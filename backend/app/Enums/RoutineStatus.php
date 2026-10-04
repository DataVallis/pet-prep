<?php

namespace App\Enums;

/**
 * Outcome of one expected routine (M2-06). Only `done` and `missed` are
 * stored (pet_daily_routines_status_check); `pending` exists only for
 * routines computed live whose deadline has not passed yet.
 */
enum RoutineStatus: string
{
    case Done = 'done';

    case Missed = 'missed';

    /** Not done yet, deadline still ahead. */
    case Pending = 'pending';
}
