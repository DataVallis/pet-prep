<?php

namespace App\Enums;

/**
 * What the parent chose for a pet at creation (M3-11, PAYMENTS_SPEC P4).
 * Fixed for the pet's life (join / relogin never change it). Mirrored by
 * the pets_plan_check / child_login_pins_plan_check constraints.
 */
enum PetPlan: string
{
    /** Free mutt "sandbox", forever: no 12-week program, 7-day history, basic media. */
    case Free = 'free';

    /** The 12-week challenge (7-day trial from birth, then one purchase per pet). */
    case Challenge = 'challenge';
}
