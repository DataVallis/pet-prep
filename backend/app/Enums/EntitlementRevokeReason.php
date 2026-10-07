<?php

namespace App\Enums;

/**
 * Why a family entitlement ended (family_entitlements.revoke_reason, M3-08).
 * Mirrored by the family_entitlements_revoke_check constraint.
 */
enum EntitlementRevokeReason: string
{
    case Expired = 'expired';
    case Refund = 'refund';
    case Transferred = 'transferred';
}
