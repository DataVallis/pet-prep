<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountDeletionException;
use App\Exceptions\FamilyException;
use App\Http\Requests\ConfirmDeletionRequest;
use App\Http\Requests\CreateChildRequest;
use App\Http\Requests\LogParentWalkRequest;
use App\Http\Requests\UpdateChildSettingsRequest;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\PetActivityService;
use App\Services\Results\ActionResult;
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
                // M5-T12: a new profile starts without adapted mode.
                'adapted_mode' => false,
            ],
        ], 201);
    }

    /**
     * Parent-only settings of a child profile (M5-T12): `{"adapted_mode": bool}` —
     * »Prilagojen način«, the child's walk counts wheelchair pushes instead of steps
     * (1 : 1; goals, points and the Care Score stay the same). Accessibility, not a
     * diagnosis: no reason is asked for or stored. A change broadcasts
     * `caretaker_settings_changed` on the child's active pet. 200 `{child_id, adapted_mode}`;
     * 404 `child_not_found` for a child outside the family; child token → 403.
     *
     * PATCH /api/parent/children/{child}/settings
     */
    public function updateSettings(UpdateChildSettingsRequest $request, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();

        $profile = $this->profiles->childOfParentFamily($parent, $child);
        if ($profile === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }

        $updated = $this->profiles->setAdaptedMode($profile, $request->adaptedMode());

        return response()->json([
            'child_id' => $updated->id,
            /**
             * M5-T12: true = the walk counts wheelchair pushes (1 push = 1 step).
             *
             * @var bool
             */
            'adapted_mode' => (bool) $updated->adapted_mode,
        ], 200);
    }

    /**
     * M5-T12 (David 2026-10-21): log a walk for a child in adapted mode — the parent
     * enters minutes (1–240) for today or yesterday (family time zone); they count as
     * minutes × 100 steps (the step-goal rule) on the child's day of their active pet,
     * on top of any wheelchair pushes from the phone. Idempotent per `entry_id`.
     * 201 `{status: accepted, entry, steps_today}`; the same `entry_id` again → 200
     * `{status: unchanged, entry, steps_today}`. 422 `adapted_mode_off` (switch first),
     * `date_not_allowed` (not today / yesterday), `steps_not_applicable` (a cat);
     * 423 `{reason}` while the pet is locked; 404 `child_not_found` / `no_pet`.
     *
     * POST /api/parent/children/{child}/walks
     */
    public function logWalk(LogParentWalkRequest $request, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();

        $profile = $this->profiles->childOfParentFamily($parent, $child);
        if ($profile === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }
        if (! (bool) $profile->adapted_mode) {
            return response()->json(['message' => 'Adapted mode is off for this child.', 'reason' => 'adapted_mode_off'], 422);
        }
        $pet = app(FamilyService::class)->activePetOf($profile);
        if ($pet === null) {
            return response()->json(['message' => 'This child has no pet yet.', 'reason' => 'no_pet'], 404);
        }

        $result = app(PetActivityService::class)->logParentWalk($pet, $profile, $parent, $request->localDate(), $request->minutes(), $request->entryId());

        if ($result->status === ActionResult::LOCKED) {
            return response()->json(['message' => 'The pet is locked right now.', 'reason' => $result->lockReason?->value], 423);
        }
        if ($result->status === ActionResult::REFUSED) {
            return response()->json(['message' => 'Steps do not apply to this pet.', 'reason' => $result->refusal?->value], 422);
        }
        if ($result->status === ActionResult::STALE) {
            return response()->json(['message' => 'Only today or yesterday can be logged.', 'reason' => 'date_not_allowed'], 422);
        }

        $pet->refresh();

        return response()->json([
            /** @var 'accepted'|'unchanged' */
            'status' => $result->status,
            /**
             * The logged walk (`steps` = minutes × 100).
             *
             * @var array{id: int, entry_id: string, pet_id: int, child_id: int, local_date: string, minutes: int, steps: int, created_at: string|null}
             */
            'entry' => $result->extra['entry'],
            /** The child's own steps today on the pet after the entry. */
            'steps_today' => $pet->stepsTodayOf($profile),
        ], $result->status === ActionResult::ACCEPTED ? 201 : 200);
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

    /**
     * Delete a child profile — immediately and irreversibly (M2-08). Body
     * `{password (the parent's), confirm: true}`. The child's devices, PINs,
     * contract (signature) and step history go. A pet only this child cared
     * for is deleted with all its data and AI media; a pet shared with
     * another child stays — the deleted child's past actions keep counting
     * for the pet, without a name.
     *
     * 200 `{status: deleted, child_id, pets_deleted, pets_kept}`; 404
     * `child_not_found` (another family / missing / not a child); 422
     * `invalid_password` (or `reauth_required` for a parent without a password
     * who confirmed with `provider` + `id_token` (+ `nonce` for Apple) — a
     * linked provider, token ≤ 10 min old, M2-10c; 503 `provider_unavailable` /
     * `provider_disabled`); 429 `too_many_attempts` + `Retry-After` after 5 wrong
     * passwords in 15 min (only failed attempts count; separate from the
     * account deletion).
     *
     * DELETE /api/parent/children/{child}
     */
    public function destroy(ConfirmDeletionRequest $request, AccountDeletionService $deletion, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('manageFamily', User::class)) {
            abort(403);
        }

        $profile = $this->profiles->childOfParentFamily($parent, $child);
        if ($profile === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }

        try {
            $deletion->confirmIdentity(
                $parent,
                $request->password(),
                $request->provider(),
                $request->idToken(),
                $request->nonce(),
                AccountDeletionService::SCOPE_CHILD,
            );
            $result = $deletion->deleteChildProfile($parent, $profile, $request->acknowledgesPaidChallenge());
        } catch (AccountDeletionException $e) {
            return $e->toResponse();
        }

        return response()->json(['status' => 'deleted'] + $result, 200);
    }
}
