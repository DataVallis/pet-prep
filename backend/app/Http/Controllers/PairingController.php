<?php

namespace App\Http\Controllers;

use App\Exceptions\FamilyException;
use App\Exceptions\PairingException;
use App\Http\Requests\GeneratePinRequest;
use App\Http\Requests\PairChildRequest;
use App\Models\Pet;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

class PairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairingService,
    ) {}

    /**
     * Generate a 6-digit child pairing PIN for the authenticated parent.
     * Body (optional, M2-01): `pet_id` of an existing pet of the family → the
     * child will share that pet; omitted → the PIN creates a new pet.
     * 422 `pet_not_joinable` when the pet is not an active pet of this family.
     *
     * POST /api/parent/generate-pin
     */
    public function generatePin(GeneratePinRequest $request): JsonResponse
    {
        try {
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
            // null = new pet; otherwise the pet the child will join.
            'pet_id' => $result['pet_id'],
        ], 200);
    }

    /**
     * Pair a child using a parent's PIN. New-pet PIN: creates the child's
     * pet — unborn (`born_at` null) until POST /api/child/contract. Join PIN
     * (M2-01): the child becomes a caretaker of the existing pet
     * (`joined_existing` true); the pet is not re-born, but this child must
     * sign their own contract (`awaiting_contract` true for them).
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
                'pet' => [
                    'id' => $pet->id,
                    'breed_type' => $pet->breed_type->value,
                    'hunger_level' => $pet->displayMetric('hunger_level'),
                    'thirst_level' => $pet->displayMetric('thirst_level'),
                    'energy_level' => $pet->displayMetric('energy_level'),
                    'hygiene_level' => $pet->displayMetric('hygiene_level'),
                    // null until the first contract is signed (M1-07b).
                    'born_at' => $pet->born_at?->toIso8601String(),
                    // This child must sign before acting (always true right
                    // after pairing: new pet unborn, or joined a shared pet).
                    'awaiting_contract' => $pet->isUnborn() || $pet->caretakerNeedsContract($child),
                    'is_active' => $pet->is_active,
                    'pet_dna' => [
                        'seed' => $pet->pet_dna['seed'] ?? null,
                        'prompt_anchor' => $pet->pet_dna['prompt_anchor'] ?? null,
                        'visual_traits' => $pet->pet_dna['visual_traits'] ?? null,
                        'reference_image_url' => $pet->pet_dna['reference_image_url'] ?? null,
                    ],
                    'current_video_url' => $pet->current_video_url,
                    'media_status' => $pet->media_status,
                ],
            ], 201);
        } catch (PairingException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
