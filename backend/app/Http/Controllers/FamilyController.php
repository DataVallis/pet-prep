<?php

namespace App\Http\Controllers;

use App\Exceptions\FamilyException;
use App\Http\Requests\InviteParentRequest;
use App\Http\Requests\JoinFamilyRequest;
use App\Models\Family;
use App\Services\FamilyInviteService;
use Illuminate\Http\JsonResponse;

/**
 * Second parent (M2-01, ADR-012): invite code → join family.
 */
class FamilyController extends Controller
{
    public function __construct(private readonly FamilyInviteService $invites) {}

    /**
     * Create an invite code for another parent: 8 characters, valid 24 h,
     * single use. A new code revokes this parent's previous unused one.
     *
     * POST /api/parent/invite-parent
     */
    public function invite(InviteParentRequest $request): JsonResponse
    {
        $result = $this->invites->createInvite($request->user());

        return response()->json([
            'code' => $result['code'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'expires_in_hours' => FamilyInviteService::EXPIRY_HOURS,
        ], 201);
    }

    /**
     * Join the inviting family as a parent. Allowed only for a parent account
     * without children or pets of its own (409 `family_not_empty`).
     * 422 `invalid_code` | `code_expired` | `code_used`; 409 `already_member`;
     * 429 `too_many_attempts` after 5 wrong codes in 15 minutes.
     *
     * POST /api/parent/join-family
     */
    public function join(JoinFamilyRequest $request): JsonResponse
    {
        try {
            $family = $this->invites->joinFamily($request->user(), (string) $request->validated('code'));
        } catch (FamilyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], $e->status);
        }

        return response()->json([
            'message' => 'You joined the family.',
            'family' => $this->summary($family),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Family $family): array
    {
        return [
            'id' => $family->id,
            'timezone' => $family->timezone,
            'parents' => $family->parents()->get(['users.id', 'users.name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()->all(),
            'children_count' => $family->children()->count(),
            'pets_count' => $family->pets()->count(),
        ];
    }
}
