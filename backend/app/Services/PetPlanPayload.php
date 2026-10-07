<?php

namespace App\Services;

use App\Enums\ChallengePaidSource;
use App\Enums\PetPlan;
use App\Models\Pet;
use Carbon\CarbonInterface;

/**
 * `plan` object of a pet in the child state, the parent dashboard and the
 * PetUpdated broadcast (M3-11, additive):
 * `{type: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null,
 *   trial_ends_at: string|null, paid_at: string|null, payments_enforced: bool,
 *   display_type: 'free'|'challenge'}`.
 * `display_type` (M5-F02, David 2026-10-07: a mutt is never shown as "Paid")
 * is the plan a parent sees: `free` for a free pet AND for a pet without a
 * premium breed (the mutt) whose challenge nobody bought (`grandfathered` by
 * the M3-11 backfill, or an `admin` unlock); otherwise = `type`. Display
 * only — `type` / `status` stay the pet's real plan, so the program clock,
 * history, certificate and locks of existing pets are unchanged.
 * `payments_enforced` false (kill switch) = no lock after the trial; the app shows no
 * trial countdown or payment banner.
 * `status` is null for a free pet; `trial_ends_at` is null before birth and
 * for a free pet. Instants ISO 8601 (in `$timezone` when given).
 */
final class PetPlanPayload
{
    /**
     * @param  'free'|'challenge'  $type
     * @param  'trial'|'payment_required'|'paid'|null  $status
     * @param  'free'|'challenge'|null  $displayType  null = same as $type
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $status,
        public readonly ?string $trialEndsAt,
        public readonly ?string $paidAt,
        public readonly bool $paymentsEnforced = true,
        public readonly ?string $displayType = null,
    ) {}

    /**
     * M5-F02: the pet is shown to a parent as free — a free-plan pet, or a
     * pet of a breed without premium (the mutt) whose challenge is paid but
     * not by a purchase (grandfathered / admin). A purchased challenge stays
     * a challenge (somebody paid); an unpaid one keeps its trial status.
     */
    public static function displaysAsFree(Pet $pet): bool
    {
        if ($pet->plan === PetPlan::Free) {
            return true;
        }

        return ! $pet->breed_type->isPremium()
            && $pet->challenge_paid_at !== null
            && $pet->challenge_paid_source !== ChallengePaidSource::Purchase;
    }

    public static function for(Pet $pet, ?string $timezone = null, ?CarbonInterface $now = null): self
    {
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null
            : ($timezone !== null ? $at->copy()->setTimezone($timezone) : $at)->toIso8601String();

        /** @var 'free'|'challenge' $type */
        $type = $pet->plan->value;
        /** @var 'trial'|'payment_required'|'paid'|null $status */
        $status = $pet->challengeStatus($now)?->value;

        return new self($type, $status, $iso($pet->trial_ends_at), $iso($pet->challenge_paid_at), Pet::paymentsEnforced(),
            self::displaysAsFree($pet) ? PetPlan::Free->value : $type);
    }

    /**
     * @return array{type: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null, trial_ends_at: string|null, paid_at: string|null, payments_enforced: bool, display_type: 'free'|'challenge'}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'trial_ends_at' => $this->trialEndsAt,
            'paid_at' => $this->paidAt,
            'payments_enforced' => $this->paymentsEnforced,
            'display_type' => $this->displayType ?? $this->type,
        ];
    }
}
