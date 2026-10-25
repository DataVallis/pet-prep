<?php

namespace App\Services;

use App\Models\Pet;
use App\Models\QuietHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When the pet sleeps (M5-T02, APP_REFINEMENT_SPEC §8, David 2026-10-20) —
 * the ONE definition every rule and the apps use.
 *
 * The pet sleeps while the family's quiet hours are on (night and the
 * optional school window, read on the family wall clock — QuietHours), the
 * same moments PetDecayService shows `pet_state = sleeping` (a mess wins
 * there with `sick`, but the pet still sleeps). It wakes at the end of the
 * quiet stretch (adjoining school + night windows are hopped over; DST-safe
 * through QuietHours::nextBoundaryAfter).
 *
 * While it sleeps: no ball game and no cuddle (PlayService → 422
 * `play_pet_sleeping`, M5-T02b), no training session (TrainingService →
 * 422 `training_pet_sleeping`), no brushing for a dog or a cat
 * (CatChoreService → 422 `grooming_quiet_hours`, checked first, M5-T02b —
 * David 2026-10-21 »spečo žival ne motimo«), no breed care task — teeth, folds,
 * ears, nails (CareTaskService → 422 `care_task_quiet_hours`, checked first,
 * David 2026-10-24). The cat's wand and the puppy's
 * socialisation keep their own quiet-hours refusals (they, like grooming,
 * also refuse a game that would run INTO quiet hours).
 */
class PetSleepService
{
    /** Upper bound on quiet-window hops (school directly followed by the night, DST …). */
    private const MAX_HOPS = 8;

    /** The pet sleeps at $now. */
    public function asleepAt(Pet $pet, CarbonInterface $now, ?QuietHours $quiet = null): bool
    {
        return ($quiet ?? $pet->quietHours())->isQuietNow($now);
    }

    /**
     * When a sleeping pet wakes (UTC): the end of the quiet stretch $now lies
     * in. Null while it is awake.
     */
    public function wakesAt(Pet $pet, CarbonInterface $now, ?QuietHours $quiet = null): ?CarbonImmutable
    {
        $quiet ??= $pet->quietHours();
        $at = CarbonImmutable::instance($now)->utc();
        if (! $quiet->isQuietNow($at)) {
            return null;
        }

        for ($hop = 0; $hop < self::MAX_HOPS && $quiet->isQuietNow($at); $hop++) {
            $next = $quiet->nextBoundaryAfter($at);
            if ($next === null) {
                break;
            }
            $at = CarbonImmutable::instance($next)->utc();
        }

        return $at;
    }

    /**
     * The time the child is shown for a care moment (display only, David
     * 2026-10-25 — the child state's `water.shown_at`): a moment the pet
     * sleeps through (quiet hours, night or school window) is shown as its
     * wake time, so a bowl due »ob 23:58« reads »jutri 7:00«. A moment while
     * the pet is awake, or null, is returned as it is. The rule that decides
     * when the action is allowed is never changed by this.
     */
    public function shownAt(Pet $pet, ?CarbonInterface $at, ?QuietHours $quiet = null): ?CarbonImmutable
    {
        if ($at === null) {
            return null;
        }

        return $this->wakesAt($pet, $at, $quiet) ?? CarbonImmutable::instance($at)->utc();
    }
}
