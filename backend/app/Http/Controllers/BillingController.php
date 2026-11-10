<?php

namespace App\Http\Controllers;

use App\Exceptions\ChallengeException;
use App\Http\Requests\ActivateChallengeRequest;
use App\Http\Requests\BillingRequest;
use App\Http\Requests\ChallengeIntentRequest;
use App\Models\User;
use App\Services\ChallengeCreditService;
use App\Services\ChallengePurchaseIntentService;
use App\Services\FamilyDashboardService;
use App\Services\FamilyService;
use App\Services\PetPlanPayload;
use Illuminate\Http\JsonResponse;

/**
 * Billing of the 12-week challenge (M3-11, PAYMENTS_SPEC). Purchases happen
 * only in the parent app (RevenueCat); the server keeps the credits and
 * which pet each one paid for.
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly ChallengeCreditService $credits,
        private readonly FamilyService $families,
        private readonly FamilyDashboardService $dashboard,
        private readonly ChallengePurchaseIntentService $intents,
    ) {}

    /**
     * Unused challenge purchases of the family and the payment status of
     * every active pet. `status` null = free plan; an unpaid challenge is
     * `payment_required` (also before birth — buy first, M3-13) or `trial`
     * (a pre-M3-13 trial still running, or payments not enforced).
     * `trial_ends_at` null = free plan or not born yet; `trial_available` is
     * deprecated (no free trial since M3-13). M6-U01: `upgrade` (free pets only,
     * null for a challenge pet) says whether the free pet can be upgraded to the
     * challenge (`reason` when not — `legacy_profile` → the app explains);
     * `challenge_started_at` = the purchase that upgraded a free pet (its 12 weeks
     * start there), null for every other pet.
     *
     * GET /api/parent/billing
     */
    public function index(BillingRequest $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        // GET never creates a family.
        $billing = $this->credits->billing($this->families->familyOf($parent));

        return response()->json([
            /** @var int */
            'credits_available' => $billing['credits_available'],
            // Kill switch (config/payments.php): false = nobody is payment-locked yet.
            /** @var bool */
            'payments_enforced' => $billing['payments_enforced'],
            /** @var list<array{pet_id: int, plan: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null, trial_ends_at: string|null, paid_at: string|null, trial_available: bool|null, deletion_loses_purchase: bool, challenge_started_at: string|null, upgrade: array{available: bool, reason: 'legacy_profile'|'pet_not_born'|'game_over'|'pet_not_active'|'free_plan'|null}|null}> */
            'pets' => $billing['pets'],
        ]);
    }

    /**
     * Use the family's oldest unused challenge purchase for this pet — call
     * it after a successful store purchase. Idempotent: a pet already paid
     * by a purchase → 200 `already_active` (no second credit is used; the
     * webhook may have assigned it already). 409 `no_credit` = the purchase
     * has not reached the server yet (retry shortly). 422 `free_plan`,
     * `already_paid` (grandfathered pet), `pet_not_active`. Another family's
     * pet → 404 `pet_not_found`. M6-U01: a born, active free pet (mutt / domestic
     * cat) is upgraded to the 12-week challenge — the challenge starts now; 422
     * `game_over`, `pet_not_born`, `legacy_profile` (a pre-M5 mutt), `free_plan`
     * (a free pet of a paid breed).
     *
     * POST /api/parent/pets/{pet}/challenge/activate
     */
    public function activate(ActivateChallengeRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;

        if ($target === null || ! $parent->can('buyChallenge', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        try {
            $result = $this->credits->activate($target, $parent);
        } catch (ChallengeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        $billing = $this->credits->billing($family);

        return response()->json([
            /** @var 'activated'|'already_active' */
            'status' => $result['status'],
            'pet_id' => $result['pet']->id,
            'plan' => PetPlanPayload::for($result['pet'], $result['pet']->timezone ?? $family->timezone)->toArray(),
            /** @var int */
            'credits_available' => (int) $billing['credits_available'],
        ]);
    }

    /**
     * M6-U01 purchase intent (FREE_PET_UPGRADE_SPEC §5.3): call it right BEFORE
     * opening the store sheet — the server remembers, for this parent (the
     * RevenueCat app user), which pet the next challenge purchase is for, and the
     * webhook assigns the purchase to it (a free pet is upgraded). Single use,
     * valid 30 minutes; a new intent replaces the parent's earlier one. Still
     * call `activate` after the purchase (idempotent). Allowed for an active
     * unpaid challenge pet (also unborn) or an upgradeable free pet; 422
     * `already_paid`, `pet_not_active`, `game_over`, `pet_not_born`,
     * `legacy_profile`, `free_plan`. Another family's pet → 404 `pet_not_found`.
     * The app must not open the store sheet when this fails.
     *
     * POST /api/parent/pets/{pet}/challenge/intent
     */
    public function intent(ChallengeIntentRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;

        if ($target === null || ! $parent->can('buyChallenge', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        try {
            $intent = $this->intents->create($parent, $target);
        } catch (ChallengeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            /** @var int */
            'intent_id' => $intent->id,
            /** @var int */
            'pet_id' => $intent->pet_id,
            /** @var string */
            'expires_at' => $intent->expires_at->toIso8601String(),
        ], 201);
    }
}
