<?php

namespace App\Enums;

/**
 * What a RevenueCat webhook event did (purchase_events.outcome, M3-08 /
 * M3-11). Mirrored by the purchase_events_outcome_check constraint.
 */
enum PurchaseEventOutcome: string
{
    /** A new challenge credit for the family (maybe auto-assigned to its only unpaid pet). */
    case Granted = 'granted';
    /** A refund revoked the purchase's challenge credit. */
    case Revoked = 'revoked';
    /** TRANSFER moved unassigned credits to another family. */
    case Transferred = 'transferred';
    /** Stored for the record, nothing to change (BILLING_ISSUE, TEST, alias, assigned credits in a TRANSFER, …). */
    case Recorded = 'recorded';
    /** An event type we don't act on, or an event that no longer applies. */
    case Ignored = 'ignored';
    /** No parent of ours behind the app user id / aliases. */
    case UnknownUser = 'unknown_user';
    /** SANDBOX event while services.revenuecat.accept_sandbox is off. */
    case SandboxIgnored = 'sandbox_ignored';
    /** The product is not the challenge consumable (services.revenuecat.challenge_products). */
    case UnknownProduct = 'unknown_product';
}
