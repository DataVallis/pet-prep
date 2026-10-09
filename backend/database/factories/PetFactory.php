<?php

namespace Database\Factories;

use App\Enums\BreedType;
use App\Models\Pet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Pet>
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
            // M3-11: like the pets that existed before payments — a challenge,
            // paid as `grandfathered`, so time travel in older tests never hits
            // the trial lock. Use ->trial() / ->freePlan() for payment rules.
            'plan' => 'challenge',
            'challenge_paid_at' => now(),
            'challenge_paid_source' => 'grandfathered',
        ];
    }

    /**
     * M3-11 / M3-13: an unpaid challenge pet. Since M3-13 there is no free
     * trial: a born pet gets trial_ends_at = born_at (payment_required; the
     * tick locks it), an unborn one is locked at the contract. Use
     * ->legacyTrial() for a pet that still runs a pre-M3-13 7-day trial.
     */
    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'challenge',
            'challenge_paid_at' => null,
            'challenge_paid_source' => null,
        ]);
    }

    /**
     * A pet born before M3-13 whose 7-day free trial is still recorded:
     * trial_ends_at = birth + 7 family-local days (Pet::trialEndFor). Born
     * pets only (an unborn pet never gets a trial any more). An explicit
     * `trial_ends_at` other than the birth is kept.
     */
    public function legacyTrial(): static
    {
        return $this->trial()->afterCreating(function (Pet $pet): void {
            if ($pet->born_at === null
                || ($pet->trial_ends_at !== null && ! $pet->trial_ends_at->equalTo($pet->born_at))) {
                return;
            }
            DB::table('pets')->where('id', $pet->id)->update(['trial_ends_at' => $pet->trialEndFor($pet->born_at)]);
            $pet->refresh();
        });
    }

    /**
     * M5-F02: an UNPAID mutt challenge as it could exist before M5-F03 (data
     * the 2026_10_21 migration converts). Pet::creating no longer lets such a
     * pet be created, so the row is rewritten without model hooks.
     */
    public function legacyUnpaidMuttChallenge(): static
    {
        return $this->mutt()->trial()->afterCreating(function (Pet $pet): void {
            DB::table('pets')->where('id', $pet->id)->update([
                'plan' => 'challenge',
                'trial_ends_at' => $pet->born_at !== null ? $pet->trialEndFor($pet->born_at) : null,
            ]);
            $pet->refresh();
        });
    }

    /**
     * M3-11: a challenge paid by a store purchase (full AI media tier, P6).
     */
    public function purchased(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'challenge',
            'challenge_paid_at' => now(),
            'challenge_paid_source' => 'purchase',
        ]);
    }

    /**
     * M3-11: the free mutt sandbox (no trial, no payment).
     */
    public function freePlan(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => 'free',
            'breed_type' => BreedType::Mutt->value,
            'challenge_paid_at' => null,
            'challenge_paid_source' => null,
            'trial_ends_at' => null,
        ]);
    }

    /**
     * A pet created at pairing that waits for the contract (M1-07b):
     * born_at null, no clocks yet.
     */
    public function unborn(): static
    {
        return $this->state(fn (array $attributes) => [
            'born_at' => null,
            'last_decay_at' => null,
            'last_step_reset_at' => null,
        ]);
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
     * Indicate the pet is a Labrador Retriever (paid breed, M5-R10).
     */
    public function labradorRetriever(): static
    {
        return $this->state(fn (array $attributes) => [
            'breed_type' => BreedType::LabradorRetriever->value,
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
