<?php

namespace Database\Factories;

use App\Models\EmailContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailContact>
 */
class EmailContactFactory extends Factory
{
    protected $model = EmailContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => strtolower(fake()->unique()->safeEmail()),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'signups_count' => 1,
            'consent_version' => EmailContact::CONSENT_VERSIONS[0],
            'consented_at' => now(),
        ];
    }
}
