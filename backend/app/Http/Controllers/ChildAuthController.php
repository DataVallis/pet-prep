<?php

namespace App\Http\Controllers;

use App\Enums\TokenAbility;
use App\Exceptions\ChildLoginException;
use App\Http\Requests\PinLoginRequest;
use App\Http\Resources\PairedPetResource;
use App\Services\ChildPinLoginService;
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
            $body = ['message' => $e->getMessage(), 'reason' => $e->reason];
            $headers = [];
            if ($e->retryAfter !== null) {
                $body['retry_after'] = $e->retryAfter;
                $headers['Retry-After'] = (string) $e->retryAfter;
            }

            return response()->json($body, $e->status, $headers);
        }

        $child = $result['child'];
        $pet = $result['pet']->refresh();

        return response()->json([
            'token' => $result['token'],
            'abilities' => [TokenAbility::Child->value],
            // Only the nickname — the profile has no e-mail.
            'user' => [
                'id' => $child->id,
                'name' => $child->name,
                'role' => $child->role->value,
            ],
            'mode' => $result['mode'],
            'joined_existing' => $result['joined_existing'],
            'family_id' => $pet->family_id,
            'pet' => new PairedPetResource($pet, $child),
            'awaiting_contract' => (bool) ($pet->isUnborn() || $pet->caretakerNeedsContract($child)),
        ], 200);
    }
}
