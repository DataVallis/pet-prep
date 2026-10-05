<?php

namespace App\Http\Controllers;

use App\Exceptions\FamilyException;
use App\Exceptions\PairingException;
use App\Http\Requests\GeneratePinRequest;
use App\Http\Requests\PairChildRequest;
use App\Http\Resources\PairedPetResource;
use App\Models\Pet;
use App\Models\User;
use App\Services\ChildPinLoginService;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PairingController extends Controller
{
    public const PAIR_REFUSED_MESSAGE = 'This code cannot be used. Ask your parent for a new code.';

    public function __construct(
        private readonly PairingService $pairingService,
        private readonly ChildPinLoginService $childLogins,
    ) {}

    /**
     * Generate a 6-digit, one-time child PIN (15 minutes).
     *
     * With `child_id` (M2-02, preferred): the PIN is for that child profile
     * of the family and is used on the child's device with
     * `POST /api/child/pin-login` (no e-mail, no password). `mode` says what
     * it will do: `new_pet` (first pairing), `join_pet` (with `pet_id`: the
     * child joins that shared pet), `relogin` (already paired child, new
     * device). A new PIN for the child replaces their previous one.
     * 404 `child_not_found`, 422 `pet_not_joinable` | `already_paired` |
     * `breed_locked`.
     *
     * New pet profile (M5-R01, only without `pet_id`), all or nothing:
     * `origin` bought | adopted and `age_stage` puppy | young | adult |
     * senior (both required as soon as any profile field is sent),
     * optional `breed` (default mutt; a premium breed → 422
     * `breed_locked`, it is unlocked by purchase). The pet is created with
     * this profile (life-stage rules) when the child uses the PIN. **None
     * of the fields** (old app builds) → `pet_profile: null` and a
     * legacy-profile pet that keeps the pre-M5 rules.
     *
     * Without `child_id` (**deprecated**, `Deprecation: true` header): the
     * PIN is for a child already signed in with e-mail, used with
     * `POST /api/child/pair`; optional `pet_id` = join that pet. The
     * profile fields are refused there (422 validation error); the pet it
     * creates has a legacy profile (pre-M5 rules).
     *
     * POST /api/parent/generate-pin
     */
    public function generatePin(GeneratePinRequest $request): JsonResponse
    {
        $childId = $request->childId();

        try {
            if ($childId !== null) {
                $result = $this->childLogins->generatePin($request->user(), $childId, $request->joinPetId(), $request->petProfile());

                return response()->json([
                    'pin' => $result['pin'],
                    'expires_at' => $result['expires_at']->toIso8601String(),
                    'expires_in_minutes' => ChildPinLoginService::PIN_EXPIRY_MINUTES,
                    'child_id' => $result['child_id'],
                    // null = new pet (or re-login); otherwise the pet to join.
                    'pet_id' => $result['pet_id'],
                    'mode' => $result['mode'],
                    /**
                     * M5-R01: the new pet's profile the PIN will create (mode new_pet with a profile);
                     * null = join / re-login, or no profile sent (→ legacy pet, pre-M5 rules).
                     *
                     * @var array{breed: 'mutt'|'border_collie', origin: 'bought'|'adopted', age_stage: 'puppy'|'young'|'adult'|'senior'}|null
                     */
                    'pet_profile' => $result['pet_profile'],
                ], 200);
            }

            $result = $this->pairingService->generatePin($request->user(), $request->joinPetId());
        } catch (FamilyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], $e->status);
        }

        return response()->json([
            'pin' => $result['pin'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'expires_in_minutes' => PairingService::PIN_EXPIRY_MINUTES,
            'child_id' => null,
            // null = new pet; otherwise the pet the child will join.
            'pet_id' => $result['pet_id'],
            'mode' => $result['pet_id'] === null ? ChildPinLoginService::MODE_NEW_PET : ChildPinLoginService::MODE_JOIN_PET,
        ], 200, ['Deprecation' => 'true']);
    }

    /**
     * Pair a child using a parent's PIN. New-pet PIN: creates the child's
     * pet — unborn (`born_at` null) until POST /api/child/contract. Join PIN
     * (M2-01): the child becomes a caretaker of the existing pet
     * (`joined_existing` true); the pet is not re-born, but this child must
     * sign their own contract (`awaiting_contract` true for them).
     *
     * **Deprecated (M2-02):** only for children with an e-mail account; new
     * child profiles sign in with `POST /api/child/pin-login`.
     *
     * POST /api/child/pair
     */
    public function pairChild(PairChildRequest $request): JsonResponse
    {
        /** @var User $child */
        $child = $request->user();

        try {
            $result = $this->pairingService->pairChild($request->input('pin'), $child);

            /** @var Pet $pet */
            $pet = $result['pet']->refresh();

            return response()->json([
                'message' => 'Pairing successful. Pet session initialized.',
                'parent_id' => $result['parent']->id,
                'family_id' => $pet->family_id,
                'joined_existing' => $result['joined_existing'],
                'pet' => new PairedPetResource($pet, $child),
            ], 201);
        } catch (PairingException $e) {
            // One answer for every refusal (wrong / expired PIN, already
            // paired child, parent-side problems) — no oracle for PIN
            // guessing (PR #16 review). The reason is only logged.
            Log::info('Legacy child pairing refused', ['child_id' => $child->id, 'reason' => $e->getMessage()]);

            return response()->json([
                'message' => self::PAIR_REFUSED_MESSAGE,
                'reason' => 'pairing_refused',
            ], 422);
        }
    }
}
