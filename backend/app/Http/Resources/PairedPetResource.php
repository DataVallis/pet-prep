<?php

namespace App\Http\Resources;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pet returned when a child pairs or signs in with a PIN
 * (POST /api/child/pair, POST /api/child/pin-login). Pet state only — no
 * personal data. `awaiting_contract` is evaluated for this child.
 *
 * @property Pet $resource
 */
class PairedPetResource extends JsonResource
{
    public function __construct(Pet $pet, private readonly User $child)
    {
        parent::__construct($pet);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pet = $this->resource;

        return [
            'id' => $pet->id,
            'breed_type' => $pet->breed_type->value,
            'hunger_level' => $pet->displayMetric('hunger_level'),
            'thirst_level' => $pet->displayMetric('thirst_level'),
            'energy_level' => $pet->displayMetric('energy_level'),
            'hygiene_level' => $pet->displayMetric('hygiene_level'),
            // null until the first contract is signed (M1-07b).
            'born_at' => $pet->born_at?->toIso8601String(),
            // This child must sign before acting (new unborn pet, or joined a
            // shared pet and has not signed yet).
            'awaiting_contract' => (bool) ($pet->isUnborn() || $pet->caretakerNeedsContract($this->child)),
            'is_active' => (bool) $pet->is_active,
            'is_game_over' => (bool) $pet->is_game_over,
            'pet_dna' => [
                'seed' => $pet->pet_dna['seed'] ?? null,
                'prompt_anchor' => $pet->pet_dna['prompt_anchor'] ?? null,
                'visual_traits' => $pet->pet_dna['visual_traits'] ?? null,
                'reference_image_url' => $pet->pet_dna['reference_image_url'] ?? null,
            ],
            'current_video_url' => $pet->current_video_url,
            'media_status' => $pet->media_status,
        ];
    }
}
