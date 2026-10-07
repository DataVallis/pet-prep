<?php

namespace App\Enums;

/**
 * Why a challenge pet counts as paid (pets.challenge_paid_source, M3-11).
 * Mirrored by the pets_challenge_paid_check constraint.
 */
enum ChallengePaidSource: string
{
    /** A challenge credit (store purchase) is assigned to the pet. */
    case Purchase = 'purchase';

    /** Created before payments existed (testers) — never locked (PAYMENTS_SPEC §2). */
    case Grandfathered = 'grandfathered';

    /** Unlocked by a superadmin in Filament (support, refunds outside the store, testers). */
    case Admin = 'admin';
}
