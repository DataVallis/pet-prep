<?php

namespace App\Http\Controllers;

use App\Exceptions\FamilyException;
use App\Http\Requests\CreateChildRequest;
use App\Models\User;
use App\Services\ChildProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Child profiles without e-mail / password (M2-02). Parent API, family
 * scoped: a child of another family is a 404.
 */
class ChildProfileController extends Controller
{
    public function __construct(private readonly ChildProfileService $profiles) {}

    /**
     * Create a child profile in the parent's family: nickname (≤ 30
     * characters) and optional birth year — no e-mail, no password, no
     * surname. Then `POST /api/parent/generate-pin {child_id}` gives the
     * child a one-time PIN for `POST /api/child/pin-login`.
     * 422 `too_many_children` (10 per family).
     *
     * POST /api/parent/children
     */
    public function store(CreateChildRequest $request): JsonResponse
    {
        try {
            $child = $this->profiles->createChild($request->user(), $request->displayName(), $request->birthYear());
        } catch (FamilyException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            'child' => [
                'id' => $child->id,
                'display_name' => $child->name,
                'birth_year' => $child->birth_year,
                'family_id' => $child->family?->id,
                'pet_id' => null,
                'devices' => 0,
            ],
        ], 201);
    }

    /**
     * Sign the child out on every device (deletes all their tokens) and
     * revoke their open login PIN. 404 for a child outside the family.
     *
     * DELETE /api/parent/children/{child}/tokens
     */
    public function revokeTokens(Request $request, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('manageFamily', User::class)) {
            abort(403);
        }

        // Family scoped: another family's child looks exactly like a missing one.
        $profile = $this->profiles->childOfParentFamily($parent, $child);
        if ($profile === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }

        $revoked = $this->profiles->revokeDevices($profile);

        return response()->json([
            'revoked_tokens' => $revoked['tokens'],
            'revoked_pins' => $revoked['pins'],
        ], 200);
    }
}
