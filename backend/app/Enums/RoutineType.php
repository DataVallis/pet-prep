<?php

namespace App\Enums;

/**
 * The four care routines a child is scored on (M2-06, PRODUCT_SPEC §11).
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
}
