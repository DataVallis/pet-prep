<?php

namespace App\Services;

use App\Enums\ChallengeCreditRevokeReason;
use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Exceptions\ChallengeException;
use App\Models\ChallengeCredit;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetCaretaker;
use App\Models\PurchaseEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Challenge credits (M3-11, PAYMENTS_SPEC P1): one per store purchase of
 * the consumable `petprep_challenge_12w`, owned by the buyer's family,
 * assigned to exactly one pet.
 *
 * Lock order: family row → pet row → credit rows (same in the webhook and in
 * activate()). Writes that touch a pet go through ChallengeService.
 *
 *  - create() (webhook): new credit; auto-assigned when the family has
 *    exactly one active unpaid challenge pet (status trial or
 *    payment_required, unborn pets count as trial).
 *  - activate() (POST /api/parent/pets/{pet}/challenge/activate): the oldest
 *    available credit → the pet. Idempotent: a pet already paid by a
 *    purchase answers `already_active` without using another credit.
 *  - revokeForRefund() (webhook): the purchase's credit is revoked; its pet
 *    goes back to trial / payment_required (ChallengeService::markRefunded).
 *  - transferUnassigned() (webhook TRANSFER): only unassigned credits move.
 */
class ChallengeCreditService
{
    public function __construct(
        private readonly ChallengeService $challenges,
    ) {}

    /**
     * @param  array{product_id: string, store: string|null, environment: string|null, transaction_id: string|null}  $attrs
     */
    public function create(Family $family, PurchaseEvent $event, array $attrs, CarbonInterface $purchasedAt): ChallengeCredit
    {
        $credit = ChallengeCredit::create([
            'family_id' => $family->id,
            'purchase_event_id' => $event->id,
            'product_id' => $attrs['product_id'],
            'store' => $attrs['store'],
            'environment' => $attrs['environment'],
            'transaction_id' => $attrs['transaction_id'],
            'purchased_at' => $purchasedAt,
        ]);

        $unpaid = $this->unpaidPets($family);
        if ($unpaid->count() === 1) {
            $pet = Pet::whereKey($unpaid->first()->id)->lockForUpdate()->first();
            if ($pet !== null && $pet->challenge_paid_at === null) {
                $this->assign($credit, $pet, ChallengeCredit::VIA_WEBHOOK);
            }
        }

        return $credit;
    }

    /**
     * Assign the family's oldest available credit to $pet (parent action).
     *
     * @return array{status: 'activated'|'already_active', pet: Pet, credit: ChallengeCredit|null}
     *
     * @throws ChallengeException free_plan / already_paid / pet_not_active (422), no_credit (409)
     */
    /**
     * Superadmin unlock in Filament (QA PR #67 B1c): the challenge counts as paid
     * without a store purchase (`challenge_paid_source = admin`) — support cases,
     * refunds outside the store, testers. No credit is used; a payment lock is
     * lifted like after a purchase. False when it was not a challenge pet in play
     * or was already paid. Lock order family → pet (as `activate`).
     */
    public function grantByAdmin(Pet $pet): bool
    {
        return DB::transaction(function () use ($pet): bool {
            Family::whereKey($pet->family_id)->lockForUpdate()->first();
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();
            if ($locked->plan !== PetPlan::Challenge || $locked->challenge_paid_at !== null
                || ! $locked->is_active || $locked->is_game_over) {
                return false;
            }

            return $this->challenges->markPaid($locked, ChallengePaidSource::Admin, now()->startOfSecond());
        });
    }

    public function activate(Pet $pet): array
    {
        return DB::transaction(function () use ($pet): array {
            Family::whereKey($pet->family_id)->lockForUpdate()->first();
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            if ($locked->plan !== PetPlan::Challenge) {
                throw new ChallengeException('free_plan', 'This dog is on the free plan.');
            }
            if ($locked->challenge_paid_at !== null) {
                if ($locked->challenge_paid_source === ChallengePaidSource::Purchase) {
                    return [
                        'status' => 'already_active',
                        'pet' => $locked,
                        'credit' => ChallengeCredit::where('pet_id', $locked->id)->unrevoked()->first(),
                    ];
                }

                throw new ChallengeException('already_paid', 'This dog\'s challenge is already unlocked.');
            }
            if (! $locked->is_active || $locked->is_game_over) {
                throw new ChallengeException('pet_not_active', 'This dog is no longer in play.');
            }

            $credit = ChallengeCredit::where('family_id', $locked->family_id)
                ->available()
                ->orderBy('purchased_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($credit === null) {
                throw new ChallengeException('no_credit', 'No unused challenge purchase in this family.', 409);
            }

            $this->assign($credit, $locked, ChallengeCredit::VIA_PARENT);

            return ['status' => 'activated', 'pet' => $locked, 'credit' => $credit];
        });
    }

