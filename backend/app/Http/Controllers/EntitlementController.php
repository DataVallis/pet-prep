<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EntitlementService;
use App\Services\FamilyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly FamilyService $families,
    ) {}

    /**
     * The family's entitlements (M3-08) — what this family has unlocked,
     * shared by every parent of the family whoever paid. One entry per
     * entitlement we know (today `challenge`); `active: false` with nulls
     * when the family doesn't have it. `expires_at` null on an active entry
     * = lifetime (one-time purchase). Parents only (403 for a child).
     *
     * GET /api/parent/entitlements
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        abort_unless($parent->can('viewEntitlements', User::class), 403);

        // GET never creates a family.
        $family = $this->families->familyOf($parent);

        return response()->json([
            /** @var list<array{key: string, active: bool, source: 'revenuecat'|null, store: string|null, granted_at: string|null, expires_at: string|null}> */
            'entitlements' => $this->entitlements->forFamily($family),
        ]);
    }
}
