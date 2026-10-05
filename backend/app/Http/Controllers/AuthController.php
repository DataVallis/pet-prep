<?php

namespace App\Http\Controllers;

use App\Enums\TokenAbility;
use App\Exceptions\EmailTakenException;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterParentRequest;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\ParentRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Parent self-registration with e-mail + password (M2-10a). Creates the
     * parent and their own family (timezone from the device, default
     * Europe/Ljubljana) and signs them in: same body as `POST /api/login`
     * (token with the `parent` ability, `pet` null), status 201.
     *
     * - `email` is trimmed and stored lower case; 422 when the address is
     *   already used (case-insensitive) — an accepted trade-off for parents.
     * - `timezone`: device aliases (Etc/UTC, Asia/Calcutta, Europe/Kiev …)
     *   are mapped to the canonical IANA name; an unknown but valid zone
     *   becomes Europe/Ljubljana (logged); only a non-timezone is a 422.
     * - Every 422 carries `codes` {field: code}: `name_invalid`,
     *   `email_invalid`, `email_taken`, `password_weak`, `password_mismatch`,
     *   `terms_required`, `timezone_invalid`, `device_name_invalid`.
     * - `password`: ≥ 10 characters, upper + lower case and a digit,
     *   `password_confirmation` must match.
     * - `accept_terms` must be true (terms of use + privacy policy);
     *   stored as `terms_accepted_at`.
     * - No e-mail verification yet (M2-10b). Throttled: 5 / min and
     *   20 / hour per IP (429 with `Retry-After`).
     *
     * POST /api/register
     */
    public function register(RegisterParentRequest $request, ParentRegistrationService $registrations): JsonResponse
    {
        try {
            $result = $registrations->register(
                $request->displayName(),
                $request->emailAddress(),
                $request->plainPassword(),
                $request->familyTimezone(),
                $request->deviceName(),
            );
        } catch (EmailTakenException $e) {
            // Concurrent sign-up with the same address won the race.
            return RegisterParentRequest::errorResponse(
                ['email' => [__('validation.unique', ['attribute' => 'email'])]],
                ['email' => RegisterParentRequest::CODE_EMAIL_TAKEN],
            );
        }

        $user = $result['user'];

        return response()->json([
            'token' => $result['token'],
            'abilities' => TokenAbility::abilitiesFor($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            // A new family has no pet yet; the parent adds a child next.
            'pet' => null,
            'awaiting_contract' => null,
        ], 201);
    }

    /**
     * Authenticate a user with e-mail + password and issue a Sanctum API
     * token. The token carries one ability (M2-03): `parent` or `child`.
     *
     * Children: only legacy child accounts that still have an e-mail and a
     * password can sign in here (**deprecated**, dev/test accounts until they
     * are migrated). PIN-only child profiles (M2-02) have neither and use
     * `POST /api/child/pin-login`; this endpoint answers them like any wrong
     * credentials (401).
     *
     * POST /api/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Case-insensitive (M2-10a stores lower case; legacy rows may not be).
        $user = User::findByEmail((string) $request->input('email'));
        $hash = $user?->getAuthPassword();

        if ($user === null || ! is_string($hash) || $hash === ''
            || ! Hash::check($request->input('password'), $hash)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $deviceName = (string) $request->input('device_name', 'mobile-app');
        if ($user->isChild()) {
            // Never store a child's raw device name (may contain their name).
            $deviceName = ChildProfileService::deviceLabel($deviceName);
        }
        $token = $user->createToken($deviceName, TokenAbility::abilitiesFor($user))->plainTextToken;

        $activePet = $this->sessionPet($user);
        $awaiting = $this->awaitingContract($user, $activePet);

        return response()->json([
            'token' => $token,
            'abilities' => TokenAbility::abilitiesFor($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'pet' => $this->petPayload($activePet, $awaiting),
            // Per child (M2-02): this child must sign before acting. null for a parent.
            'awaiting_contract' => $awaiting,
        ], 200);
    }

    /**
     * Get the authenticated user's profile (session restore on app launch).
     *
     * `awaiting_contract` (also inside `pet`) is evaluated for the signed-in
     * child: the pet is unborn, or the child joined a shared pet and hasn't
     * signed their own contract yet. null for a parent.
     *
     * GET /api/user
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        $activePet = $this->sessionPet($user);
        $awaiting = $this->awaitingContract($user, $activePet);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'pet' => $this->petPayload($activePet, $awaiting),
            'awaiting_contract' => $awaiting,
        ], 200);
    }

    /**
     * The pet shown with the session (M2-01): a child's active caretaker
     * pet; for a parent the family's oldest active pet (legacy single-pet
     * field — the family is in GET /api/parent/dashboard).
     */
    private function sessionPet(User $user): ?Pet
    {
        if ($user->isChild()) {
            return $user->activePet();
        }

        $familyId = $user->family?->id;

        return $familyId === null
            ? null
            : Pet::where('family_id', $familyId)->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * Child: must this child sign a contract before acting? True when the
     * pet is unborn or the child's own caretaker row still requires a
     * contract they haven't signed (shared pet joined after its birth).
     * Parent: null (parents never sign).
     */
    private function awaitingContract(User $user, ?Pet $pet): ?bool
    {
        if (! $user->isChild()) {
            return null;
        }

        return $pet !== null && ($pet->isUnborn() || $pet->caretakerNeedsContract($user));
    }

    /**
     * The raw pet model (legacy shape, unchanged — kept as a model so the
     * OpenAPI `Pet` schema stays) plus `awaiting_contract` when it is known
     * per child. The attribute lives only on this response instance, which
     * is never saved.
     */
    private function petPayload(?Pet $pet, ?bool $awaiting): ?Pet
    {
        if ($pet !== null && $awaiting !== null) {
            $pet->setAttribute('awaiting_contract', $awaiting);
        }

        return $pet;
    }

    /**
     * Revoke the current API token (logout).
     *
     * POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ], 200);
    }
}