    /**
     * Revoke the credit of a refunded purchase (caller: webhook, family row
     * locked). Match: the purchase's transaction id; without one, the
     * family's newest unrevoked credit of the product, unassigned first.
     */
    public function revokeForRefund(Family $family, ?string $productId, ?string $transactionId, string $eventId, CarbonInterface $now): ?ChallengeCredit
    {
        $query = ChallengeCredit::where('family_id', $family->id)->unrevoked();
        if ($transactionId !== null) {
            $query->where('transaction_id', $transactionId);
        } else {
            $query->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
                ->orderByRaw('assigned_at IS NOT NULL')
                ->orderByDesc('purchased_at')
                ->orderByDesc('id');
        }
        $credit = $query->lockForUpdate()->first();
        if ($credit === null) {
            return null;
        }

        $pet = $credit->pet_id !== null ? Pet::whereKey($credit->pet_id)->lockForUpdate()->first() : null;

        $credit->forceFill([
            'revoked_at' => $now,
            'revoke_reason' => ChallengeCreditRevokeReason::Refund,
            'revoke_event_id' => $eventId,
        ])->save();

        if ($pet !== null) {
            $this->challenges->markRefunded($pet, $now);
        }

        return $credit;
    }

    /**
     * TRANSFER: move every available credit of $from to $to (both family rows
     * locked by the caller). Assigned credits stay with their pet.
     *
     * @return int number of credits moved
     */
    public function transferUnassigned(Family $from, Family $to, CarbonInterface $now): int
    {
        return ChallengeCredit::where('family_id', $from->id)->available()->update([
            'family_id' => $to->id,
            'transferred_from_family_id' => $from->id,
            'transferred_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Read model for GET /api/parent/billing: available credits + the active
     * pets of the family with plan and payment status.
     *
     * @return array{credits_available: int, pets: list<array{pet_id: int, plan: string, status: string|null, trial_ends_at: string|null, paid_at: string|null, trial_available: bool|null, deletion_loses_purchase: bool}>}
     */
    public function billing(?Family $family): array
    {
        if ($family === null) {
            return ['credits_available' => 0, 'payments_enforced' => Pet::paymentsEnforced(), 'pets' => []];
        }

        $tz = $family->timezone;
        $pets = Pet::where('family_id', $family->id)->where('is_active', true)->orderBy('id')->get();

        return [
            'credits_available' => ChallengeCredit::where('family_id', $family->id)->available()->count(),
            // Kill switch (config/payments.php): false = nobody is locked after the trial yet.
            'payments_enforced' => Pet::paymentsEnforced(),
            'pets' => $pets->map(fn (Pet $pet): array => [
                'pet_id' => $pet->id,
                'plan' => $pet->plan->value,
                'status' => $pet->challengeStatus()?->value,
                'trial_ends_at' => $pet->trial_ends_at?->copy()->setTimezone($tz)->toIso8601String(),
                'paid_at' => $pet->challenge_paid_at?->copy()->setTimezone($tz)->toIso8601String(),
                // P7: the pet has / gets the 7-day free trial (null for a free pet).
                'trial_available' => $this->trialAvailable($pet),
                // P5: deleting the pet (or its only child) throws the purchase away.
                'deletion_loses_purchase' => $pet->deletionLosesPurchase(),
            ])->values()->all(),
        ];
    }

    /**
     * P7: born → whether its trial ran (trial_ends_at after birth); unborn →
     * whether its first caretaker still has the one free trial per child.
     */
    private function trialAvailable(Pet $pet): ?bool
    {
        if ($pet->plan !== PetPlan::Challenge) {
            return null;
        }
        if ($pet->born_at !== null) {
            return ! ChallengeService::startedWithoutTrial($pet);
        }

        $childId = PetCaretaker::where('pet_id', $pet->id)->orderBy('id')->value('user_id');
        $child = $childId !== null ? User::find($childId) : null;

        return $child === null || ! $this->challenges->childHadTrial($child, $pet->id);
    }

    /**
     * Active challenge pets of the family that are not paid (trial — also
     * unborn — or payment_required).
     *
     * @return Collection<int, Pet>
     */
    private function unpaidPets(Family $family): Collection
    {
        return Pet::where('family_id', $family->id)
            ->where('plan', PetPlan::Challenge->value)
            ->whereNull('challenge_paid_at')
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->orderBy('id')
            ->get()
            ->filter(fn (Pet $p) => in_array($p->challengeStatus(), [ChallengeStatus::Trial, ChallengeStatus::PaymentRequired], true))
            ->values();
    }

    private function assign(ChallengeCredit $credit, Pet $pet, string $via): void
    {
        $now = now()->startOfSecond();
        $credit->forceFill(['pet_id' => $pet->id, 'assigned_at' => $now, 'assigned_via' => $via])->save();
        $this->challenges->markPaid($pet, ChallengePaidSource::Purchase, $now);
    }
}
