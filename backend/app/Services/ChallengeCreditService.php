<?php

namespace App\Services;

use App\Enums\ChallengeCreditRevokeReason;
use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Exceptions\ChallengeException;
use App\Models\ChallengeCredit;
use App\Models\ChallengePurchaseIntent;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PurchaseEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Challenge credits (M3-11, PAYMENTS_SPEC P1): one per store purchase of
 * the consumable `petprep_challenge_12w`, owned by the buyer's family,
 * assigned to exactly one pet.
 *
 * Lock order: family row → pet row → credit rows (same in the webhook and in
 * activate()). Writes that touch a pet go through ChallengeService.
 *
 *  - create() (webhook): new credit. M6-U01 (§5.3, David »da« 2026-11-10):
 *    with a valid purchase intent of the buying parent (new apps) it goes to
 *    the intent's pet — a free pet is upgraded on the same code path as
 *    activate(); without one (every older app) it is auto-assigned exactly as
 *    before: when the family has exactly one active unpaid challenge pet
 *    (status trial — a pre-M3-13 trial — or payment_required, unborn pets
 *    included). A free pet is NEVER auto-assigned without an intent.
 *  - assignAvailableBeforeBirth() (contract, M3-13): the same rule at birth
 *    for a credit the family already holds.
 *  - activate() (POST /api/parent/pets/{pet}/challenge/activate): the oldest
 *    available credit → the pet. Idempotent: a pet already paid by a
 *    purchase answers `already_active` without using another credit.
 *    M6-U01: a born, active free pet (mutt / domestic cat, not a legacy
 *    profile) is upgraded to the challenge — the 12 weeks run from now.
 *  - revokeForRefund() (webhook): the purchase's credit is revoked; its pet
 *    goes back to payment_required (ChallengeService::markRefunded).
 *  - transferUnassigned() (webhook TRANSFER): only unassigned credits move.
 */
class ChallengeCreditService
{
    public function __construct(
        private readonly ChallengeService $challenges,
    ) {}

    /**
     * Caller (webhook) holds the family row lock.
     *
     * @param  array{product_id: string, store: string|null, environment: string|null, transaction_id: string|null}  $attrs
     * @param  list<int>  $buyerIds  parent user ids of the event (app_user_id, original, aliases) — intent owners
     */
    public function create(Family $family, PurchaseEvent $event, array $attrs, CarbonInterface $purchasedAt, array $buyerIds = []): ChallengeCredit
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

        // M6-U01 (§5.3): the new app named the pet before opening the store sheet.
        $intent = $this->intentFor($family, $buyerIds, $purchasedAt);
        if ($intent !== null) {
            $intent->forceFill([
                'consumed_at' => now(),
                'consumed_by' => ChallengePurchaseIntent::CONSUMED_BY_WEBHOOK,
                'challenge_credit_id' => $credit->id,
            ])->save();

            $pet = Pet::whereKey($intent->pet_id)->where('family_id', $family->id)->lockForUpdate()->first();
            if ($pet !== null && self::intentTargetAllowed($pet)) {
                $this->assignOrUpgrade($credit, $pet, ChallengeCredit::VIA_INTENT);

                return $credit;
            }
            // The pet is no longer eligible (paid another way, game over, deleted …):
            // an unusable intent counts as none — the rule every older app relies on below.
            Log::info('Challenge: purchase intent not usable, falling back to auto-assignment', ['intent_id' => $intent->id, 'pet_id' => $intent->pet_id]);
        }

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
     * M3-13 (no free trial — the challenge starts with a purchase): right
     * before an unborn challenge pet is born (PetActivityService::signContract),
     * a credit the family already holds pays it — mirroring the webhook rule:
     * only when this pet is the family's ONLY active unpaid challenge pet
     * (with several, the parent chooses via activate()). Oldest credit first.
     * Lock order family → pet → credit. Returns true when it assigned.
     */
    public function assignAvailableBeforeBirth(Pet $pet): bool
    {
        return DB::transaction(function () use ($pet): bool {
            $family = Family::whereKey($pet->family_id)->lockForUpdate()->first();
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->first();
            if ($family === null || $locked === null || ! $locked->isUnborn() || $locked->plan !== PetPlan::Challenge
                || $locked->challenge_paid_at !== null || ! $locked->is_active || $locked->is_game_over) {
                return false;
            }

            $unpaid = $this->unpaidPets($family);
            if ($unpaid->count() !== 1 || $unpaid->first()->id !== $locked->id) {
                return false;
            }

            $credit = ChallengeCredit::where('family_id', $family->id)
                ->available()
                ->orderBy('purchased_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($credit === null) {
                return false;
            }

            $this->assign($credit, $locked, ChallengeCredit::VIA_BIRTH);

            return true;
        });
    }

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

