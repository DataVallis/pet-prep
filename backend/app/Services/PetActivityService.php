<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CareRefusal;
use App\Enums\PetLockReason;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetDailyStep;
use App\Models\User;
use App\Services\Media\PetMediaService;
use App\Services\Results\ActionResult;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Child actions (M1-04 steps, M1-05 cleaning, M1-07 feed / water / contract),
 * called by the child API (ChildPetController, ChildContractController).
 *
 * Every action follows the metric-write rule (backend/CLAUDE.md): transaction,
 * `lockForUpdate()` re-read, compute from the locked row, quiet write, one
 * `activities_log` row for an applied action, one `PetUpdated` broadcast
 * after commit (the activity-log observer is bypassed to avoid a duplicate).
 *
 * Family model (M2-01): every action takes the acting child. The activity
 * row records it (`actor_user_id`), the lock is evaluated for that child
 * (a caretaker without their own contract → 423 contract_required), and
 * steps are counted per child (`pet_daily_steps`); the pet's daily count is
 * the sum over its caretakers. Without an actor (system / old callers) the
 * primary caretaker (pets.user_id) is assumed.
 */
class PetActivityService
{
    /**
     * Anti-cheat (PRODUCT_SPEC §5): step increments implying more than this
     * many steps per minute since the last accepted sync are refused.
     */
    public const MAX_STEPS_PER_MINUTE = 200;

    public function __construct(
        private HygieneEventService $hygieneEvents,
        private DailyWalkService $dailyWalks,
        private PetDecayService $decay,
        private CareScheduleService $schedule,
        private LifeStageService $lifeStages,
    ) {}

    /**
     * Sync today's step count from the device (HealthKit / Health Connect).
     *
     * - Idempotent: `$stepsToday` is the cumulative count for the family-local
     *   day; only the part above the stored count is new (the max wins).
     * - Anti-cheat: the increment is capped at 200 steps per minute between
     *   the last accepted sync (or local midnight) and `$recordedAt`; the
     *   excess is refused now and can still be accepted by a later sync.
     * - Energy = min(100, steps / today's step goal × 100) — the goal of the
     *   dog's life stage (M5-R01, LifeStageService); a sync
     *   never lowers it (birth-day grace, DECISIONS 2026-10-03).
     * - A sync for a local day that is already over is ignored (stale).
     * - Activity log: one `walked_pet` row per day, when the sync first
     *   reaches the daily goal (value = steps), not one per sync — so the
     *   dashboard counts a walk once (daily walk rule).
     * - Shared pet (M2-01): `$stepsToday` is the acting child's own count;
     *   idempotency and the anti-cheat cap apply per child; the pet's count
     *   (energy, daily walk goal) is the sum of all caretakers' steps that
     *   day. Steps the pet already has that no child row explains (data from
     *   before M2-01) belong to the primary caretaker.
     */
    public function recordSteps(Pet $pet, int $stepsToday, CarbonInterface $recordedAt, ?User $actor = null): ActionResult
    {
        if ($stepsToday < 0) {
            throw new InvalidArgumentException('stepsToday must be >= 0.');
        }

        return $this->withLockedPet($pet, ActivityType::WalkedPet, function (Pet $locked) use ($stepsToday, $recordedAt, $actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $actorId = $actor?->id ?? $locked->user_id;

            // Device clocks can run ahead; never accept time from the future.
            $at = Carbon::instance($recordedAt)->utc()->startOfSecond();
            if ($at->greaterThan($now)) {
                $at = $now->copy();
            }

            // Midnight may have passed since the last tick: close the day
            // (daily walk rule) exactly as the tick would.
            $this->dailyWalks->closeDayIfNeeded($locked, $now, allowIllness: true);

            $today = $locked->localDate($now);
            if ($locked->localDate($at) !== $today) {
                return $this->unchanged(ActionResult::STALE, $locked);
            }

            $row = $this->childStepRow($locked, $actorId, $today);
            $petCount = (int) $locked->daily_step_count;
            $current = (int) $row->steps;
            $increment = $stepsToday - $current;
            if ($increment <= 0) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $dayStart = Carbon::parse($today, $locked->familyTimezone())->startOfDay()->utc();
            $reference = $row->last_sync_at !== null && $row->last_sync_at->greaterThan($dayStart)
                ? $row->last_sync_at
                : $dayStart;
            $seconds = max(0, (int) $reference->diffInSeconds($at, false));
            $allowed = intdiv($seconds * self::MAX_STEPS_PER_MINUTE, 60);
            $accepted = min($increment, $allowed);

            if ($accepted < $increment) {
                Log::warning('PetActivityService: step increment above anti-cheat limit', [
                    'pet_id' => $locked->id,
                    'actor_user_id' => $actorId,
                    'reported_increment' => $increment,
                    'accepted' => $accepted,
                    'seconds_since_reference' => $seconds,
                ]);
            }

            if ($accepted <= 0) {
                return $this->unchanged(ActionResult::REJECTED, $locked);
            }

            $breedConfig = $this->breedConfigOf($locked);
            // Today's goal of the dog's life stage (M5-R01).
            $goal = $this->lifeStages->rulesOn($locked, $today, $breedConfig)->stepGoal;

            $row->forceFill(['steps' => $current + $accepted, 'last_sync_at' => $at])->save();

            $newCount = $petCount + $accepted;
            $energy = max((float) $locked->energy_level, self::energyForSteps($newCount, $goal));

            $locked->forceFill([
                'daily_step_count' => $newCount,
                'energy_level' => $energy,
                'last_step_sync_at' => $at,
                'energy_zero_since' => null,
            ]);
            $locked->saveQuietly();

            if ($goal > 0 && $petCount < $goal && $newCount >= $goal) {
                $this->logActivity($locked, ActivityType::WalkedPet, $newCount, $actorId);
            }

            return $this->result($accepted < $increment ? ActionResult::CAPPED : ActionResult::ACCEPTED, $locked, $accepted);
        });
    }

