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
use App\Models\User;
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
    ) {}

    /**
     * Sync today's step count from the device (HealthKit / Health Connect).
     *
     * - Idempotent: `$stepsToday` is the cumulative count for the family-local
     *   day; only the part above the stored count is new (the max wins).
     * - Anti-cheat: the increment is capped at 200 steps per minute between
     *   the last accepted sync (or local midnight) and `$recordedAt`; the
     *   excess is refused now and can still be accepted by a later sync.
     * - Energy = min(100, steps / breed daily_steps_required × 100); a sync
     *   never lowers it (birth-day grace, DECISIONS 2026-10-03).
     * - A sync for a local day that is already over is ignored (stale).
     * - Activity log: one `walked_pet` row per day, when the sync first
     *   reaches the daily goal (value = steps), not one per sync — so the
     *   dashboard counts a walk once (daily walk rule).
     */
    public function recordSteps(Pet $pet, int $stepsToday, CarbonInterface $recordedAt): ActionResult
    {
        if ($stepsToday < 0) {
            throw new InvalidArgumentException('stepsToday must be >= 0.');
        }

        return $this->withLockedPet($pet, ActivityType::WalkedPet, function (Pet $locked) use ($stepsToday, $recordedAt): ActionResult {
            $now = now()->startOfSecond();

            if ($locked->isActionLocked()) {
                return $this->locked($locked);
            }

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

            $current = (int) $locked->daily_step_count;
            $increment = $stepsToday - $current;
            if ($increment <= 0) {
                return $this->unchanged(ActionResult::UNCHANGED, $locked);
            }

            $dayStart = Carbon::parse($today, $locked->familyTimezone())->startOfDay()->utc();
            $reference = $locked->last_step_sync_at !== null && $locked->last_step_sync_at->greaterThan($dayStart)
                ? $locked->last_step_sync_at
                : $dayStart;
            $seconds = max(0, (int) $reference->diffInSeconds($at, false));
            $allowed = intdiv($seconds * self::MAX_STEPS_PER_MINUTE, 60);
            $accepted = min($increment, $allowed);

            if ($accepted < $increment) {
                Log::warning('PetActivityService: step increment above anti-cheat limit', [
                    'pet_id' => $locked->id,
                    'reported_increment' => $increment,
                    'accepted' => $accepted,
                    'seconds_since_reference' => $seconds,
                ]);
            }

            if ($accepted <= 0) {
                return $this->unchanged(ActionResult::REJECTED, $locked);
            }

            $breedConfig = $this->breedConfigOf($locked);

            $newCount = $current + $accepted;
            $energy = max((float) $locked->energy_level, $breedConfig->energyForSteps($newCount));

            $locked->forceFill([
                'daily_step_count' => $newCount,
                'energy_level' => $energy,
                'last_step_sync_at' => $at,
                'energy_zero_since' => null,
            ]);
            $locked->saveQuietly();

            $goal = (int) $breedConfig->daily_steps_required;
            if ($goal > 0 && $current < $goal && $newCount >= $goal) {
                $this->logActivity($locked, ActivityType::WalkedPet, $newCount);
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
    public function clean(Pet $pet): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::CleanedPoop, function (Pet $locked): ActionResult {
            $now = now()->startOfSecond();

            if ($locked->isActionLocked()) {
                return $this->locked($locked);
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

            $this->logActivity($locked, ActivityType::CleanedPoop, null);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Feed the dog (PRODUCT_SPEC §5): only inside a breed feed window
     * (family-local), one feed per window, hunger → 100 %. Refused while the
     * mess isn't cleaned (hygiene shows 0 %, PRODUCT_SPEC §8).
     * Activity value = hunger shown before feeding.
     */
    public function feed(Pet $pet): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::FedPet, function (Pet $locked): ActionResult {
            $now = now()->startOfSecond();

            if ($locked->isActionLocked()) {
                return $this->locked($locked);
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

            $this->logActivity($locked, ActivityType::FedPet, $before);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Fresh water (PRODUCT_SPEC §5): at most breed water_times_per_day per
     * family-local day, at least water_min_gap_minutes since the last refill,
     * thirst → 100 %. Refused while the mess isn't cleaned.
     * Activity value = thirst shown before the refill.
     */
    public function water(Pet $pet): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::WateredPet, function (Pet $locked): ActionResult {
            $now = now()->startOfSecond();

            if ($locked->isActionLocked()) {
                return $this->locked($locked);
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

            $this->logActivity($locked, ActivityType::WateredPet, $before);

            return $this->result(ActionResult::ACCEPTED, $locked);
        });
    }

    /**
     * Store the child's signed responsibility contract (PRODUCT_SPEC §3):
     * once per pet, server time as signed_at. A second signature is refused
     * (CareRefusal::ContractAlreadySigned → 409); the first one stays.
     * The caller validated the signature (SignContractRequest).
     */
    public function signContract(Pet $pet, User $child, string $format, string $signature): ActionResult
    {
        return $this->withLockedPet($pet, ActivityType::SignedContract, function (Pet $locked) use ($child, $format, $signature): ActionResult {
            if ($locked->isActionLocked()) {
                return $this->locked($locked);
            }

            if (PetContract::where('pet_id', $locked->id)->exists()) {
                return $this->refused($locked, CareRefusal::ContractAlreadySigned);
            }

            PetContract::create([
                'pet_id' => $locked->id,
                'user_id' => $child->id,
                'signature_format' => $format,
                'signature' => $signature,
                'signed_at' => now()->startOfSecond(),
            ]);

            $this->logActivity($locked, ActivityType::SignedContract, null);

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
            DB::afterCommit(fn () => broadcast(new PetUpdated($locked, $activity->value)));
        } elseif ($bookkeeping) {
            DB::afterCommit(fn () => broadcast(new PetUpdated($locked, 'metric_changed')));
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
     * Hard stop / illness / inactive / game over: nothing is changed (a due
     * recovery was already applied by withLockedPet).
     */
    private function locked(Pet $locked): ActionResult
    {
        return $this->result(ActionResult::LOCKED, $locked, lockReason: $locked->actionLockReason());
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

    private function breedConfigOf(Pet $pet): BreedConfig
    {
        return $pet->breedConfig()
            ?? throw new RuntimeException("No breed config for pet {$pet->id}.");
    }

    /**
     * One activities_log row per applied action. Created without model events:
     * the action broadcasts once itself after commit.
     */
    private function logActivity(Pet $pet, ActivityType $type, ?int $value): void
    {
        ActivityLog::withoutEvents(fn () => ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => $type->value,
            'value' => $value,
        ]));
    }
}