    /**
     * Assign the family's oldest available credit to $pet (parent action).
     * Works for an unborn pet too (M3-13: the parent buys before the contract).
     *
     * @return array{status: 'activated'|'already_active', pet: Pet, credit: ChallengeCredit|null}
     *
     * @throws ChallengeException free_plan / already_paid / pet_not_active (422), no_credit (409);
     *                            for a free pet (M6-U01) game_over / pet_not_born / legacy_profile (422)
     */
    public function activate(Pet $pet, ?User $parent = null): array
    {
        return DB::transaction(function () use ($pet, $parent): array {
            Family::whereKey($pet->family_id)->lockForUpdate()->first();
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            if ($locked->plan !== PetPlan::Challenge) {
                // M6-U01: a free pet is upgraded to the challenge (same locks, same credit rule).
                self::assertUpgradeable($locked);
            } elseif ($locked->challenge_paid_at !== null) {
                if ($locked->challenge_paid_source === ChallengePaidSource::Purchase) {
                    return [
                        'status' => 'already_active',
                        'pet' => $locked,
                        'credit' => ChallengeCredit::where('pet_id', $locked->id)->unrevoked()->first(),
                    ];
                }

                throw new ChallengeException('already_paid', 'This '.$locked->speciesValue()->value.'\'s challenge is already unlocked.');
            } elseif (! $locked->is_active || $locked->is_game_over) {
                throw new ChallengeException('pet_not_active', 'This '.$locked->speciesValue()->value.' is no longer in play.');
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

            $this->assignOrUpgrade($credit, $locked, ChallengeCredit::VIA_PARENT);

            // The parent's open intents for this pet are settled (single use).
            if ($parent !== null) {
                ChallengePurchaseIntent::where('parent_user_id', $parent->id)
                    ->where('pet_id', $locked->id)
                    ->open()
                    ->update([
                        'consumed_at' => now(),
                        'consumed_by' => ChallengePurchaseIntent::CONSUMED_BY_ACTIVATE,
                        'challenge_credit_id' => $credit->id,
                        'updated_at' => now(),
                    ]);
            }

            return ['status' => 'activated', 'pet' => $locked, 'credit' => $credit];
        });
    }

    /**
     * M6-U01 refusals of a free pet that cannot be upgraded (422, clear codes):
     * `game_over`, `pet_not_active`, `pet_not_born`, `legacy_profile` (K5 / D3),
     * `free_plan` (a free pet of a paid breed — old data).
     *
     * @throws ChallengeException
     */
    public static function assertUpgradeable(Pet $pet): void
    {
        $reason = $pet->upgradeBlockReason();
        if ($reason === null) {
            return;
        }
        $species = $pet->speciesValue()->value;
        throw new ChallengeException($reason, match ($reason) {
            Pet::UPGRADE_GAME_OVER => 'This '.$species.' went to the shelter; a purchase cannot bring it back.',
            Pet::UPGRADE_NOT_ACTIVE => 'This '.$species.' is no longer in play.',
            Pet::UPGRADE_NOT_BORN => 'This '.$species.' is not born yet (the contract is not signed).',
            Pet::UPGRADE_LEGACY_PROFILE => 'This '.$species.' was created before pet profiles; it cannot be upgraded to the challenge.',
            default => 'This '.$species.' is on the free plan.',
        });
    }

    /**
     * A pet a purchase may be meant for (intent endpoint and webhook, same rules as
     * activate()): an active, unpaid challenge pet (unborn included — buy first,
     * M3-13) or a free pet that can be upgraded.
     */
    public static function intentTargetAllowed(Pet $pet): bool
    {
        if ($pet->plan === PetPlan::Free) {
            return $pet->isUpgradeableFreePet();
        }

        return $pet->challenge_paid_at === null && $pet->is_active && ! $pet->is_game_over;
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
     * @return array{credits_available: int, payments_enforced: bool, pets: list<array{pet_id: int, plan: string, status: string|null, trial_ends_at: string|null, paid_at: string|null, trial_available: bool|null, deletion_loses_purchase: bool, challenge_started_at: string|null, upgrade: array{available: bool, reason: string|null}|null}>}
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
            // Kill switch (config/payments.php): false = nobody is payment-locked yet.
            'payments_enforced' => Pet::paymentsEnforced(),
            'pets' => $pets->map(fn (Pet $pet): array => [
                'pet_id' => $pet->id,
                'plan' => $pet->plan->value,
                'status' => $pet->challengeStatus()?->value,
                // The pet's own clock (David 2026-11-09): its zone, else the family's.
                'trial_ends_at' => $pet->trial_ends_at?->copy()->setTimezone($pet->timezone ?? $tz)->toIso8601String(),
                'paid_at' => $pet->challenge_paid_at?->copy()->setTimezone($pet->timezone ?? $tz)->toIso8601String(),
                // M3-13: no free trial any more — true only for a pre-M3-13 pet whose
                // 7-day trial ran (null for a free pet). Kept for old app builds.
                'trial_available' => $this->trialAvailable($pet),
                // P5: deleting the pet (or its only child) throws the purchase away.
                'deletion_loses_purchase' => $pet->deletionLosesPurchase(),
                // M6-U01: the purchase that upgraded a free pet (its 12 weeks start here); null otherwise.
                'challenge_started_at' => $pet->challenge_started_at?->copy()->setTimezone($pet->timezone ?? $tz)->toIso8601String(),
                // M6-U01: a free pet — can it be upgraded to the challenge (reason when not, e.g.
                // `legacy_profile` → the app explains instead of a button)? null for a challenge pet.
                'upgrade' => self::upgradePayload($pet),
            ])->values()->all(),
        ];
    }

