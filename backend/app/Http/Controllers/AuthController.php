<?php

namespace App\Http\Controllers;

use App\Enums\TokenAbility;
use App\Http\Requests\LoginRequest;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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
        $user = User::where('email', $request->input('email'))->first();
        $hash = $user?->getAuthPassword();

        if ($user === null || ! is_string($hash) || $hash === ''
            || ! Hash::check($request->input('password'), $hash)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $deviceName = $request->input('device_name', 'mobile-app');
        $token = $user->createToken($deviceName, TokenAbility::abilitiesFor($user))->plainTextToken;

        $activePet = $this->sessionPet($user);

        return response()->json([
            'token' => $token,
            'abilities' => TokenAbility::abilitiesFor($user),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            'pet' => $activePet,
        ], 200);
    }

    /**
     * Get the authenticated user's profile.
     *
     * GET /api/user
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        $activePet = $this->sessionPet($user);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'pet' => $activePet,
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
