<?php

namespace App\Http\Controllers;

use App\Exceptions\ChallengeException;
use App\Http\Requests\ActivateChallengeRequest;
use App\Http\Requests\BillingRequest;
use App\Models\User;
use App\Services\ChallengeCreditService;
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
    ) {}

    /**
     * Unused challenge purchases of the family and the payment status of
     * every active pet. `status` null = free plan; `trial_ends_at` null =
     * free plan or not born yet (trial starts at birth).
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
            /** @var list<array{pet_id: int, plan: 'free'|'challenge', status: 'trial'|'payment_required'|'paid'|null, trial_ends_at: string|null, paid_at: string|null}> */
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
     * pet → 404 `pet_not_found`.
     *
     * POST /api/parent/pets/{pet}/challenge/activate
     */
    public function activate(ActivateChallengeRequest $request, string $pet): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        $family = $this->families->familyOf($parent);
        $target = $family !== null ? $this->dashboard->targetPet($family, $pet) : null;

        if ($target === null || ! $parent->can('manage', $target)) {
            return response()->json(['message' => 'No such pet in your family.', 'reason' => 'pet_not_found'], 404);
        }

        try {
            $result = $this->credits->activate($target);
        } catch (ChallengeException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        $billing = $this->credits->billing($family);

        return response()->json([
            /** @var 'activated'|'already_active' */
            'status' => $result['status'],
            'pet_id' => $result['pet']->id,
            'plan' => PetPlanPayload::for($result['pet'], $family->timezone)->toArray(),
            /** @var int */
            'credits_available' => (int) $billing['credits_available'],
        ]);
    }
}
