<?php

namespace Database\Factories;

use App\Models\SummaryLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SummaryLead>
 */
class SummaryLeadFactory extends Factory
{
    protected $model = SummaryLead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => strtolower(fake()->unique()->safeEmail()),
            'consent_version' => SummaryLead::CONSENT_VERSIONS[0],
            'consented_at' => now(),
            'source' => 'real_cost',
            'locale' => fake()->randomElement(['sl', 'en', null]),
            'breed' => fake()->randomElement(['labrador_retriever', 'beagle', null]),
            'stage' => fake()->randomElement(['adult', 'puppy', null]),
            'visits' => 1,
            'last_seen_at' => now(),
        ];
    }
}
