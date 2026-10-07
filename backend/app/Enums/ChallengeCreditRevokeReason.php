<?php

namespace App\Enums;

/**
 * Why a challenge credit ended (challenge_credits.revoke_reason, M3-11).
 * Mirrored by the challenge_credits_revoke_check constraint.
 */
enum ChallengeCreditRevokeReason: string
{
    /** The store refunded the purchase (RevenueCat CANCELLATION / REFUND of the consumable). */
    case Refund = 'refund';
}