    /**
     * M3-13 (the free trial is gone; "one trial per child" P7 retired):
     * true only for a born pet whose pre-M3-13 trial ran (trial_ends_at
     * after birth); null otherwise — never `false`, which TestFlight 3.0.0
     * renders as "no free trial, the child already had one" (QA PR #83 m1).
     */
    private function trialAvailable(Pet $pet): ?bool
    {
        if ($pet->plan !== PetPlan::Challenge || $pet->born_at === null || ChallengeService::startedWithoutTrial($pet)) {
            return null;
        }

        return true;
    }

    /**
     * Active challenge pets of the family that are not paid (payment_required —
     * also unborn — or a pre-M3-13 trial; `trial` too while payments are not enforced).
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

    /**
     * The one code path that turns a credit into a paid challenge (activate and the
     * webhook with an intent). A free pet (caller checked upgradeBlockReason) is
     * upgraded first — M6-U01, FREE_PET_UPGRADE_SPEC §5.2: plan challenge, the
     * challenge clock starts now (`challenge_started_at`, a new start after a refund —
     * K7), `trial_ends_at = born_at` (no "trial available"), the M5-F02 conversion
     * marker cleared (CHECK: only on a free pet), the certificate scan looks again.
     * Age, look, name, skills and items stay. markPaid() then saves the row once
     * (non-quiet: hooks), broadcasts `challenge_paid` and queues the full media set
     * (P6 / M4-10 — a shared look keeps its look). Caller holds family → pet → credit locks.
     */
    private function assignOrUpgrade(ChallengeCredit $credit, Pet $pet, string $via): void
    {
        if ($pet->plan !== PetPlan::Free) {
            $this->assign($credit, $pet, $via);

            return;
        }

        $now = now()->startOfSecond();
        $pet->forceFill([
            'plan' => PetPlan::Challenge,
            'challenge_started_at' => $now,
            'trial_ends_at' => $pet->born_at,
            'payment_locked_at' => null,
            'converted_to_free_at' => null,
            'certificate_next_check_at' => null,
        ]);
        $credit->forceFill(['pet_id' => $pet->id, 'assigned_at' => $now, 'assigned_via' => $via])->save();
        $this->challenges->markPaid($pet, ChallengePaidSource::Purchase, $now, upgrade: true);

        Log::info('Challenge: free pet upgraded to the 12-week challenge', ['pet_id' => $pet->id, 'via' => $via]);
    }

    /**
     * The newest open intent of one of the buying parents in this family that was
     * valid when the store charged (`expires_at` ≥ purchase time; created no later
     * than the purchase + a 5-minute clock skew). A delayed webhook still honours it.
     *
     * @param  list<int>  $buyerIds
     */
    private function intentFor(Family $family, array $buyerIds, CarbonInterface $purchasedAt): ?ChallengePurchaseIntent
    {
        if ($buyerIds === []) {
            return null;
        }

        return ChallengePurchaseIntent::where('family_id', $family->id)
            ->whereIn('parent_user_id', $buyerIds)
            ->open()
            ->where('expires_at', '>=', $purchasedAt)
            ->where('created_at', '<=', $purchasedAt->copy()->addMinutes(5))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{available: bool, reason: string|null}|null
     */
    public static function upgradePayload(Pet $pet): ?array
    {
        if ($pet->plan !== PetPlan::Free) {
            return null;
        }
        $reason = $pet->upgradeBlockReason();

        return ['available' => $reason === null, 'reason' => $reason];
    }
}
