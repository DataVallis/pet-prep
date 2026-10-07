<?php

namespace App\Enums;

use App\Services\ChallengeService;

/**
 * Payment status of a `challenge` pet (M3-11, PAYMENTS_SPEC §2). Derived,
 * never stored — {@see ChallengeService::statusOf()}.
 * A `free` pet has no status (null).
 */
enum ChallengeStatus: string
{
    /** Unpaid and before `trial_ends_at` (also before birth, when the trial has not started). */
    case Trial = 'trial';

    /** Unpaid and the trial is over: the pet waits locked (lock reason `payment_required`). */
    case PaymentRequired = 'payment_required';

    /** A challenge credit is assigned (or the pet is grandfathered). */
    case Paid = 'paid';
}