    /**
     * Clean up after the dog (cleaning mini-game done): hygiene back to 100 %,
     * hygiene neglect clock cleared. Events that are already due but not yet
     * applied by a tick are settled here, so the pet doesn't get dirty again
     * a minute after cleaning.
     */
    public function clean(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::CleanedPoop, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $handled = $this->hygieneEvents->settleForCleaning($locked, $now, $locked->quietHours());

            if ($handled === 0 && (float) $locked->hygiene_level >= 100.0) {
                return $this->result(ActionResult::UNCHANGED, $locked);
            }

            $locked->forceFill([
                'hygiene_level' => 100.0,
                'hygiene_zero_since' => null,
            ]);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::CleanedPoop, null, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Feed the dog (PRODUCT_SPEC §5): only inside a breed feed window
     * (family-local), one feed per window, hunger → 100 %. Refused while the
     * mess isn't cleaned (hygiene shows 0 %, PRODUCT_SPEC §8).
     * Activity value = hunger shown before feeding.
     */
    public function feed(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::FedPet, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            // Decay owed since the last tick applies to the old value.
            $this->decay->catchUpLocked($locked);

            if ($locked->displayMetric('hygiene_level') <= 0) {
                return $this->refused($locked, CareRefusal::NeedsCleaning);
            }

            $feeding = $this->schedule->feeding($locked, $this->breedConfigOf($locked), $now);
            if ($feeding->currentStart === null) {
                return $this->refused($locked, CareRefusal::OutsideFeedWindow, $feeding->nextStart);
            }
            if ($feeding->fedInCurrent) {
                return $this->refused($locked, CareRefusal::AlreadyFedThisWindow, $feeding->nextStart);
            }

            $before = $locked->displayMetric('hunger_level');
            $locked->forceFill([
                'hunger_level' => 100.0,
                'hunger_zero_since' => null,
            ]);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::FedPet, $before, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Fresh water (PRODUCT_SPEC §5): at most breed water_times_per_day per
     * family-local day, at least water_min_gap_minutes since the last refill,
     * thirst → 100 %. Refused while the mess isn't cleaned.
     * Activity value = thirst shown before the refill.
     */
    public function water(Pet $pet, ?User $actor = null): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::WateredPet, function (Pet $locked) use ($actor): ActionResult {
            $now = now()->startOfSecond();

            if ($reason = $locked->actionLockReasonFor($actor)) {
                return $this->locked($locked, $reason);
            }

            $this->decay->catchUpLocked($locked);

            if ($locked->displayMetric('hygiene_level') <= 0) {
                return $this->refused($locked, CareRefusal::NeedsCleaning);
            }

            $water = $this->schedule->water($locked, $this->breedConfigOf($locked), $now);
            if ($water->limitReached) {
                return $this->refused($locked, CareRefusal::WaterDailyLimit, $water->nextAllowedAt);
            }
            if ($water->tooSoon) {
                return $this->refused($locked, CareRefusal::WaterTooSoon, $water->nextAllowedAt);
            }

            $before = $locked->displayMetric('thirst_level');
            $locked->forceFill([
                'thirst_level' => 100.0,
                'thirst_zero_since' => null,
            ]);
            $locked->pet_state = $this->decay->derivePetState($locked, $now);
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::WateredPet, $before, $actor?->id ?? $locked->user_id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Store the child's signed responsibility contract (PRODUCT_SPEC §3):
     * once per pet, server time as signed_at. A second signature is refused
     * (CareRefusal::ContractAlreadySigned → 409); the first one stays.
     * The caller validated the signature (SignContractRequest).
     *
     * Contract before birth (David 2026-10-04, M1-07b): for an unborn pet
     * the signature is the birth — in the same transaction, under the same
     * row lock, `born_at` = signed_at, metrics 100 %, every clock starts now
     * (Pet::giveBirth). The only lock it passes is `contract_required`; a
     * hard stop / inactive pet is still 423. A pet born before M1-07b
     * (grandfathered) can sign later; that only stores the contract.
     * One `PetUpdated('signed_contract')` after commit.
     *
     * Shared pet (M2-01): one contract per (pet, child). The pet is born at
     * the FIRST contract; a child who joins later signs their own (no
     * rebirth) and is locked with contract_required until then. The same
     * child signing twice → 409.
     */
    public function signContract(Pet $pet, User $child, string $format, string $signature): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::SignedContract, function (Pet $locked) use ($child, $format, $signature): ActionResult {
            $lockReason = $locked->actionLockReasonFor($child);
            if ($lockReason !== null && $lockReason !== PetLockReason::ContractRequired) {
                return $this->locked($locked, $lockReason);
            }

            if (PetContract::where('pet_id', $locked->id)->where('user_id', $child->id)->exists()) {
                return $this->refused($locked, CareRefusal::ContractAlreadySigned);
            }

            $now = now()->startOfSecond();

            PetContract::create([
                'pet_id' => $locked->id,
                'user_id' => $child->id,
                'signature_format' => $format,
                'signature' => $signature,
                'signed_at' => $now,
            ]);

            if ($locked->isUnborn()) {
                $locked->giveBirth($now);
                $locked->pet_state = $this->decay->derivePetState($locked, $now);
                $locked->saveQuietly();

                // State videos start at birth (M4-03, 2026-10-05): queued after the commit,
                // once the reference image is stored (else StorePetMedia queues them later).
                $petId = $locked->id;
                DB::afterCommit(function () use ($petId): void {
                    try {
                        $born = Pet::find($petId);

                        if ($born !== null) {
                            app(PetMediaService::class)->queueStateVideos($born);
                        }
                    } catch (\Throwable $e) {
                        // The contract is signed either way; media:backfill can catch up.
                        Log::error('signContract: state videos not queued', ['pet_id' => $petId, 'error' => $e->getMessage()]);
                        report($e);
                    }
                });
            }

            $this->logActivity($locked, ActivityType::SignedContract, null, $child->id);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Run $action on a freshly locked copy of the pet, copy the result back
     * into $pet and broadcast once after commit if something changed.
     *
     * @param  Closure(Pet): ActionResult  $action
     */
    private function withLockedPet(Pet $pet, ActivityType $activity, Closure $action): ActionResult
    {
        $work = function () use ($pet, $action): array {
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            // An illness that ended before the next tick: fresh start first,
            // so the action sees the recovered pet.
            $recovered = $locked->recoverFromIllnessIfDue(now()->startOfSecond());
            $dayBefore = $locked->last_step_reset_at?->toIso8601String();
            $shownBefore = $this->shownState($locked);

            $result = $action($locked);
            if ($recovered && $locked->isDirty()) {
                $locked->saveQuietly();
            }

            // The action closed the previous local day (steps / energy → 0,
            // maybe a walk illness planned) or caught up decay that changed
            // what the child sees, even if the action itself was refused.
            $dayClosed = $dayBefore !== $locked->last_step_reset_at?->toIso8601String();
            $shownChanged = $shownBefore !== $this->shownState($locked);

            return [$locked, $result, $recovered || $dayClosed || $shownChanged];
        };

        /** @var array{0: Pet, 1: ActionResult, 2: bool} $outcome */
        $outcome = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);
        [$locked, $result, $bookkeeping] = $outcome;

        $pet->setRawAttributes($locked->getAttributes(), true);

        // One broadcast after commit: the action's own event, or a plain
        // metric_changed for a recovery / midnight reset / decay catch-up
        // the action applied.
        if ($result->changed()) {
            PetUpdated::afterCommit($locked, $activity->value);
        } elseif ($bookkeeping) {
            PetUpdated::afterCommit($locked, 'metric_changed');
        }

        return $result;
    }

    /**
     * Persist bookkeeping (e.g. a midnight reset, illness recovery) without
     * counting as an action.
     */
    private function unchanged(string $status, Pet $locked): ActionResult
    {
        if ($locked->isDirty()) {
            $locked->saveQuietly();
        }

        return $this->result($status, $locked);
    }

    /**
     * Hard stop / illness / inactive / game over / contract required:
     * nothing is changed (a due recovery was already applied by withLockedPet).
     */
    private function locked(Pet $locked, PetLockReason $reason): ActionResult
    {
        return $this->result(ActionResult::LOCKED, $locked, lockReason: $reason);
    }

    /**
     * Today's step row of one child on this pet (caller holds the pet lock).
     * Steps the pet already counts that no child row explains (recorded
     * before per-child steps existed) are given to the primary caretaker.
     */
    private function childStepRow(Pet $locked, ?int $actorId, string $today): PetDailyStep
    {
        $rows = PetDailyStep::where('pet_id', $locked->id)->where('local_date', $today)->get();
        $untracked = (int) $locked->daily_step_count - (int) $rows->sum('steps');

        if ($untracked > 0 && $locked->user_id !== null) {
            $primary = $rows->firstWhere('user_id', $locked->user_id)
                ?? new PetDailyStep(['pet_id' => $locked->id, 'user_id' => $locked->user_id, 'local_date' => $today, 'steps' => 0]);
            $primary->forceFill([
                'steps' => (int) $primary->steps + $untracked,
                'last_sync_at' => $primary->last_sync_at ?? $locked->last_step_sync_at,
            ])->save();
            $rows = $rows->reject(fn (PetDailyStep $r) => (int) $r->user_id === (int) $locked->user_id)->push($primary);
        }

        return $rows->firstWhere('user_id', $actorId)
            ?? new PetDailyStep(['pet_id' => $locked->id, 'user_id' => $actorId, 'local_date' => $today, 'steps' => 0]);
    }

    /**
     * A game rule refuses the action; bookkeeping done so far (decay
     * catch-up, midnight) is still saved.
     */
    private function refused(Pet $locked, CareRefusal $refusal, ?CarbonInterface $nextAllowedAt = null): ActionResult
    {
        if ($locked->isDirty()) {
            $locked->saveQuietly();
        }

        return $this->result(ActionResult::REFUSED, $locked, refusal: $refusal, nextAllowedAt: $nextAllowedAt);
    }

    private function result(
        string $status,
        Pet $pet,
        int $acceptedSteps = 0,
        ?PetLockReason $lockReason = null,
        ?CareRefusal $refusal = null,
        ?CarbonInterface $nextAllowedAt = null,
    ): ActionResult {
        return new ActionResult(
            status: $status,
            acceptedSteps: $acceptedSteps,
            dailyStepCount: (int) $pet->daily_step_count,
            energyLevel: $pet->displayMetric('energy_level'),
            hygieneLevel: $pet->displayMetric('hygiene_level'),
            lockReason: $lockReason,
            refusal: $refusal,
            nextAllowedAt: $nextAllowedAt,
        );
    }

    /**
     * What the child / parent sees of the pet (displayed metrics and state);
     * decides whether bookkeeping inside an action needs a broadcast.
     *
     * @return array<string, mixed>
     */
    private function shownState(Pet $pet): array
    {
        return array_merge($pet->displayMetrics(), [
            'pet_state' => $pet->pet_state?->value,
            'escalation_level' => (int) $pet->escalation_level,
            'is_ill' => $pet->isIll(),
        ]);
    }

    /**
     * Energy (0–100, precise) for a number of steps and a goal:
     * min(100, steps / goal × 100); a goal of 0 means "no walk needed".
     */
    public static function energyForSteps(int $steps, int $goal): float
    {
        if ($goal <= 0) {
            return 100.0;
        }

        return min(100.0, max(0, $steps) / $goal * 100);
    }

    private function breedConfigOf(Pet $pet): BreedConfig
    {
        return $pet->breedConfig()
            ?? throw new RuntimeException("No breed config for pet {$pet->id}.");
    }

    /**
     * One activities_log row per applied action. Created without model events:
     * the action broadcasts once itself after commit.
     */
    private function logActivity(Pet $pet, ActivityType $type, ?int $value, ?int $actorId): void
    {
        ActivityLog::withoutEvents(fn () => ActivityLog::create([
            'pet_id' => $pet->id,
            'actor_user_id' => $actorId,
            'activity_type' => $type->value,
            'value' => $value,
        ]));
    }
}
