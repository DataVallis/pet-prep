<?php

namespace App\Enums;

/**
 * The care routines a child is scored on (M2-06, PRODUCT_SPEC §11).
 * Mirrored by the pet_daily_routines_type_check constraint.
 */
enum RoutineType: string
{
    /** One per breed feed window. */
    case Feed = 'feed';

    /** water_times_per_day refills per family-local day (pro rata on partial days). */
    case Water = 'water';

    /** One per hygiene event: clean within 2 hours outside quiet hours. */
    case Clean = 'clean';

    /** The daily step goal of a family-local day. */
    case Walk = 'walk';

    /**
     * One completed training session per family-local day (M5-R03, David
     * 2026-10-06) — a whole-day routine like the walk; only for pets with
     * training enabled.
     */
    case Training = 'training';

    /**
     * The cat's daily wand play (M5-R06-04, CAT_SPEC §5.2 / §7): done when
     * the day's successful sessions reach `play_sessions_per_day` (kitten 3,
     * grown cat 2) — a whole-day routine like the walk (`steps` = sessions,
     * `goal` = the day's goal). Only for cats with life-stage data.
     */
    case Play = 'play';

    /**
     * M5-R06-05 (cats, CAT_SPEC Q3 / §7): one per litter use — scooped
     * before its deadline (`pet_hygiene_events.due_at`: 4 h outside quiet
     * hours, 2 h while the weekly change is overdue).
     */
    case LitterScoop = 'litter_scoop';

    /**
     * M5-R06-05: the weekly full litter change — one per program week
     * (`litter_full_change_days`), on the family-local day the week ends;
     * done by a completed `changed_litter` inside the week.
     */
    case LitterChange = 'litter_change';

    /**
     * M5-R06-05 (Maine Coon, CAT_SPEC Q8): `grooming_sessions_per_week`
     * slots per program week, on the day the week ends; slot i is done by
     * the week's i-th completed grooming. Counted at the end of the week.
     */
    case Grooming = 'grooming';
}
