<?php

namespace Database\Factories;

use App\Enums\BreedType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pet>
 */
class PetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'breed_type' => BreedType::Mutt->value,
            'pet_dna' => null,
            'current_video_url' => null,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'daily_step_count' => 0,
            'last_step_reset_at' => null,
            'born_at' => now(),
            'is_active' => true,
            'pet_state' => 'idle',
            'illness_until' => null,
            'escalation_level' => 0,
            'hunger_zero_since' => null,
            'thirst_zero_since' => null,
            'energy_zero_since' => null,
            'hygiene_zero_since' => null,
            'is_game_over' => false,
            'is_hard_stopped' => false,
            'certificate_eligible' => false,
        ];
    }

    /**
     * Indicate the pet is a Mutt (free tier).
     */
    public function mutt(): static
    {
        return $this->state(fn (array $attributes) => [
            'breed_type' => BreedType::Mutt->value,
        ]);
    }

    /**
     * Indicate the pet is a Border Collie (premium tier).
     */
    public function borderCollie(): static
    {
        return $this->state(fn (array $attributes) => [
            'breed_type' => BreedType::BorderCollie->value,
        ]);
    }

    /**
     * Provide a complete pet_dna payload (for testing).
     */
    public function withPetDna(array $dna = []): static
    {
        $defaultDna = [
            'seed' => random_int(1, 4294967295),
            'visual_traits' => [
                'color_scheme' => 'golden brown with white patches',
                'eye_color' => 'amber',
                'fur_texture' => 'short and smooth',
                'markings' => 'white blaze on chest',
            ],
            'prompt_anchor' => 'A friendly golden brown mutt dog with white chest patches and amber eyes, short smooth fur, photorealistic',
            'reference_image_url' => null,
        ];

        return $this->state(fn (array $attributes) => [
            'pet_dna' => array_merge($defaultDna, $dna),
        ]);
    }
}
