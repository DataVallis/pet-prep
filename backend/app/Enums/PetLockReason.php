<?php

namespace App\Enums;

/**
 * Why the child cannot act on the pet right now (HTTP 423, M1-07). Ordered
 * by priority: when several apply, the first one is reported.
 */
enum PetLockReason: string
{
    /** Virtual Shelter Intervention: the pet was taken away (PRODUCT_SPEC §7). */
    case GameOver = 'game_over';

    /** The pet session is not active (e.g. ended by an admin). */
    case Inactive = 'inactive';

    /** The parent paused the simulation ("Pogovori se s starši"). */
    case HardStopped = 'hard_stopped';

    /**
     * The 7-day trial of a `challenge` pet is over and nobody has paid
     * (M3-11, PAYMENTS_SPEC P3): the pet waits frozen like a hard stop until
     * a parent buys the challenge. The child sees a kind waiting screen.
     */
    case PaymentRequired = 'payment_required';

    /**
     * The pet is not born yet: the child must sign the responsibility
     * contract first (PRODUCT_SPEC §3, M1-07b). Only POST /api/child/contract
     * is allowed; signing births the pet.
     */
    case ContractRequired = 'contract_required';

    /** 12 h at the vet after neglect (PRODUCT_SPEC §7). */
    case Ill = 'ill';
}
