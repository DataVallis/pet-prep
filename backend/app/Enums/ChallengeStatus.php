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
    /**
     * Unpaid and before `trial_ends_at` — only a pre-M3-13 7-day trial still
     * running (no new trials since David's decision 2026-10-08), or any
     * unpaid challenge while payments are not enforced (kill switch).
     */
    case Trial = 'trial';

    /**
     * Unpaid (M3-13: from creation on — unborn too, so the parent can buy
     * before the contract): a born pet waits locked (lock reason `payment_required`).
     */
    case PaymentRequired = 'payment_required';

    /** A challenge credit is assigned (or the pet is grandfathered). */
    case Paid = 'paid';
}
