<?php

namespace App\Http\Controllers;

use App\Enums\TokenAbility;
use App\Exceptions\ChildLoginException;
use App\Http\Requests\ChildDeviceSessionRequest;
use App\Http\Requests\PinLoginRequest;
use App\Http\Resources\PairedPetResource;
use App\Models\User;
use App\Services\ChildPinLoginService;
use App\Services\ChildProfileService;
use App\Services\Results\ChildSession;
use Illuminate\Http\JsonResponse;

/**
 * PIN-only child login (M2-02): the child has no e-mail and no password.
 */
class ChildAuthController extends Controller
{
    public function __construct(private readonly ChildPinLoginService $logins) {}

    /**
     * Sign a child in with the one-time PIN their parent generated for them
     * (`POST /api/parent/generate-pin {child_id, pet_id?}`). Unauthenticated.
     *
     * First use pairs the child (new unborn pet, or joins the shared pet —
     * `awaiting_contract` true until the child signs); a PIN for an already
     * paired child only signs in another device (`mode: relogin`). The token
     * has the ability `child`; a child keeps at most 3 signed-in devices (the
     * oldest token is revoked).
     *
     * 422 `invalid_pin` (wrong, expired or used — same answer for all),
     * 422 `pin_not_usable` (the family changed since the PIN was issued),
     * 429 `too_many_attempts` (`retry_after` seconds),
     * 422 `app_update_required` (M5-R06-01: the pet is a cat and this build
     * did not send `features: ["species_cat"]`; the PIN stays usable).
     *
     * Optional `features` (M5-R02, PR #42): this child app build's UI
     * features (`behaviour_events`, `training`; unknown values ignored, ≤ 10
     * strings). A new pet gets a feature (behaviour events, training — M5-R03)
     * only when the parent's PIN AND this device declared it; join /
     * re-login never change it.
     *
     * POST /api/child/pin-login
     */
    public function pinLogin(PinLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->logins->login($request->pin(), $request->deviceName(), (string) $request->ip(), $request->features());
        } catch (ChildLoginException $e) {
            return $this->refusal($e);
        }

        return $this->session($result);
    }

    /**
     * Profile switching on one phone (M2-11, David 2026-10-14): the signed-in
     * parent opens this child's profile on the same device — no PIN (the
     * parent is already authenticated). Back to the parent = a real parent
     * re-login (password or Google / Apple); the app revokes the parent token
     * when it switches (David 2026-10-14). The answer is exactly the `POST /api/child/pin-login` body of
     * a re-login (`mode: relogin`, token with the `child` ability). The parent's
     * own token is untouched; each token logs out on its own.
     *
     * One shared-phone session per parent and child: calling it again replaces
     * the previous one (switching back and forth never signs the child's own
     * phones out). It counts as one of the child's 3 devices.
     *
     * Optional `device_name` (phone model) and `features` as at PIN login.
     * 404 `child_not_found` (not a child of the parent's family), 403 for a
     * non-parent, 409 `no_pet` (the child has no pet yet — pair with a PIN
     * first), 422 `app_update_required` (a cat without `species_cat`), 429
     * when throttled (20 / min per parent).
     *
     * POST /api/parent/children/{child}/device-session
     */
    public function deviceSession(ChildDeviceSessionRequest $request, ChildProfileService $profiles, string $child): JsonResponse
    {
        /** @var User $parent */
        $parent = $request->user();
        if (! $parent->can('manageFamily', User::class)) {
            abort(403);
        }

        $profile = $profiles->childOfParentFamily($parent, $child);
        if ($profile === null || ! $parent->can('manageChild', $profile)) {
            return response()->json(['message' => 'No such child in your family.', 'reason' => 'child_not_found'], 404);
        }

        try {
            $result = $this->logins->deviceSession($parent, $profile, $request->deviceName(), $request->features());
        } catch (ChildLoginException $e) {
            return $this->refusal($e);
        }

        return $this->session($result);
    }

    /**
     * The child session body (PIN login and device session). Typed parameters
     * keep the OpenAPI schema precise.
     */
    private function session(ChildSession $session): JsonResponse
    {
        $child = $session->child;
        $pet = $session->pet->refresh();

        return response()->json([
            'token' => $session->token,
            'abilities' => [TokenAbility::Child->value],
            // Only the nickname — the profile has no e-mail.
            'user' => [
                'id' => $child->id,
                'name' => $child->name,
                'role' => $child->role->value,
            ],
            'mode' => $session->mode,
            'joined_existing' => $session->joinedExisting,
            'family_id' => $pet->family_id,
            'pet' => new PairedPetResource($pet, $child),
            'awaiting_contract' => (bool) ($pet->isUnborn() || $pet->caretakerNeedsContract($child)),
        ], 200);
    }

    private function refusal(ChildLoginException $e): JsonResponse
    {
        $body = ['message' => $e->getMessage(), 'reason' => $e->reason];
        $headers = [];
        if ($e->retryAfter !== null) {
            $body['retry_after'] = $e->retryAfter;
            $headers['Retry-After'] = (string) $e->retryAfter;
        }

        return response()->json($body, $e->status, $headers);
    }
}
