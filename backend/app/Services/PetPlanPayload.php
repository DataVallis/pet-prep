<?php

namespace App\Services;

use App\Models\Pet;
use Carbon\CarbonInterface;

/**
 * `plan` object of a pet in the child state, the parent dashboard and the
 * PetUpdated broadcast (M3-11, additive):
 * `{type: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null,
 *   trial_ends_at: string|null, paid_at: string|null, payments_enforced: bool}`.
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
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $status,
        public readonly ?string $trialEndsAt,
        public readonly ?string $paidAt,
        public readonly bool $paymentsEnforced = true,
    ) {}

    public static function for(Pet $pet, ?string $timezone = null, ?CarbonInterface $now = null): self
    {
        $iso = fn (?CarbonInterface $at): ?string => $at === null ? null
            : ($timezone !== null ? $at->copy()->setTimezone($timezone) : $at)->toIso8601String();

        /** @var 'free'|'challenge' $type */
        $type = $pet->plan->value;
        /** @var 'trial'|'payment_required'|'paid'|null $status */
        $status = $pet->challengeStatus($now)?->value;

        return new self($type, $status, $iso($pet->trial_ends_at), $iso($pet->challenge_paid_at), Pet::paymentsEnforced());
    }

    /**
     * @return array{type: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null, trial_ends_at: string|null, paid_at: string|null, payments_enforced: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'status' => $this->status,
            'trial_ends_at' => $this->trialEndsAt,
            'paid_at' => $this->paidAt,
            'payments_enforced' => $this->paymentsEnforced,
        ];
    }
}
