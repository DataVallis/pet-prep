<?php

namespace App\Enums;

/**
 * What a RevenueCat webhook event did (purchase_events.outcome, M3-08).
 * Mirrored by the purchase_events_outcome_check constraint.
 */
enum PurchaseEventOutcome: string
{
    /** A new active entitlement row for the family. */
    case Granted = 'granted';
    /** The family's existing row was renewed / extended / re-activated. */
    case Extended = 'extended';
    /** Expiration or refund ended the family's entitlement. */
    case Revoked = 'revoked';
    /** TRANSFER moved the entitlement between families. */
    case Transferred = 'transferred';
    /** Stored for the record, nothing to change (BILLING_ISSUE, TEST, alias, …). */
    case Recorded = 'recorded';
    /** An event type we don't act on, or an event that no longer applies (out of order). */
    case Ignored = 'ignored';
    /** No parent of ours behind the app user id / aliases. */
    case UnknownUser = 'unknown_user';
    /** SANDBOX event while services.revenuecat.accept_sandbox is off. */
    case SandboxIgnored = 'sandbox_ignored';
    /** Neither entitlement_ids nor the product map name an entitlement we know. */
    case NoEntitlement = 'no_entitlement';
}
