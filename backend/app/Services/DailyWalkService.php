<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Daily walk rule (David, 2026-10-03, PRODUCT_SPEC §5/§7).
 *
 * Energy is today's walk: steps of the family-local day / the day's step
 * goal (life stage, M5-R01: exercise minutes × 100 steps). 0 % after the midnight reset means "not walked yet",
 * not neglect, so energy has no hourly neglect clock. Instead, once per pet
 * at the family-local midnight the finished day is closed:
 *
 *  - one `pet_daily_walks` row (steps, goal, achieved) — idempotent;
 *  - steps and energy back to 0 for the new day;
 *  - the finished day ended with energy showing 0 % (no walk at all) → the
 *    dog falls ill at the END of that night's quiet hours (not at midnight):
 *    `pets.walk_illness_due_at`, started by EscalationService through the
 *    normal illness mechanism. Some steps but below the goal → only recorded
 *    as a missed goal.
 *
 * Never causes illness for the pet's birth day, for a day closed while the
 * pet is frozen (hard stop / illness; the caller passes $allowIllness=false),
 * or when more than one midnight passed since the last close (the finished
 * day is not "yesterday", e.g. after a long scheduler outage).
 *
 * The caller holds the pet's row lock (decay tick, step sync) and saves the
 * pet attributes; the walk row is written here.
 */
class DailyWalkService
{
    /**
     * Upper bound on quiet-window hops when looking for the end of the night
     * (bedtime directly followed by a school window, DST transitions …).
     */
    private const MAX_QUIET_HOPS = 8;

    public function __construct(private readonly LifeStageService $lifeStages) {}

    /**
     * Close the finished local day if `$now` is on a later local date than
     * the last step reset. Returns true if a day was closed.
     */
    public function closeDayIfNeeded(Pet $pet, CarbonInterface $now, bool $allowIllness): bool
    {
        // Unborn (contract not signed, M1-07b): no days to close yet; birth
        // stamps last_step_reset_at (birth-day grace).
        if ($pet->isUnborn()) {
            return false;
        }

        // Pet without a reset stamp: today is its first step day (birth-day grace).
        if ($pet->last_step_reset_at === null) {
            $pet->last_step_reset_at = $now;

            return false;
        }

        $closedDate = $pet->localDate($pet->last_step_reset_at);
        $today = $pet->localDate($now);
        if ($closedDate === $today) {
            return false;
        }

        $timezone = $pet->familyTimezone();
        $steps = (int) $pet->daily_step_count;
        // Goal of the closed day's life stage (M5-R01; pre-M5 breed goal without stage data).
        $config = $pet->breedConfig();
        $goal = $config !== null ? $this->lifeStages->rulesOn($pet, $closedDate, $config)->stepGoal : 0;
        $birthDay = $closedDate === $pet->localDate($pet->born_at);
        $yesterday = Carbon::parse($today, $timezone)->subDay()->toDateString();
        $noWalk = $pet->displayMetric('energy_level') === 0;

        $illnessAt = null;
        if ($allowIllness && ! $birthDay && $closedDate === $yesterday && $noWalk) {
            $midnight = Carbon::parse($today, $timezone)->startOfDay()->utc();
            $illnessAt = $this->endOfQuietStretch($pet->quietHours(), $midnight);
        }

        PetDailyWalk::firstOrCreate(
            ['pet_id' => $pet->id, 'local_date' => $closedDate],
            [
                'steps' => max(0, $steps),
                'goal' => max(0, $goal),
                'achieved' => $goal > 0 && $steps >= $goal,
                'birth_day' => $birthDay,
                'illness_due_at' => $illnessAt,
            ],
        );

        $pet->resetDailyStepsIfNewDay($now);

        if ($illnessAt !== null) {
            $pet->walk_illness_due_at = $illnessAt;

            Log::info('DailyWalkService: no walk yesterday, illness scheduled', [
                'pet_id' => $pet->id,
                'local_date' => $closedDate,
                'illness_due_at' => $illnessAt->toIso8601String(),
            ]);
        }

        return true;
    }

    /**
     * The first non-quiet instant at or after $from: the end of the quiet
     * stretch that contains $from, or $from itself when it isn't quiet.
     */
    public function endOfQuietStretch(?QuietHours $quietHours, CarbonInterface $from): Carbon
    {
        $at = Carbon::instance($from)->utc();
        if (! $quietHours || ! $quietHours->is_active) {
            return $at;
        }

        for ($hop = 0; $hop < self::MAX_QUIET_HOPS && $quietHours->isQuietNow($at); $hop++) {
            $next = $quietHours->nextBoundaryAfter($at);
            if ($next === null) {
                break;
            }
            $at = Carbon::instance($next)->utc();
        }

        return $at;
    }
}
