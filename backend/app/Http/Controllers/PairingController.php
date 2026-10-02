<?php

namespace App\Http\Controllers;

use App\Exceptions\PairingException;
use App\Http\Requests\GeneratePinRequest;
use App\Http\Requests\PairChildRequest;
use App\Models\Pet;
use App\Services\PairingService;
use Illuminate\Http\JsonResponse;

class PairingController extends Controller
{
    public function __construct(
        private readonly PairingService $pairingService,
    ) {}

    /**
     * Generate a 6-digit pairing PIN for the authenticated parent.
     *
     * POST /api/parent/generate-pin
     */
    public function generatePin(GeneratePinRequest $request): JsonResponse
    {
        $parent = $request->user();

        if (! $parent->isParent()) {
            return response()->json([
                'message' => 'Only parent profiles can generate pairing PINs.',
            ], 403);
        }

        $result = $this->pairingService->generatePin($parent);

        return response()->json([
            'pin' => $result['pin'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'expires_in_minutes' => PairingService::PIN_EXPIRY_MINUTES,
        ], 200);
    }

    /**
     * Pair a child to a parent using a 6-digit PIN.
     * Initializes the child's pet session atomically.
     *
     * POST /api/child/pair
     */
    public function pairChild(PairChildRequest $request): JsonResponse
    {
        $child = $request->user();

        try {
            $result = $this->pairingService->pairChild($request->input('pin'), $child);

            /** @var Pet $pet */
            $pet = $result['pet'];

            return response()->json([
                'message' => 'Pairing successful. Pet session initialized.',
                'parent_id' => $result['parent']->id,
                'pet' => [
                    'id' => $pet->id,
                    'breed_type' => $pet->breed_type->value,
                    'hunger_level' => $pet->hunger_level,
                    'energy_level' => $pet->energy_level,
                    'hygiene_level' => $pet->hygiene_level,
                    'born_at' => $pet->born_at->toIso8601String(),
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
