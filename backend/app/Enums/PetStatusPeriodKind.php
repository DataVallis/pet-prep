<?php

namespace App\Enums;

/**
 * Why a pet could not be cared for during a period (M2-06). Mirrored by the
 * pet_status_periods_kind_check constraint.
 */
enum PetStatusPeriodKind: string
{
    /** Parent pause. */
    case HardStop = 'hard_stop';

    /** At the vet (12 h). Also counted by the Care Score (−10 each). */
    case Illness = 'illness';

    /** Session inactive — game over or switched off by an admin. */
    case Inactive = 'inactive';
}
