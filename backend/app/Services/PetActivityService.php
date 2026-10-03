<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Services\Results\ActionResult;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Child actions that change pet metrics (M1-04 steps, M1-05 cleaning).
 * The child API (M1-07) calls these; there is no HTTP endpoint yet.
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

    public function __construct(private HygieneEventService $hygieneEvents) {}

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
     */
    public function recordSteps(Pet $pet, int $stepsToday, CarbonInterface $recordedAt): ActionResult
    {
        if ($stepsToday < 0) {
            throw new InvalidArgumentException('stepsToday must be >= 0.');
        }

        return $this->withLockedPet($pet, ActivityType::WalkedPet, function (Pet $locked) use ($stepsToday, $recordedAt): ActionResult {
            $now = now()->startOfSecond();

            if ($locked->isActionLocked()) {
                return $this->result(ActionResult::LOCKED, $locked);
            }

            // Device clocks can run ahead; never accept time from the future.
            $at = Carbon::instance($recordedAt)->utc()->startOfSecond();
            if ($at->greaterThan($now)) {
                $at = $now->copy();
            }

            // Midnight may have passed since the last tick.
            $locked->resetDailyStepsIfNewDay($now);

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

            $breedConfig = $locked->breedConfig()
                ?? throw new RuntimeException("No breed config for pet {$locked->id}.");

            $newCount = $current + $accepted;
            $energy = max((float) $locked->energy_level, $breedConfig->energyForSteps($newCount));

            $locked->forceFill([
                'daily_step_count' => $newCount,
                'energy_level' => $energy,
                'last_step_sync_at' => $at,
            ]);
            if (Pet::displayValue($energy) > 0) {
                $locked->energy_zero_since = null;
            }
            $locked->saveQuietly();

            $this->logActivity($locked, ActivityType::WalkedPet, $accepted);

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
                return $this->result(ActionResult::LOCKED, $locked);
            }

            $handled = $this->hygieneEvents->settleForCleaning($locked, $now, $locked->quietHours());

            if ($handled === 0 && (float) $locked->hygiene_level >= 100.0) {
                return $this->result(ActionResult::UNCHANGED, $locked);
            }

            $locked->forceFill([
                'hygiene_level' => 100.0,
                'hygiene_zero_since' => null,
            ])->saveQuietly();

            $this->logActivity($locked, ActivityType::CleanedPoop, null);

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

            return [$locked, $action($locked)];
        };

        /** @var array{0: Pet, 1: ActionResult} $outcome */
        $outcome = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);
        [$locked, $result] = $outcome;

        $pet->setRawAttributes($locked->getAttributes(), true);

        if ($result->changed()) {
            DB::afterCommit(fn () => broadcast(new PetUpdated($locked, $activity->value)));
        }

        return $result;
    }

    /**
     * Persist bookkeeping (e.g. a midnight reset) without counting as an action.
     */
    private function unchanged(string $status, Pet $locked): ActionResult
    {
        if ($locked->isDirty()) {
            $locked->saveQuietly();
        }

        return $this->result($status, $locked);
    }

    private function result(string $status, Pet $pet, int $acceptedSteps = 0): ActionResult
    {
        return new ActionResult(
            status: $status,
            acceptedSteps: $acceptedSteps,
            dailyStepCount: (int) $pet->daily_step_count,
            energyLevel: $pet->displayMetric('energy_level'),
            hygieneLevel: $pet->displayMetric('hygiene_level'),
        );
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
