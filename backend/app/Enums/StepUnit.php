<?php

namespace App\Enums;

/**
 * What a child's phone counted for the daily walk (M5-T12). Mirrored by the
 * pet_daily_steps_unit_check constraint.
 *
 * Pushes are converted 1 : 1 into steps on the phone (APP_REFINEMENT_SPEC §6);
 * the unit only changes the wording the parent sees — never a goal or a score.
 */
enum StepUnit: string
{
    /** Steps (Apple Health / Health Connect / motion sensor). */
    case Steps = 'steps';

    /** Wheelchair pushes (HealthKit push count / Health Connect WheelchairPushesRecord). */
    case Pushes = 'pushes';
}
