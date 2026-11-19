<?php

namespace Database\Factories;

use App\Models\EmailContact;
use App\Models\EmailSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSignup>
 */
class EmailSignupFactory extends Factory
{
    protected $model = EmailSignup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_contact_id' => EmailContact::factory(),
            'purpose' => 'newsletter',
            'source' => 'real_cost',
            'page_path' => '/sl/koliko-dela-je-z-labradorcem',
            'locale' => 'sl',
            'breed' => 'labrador_retriever',
            'stage' => 'adult',
            'consent_version' => EmailContact::CONSENT_VERSIONS[0],
            'created_at' => now(),
        ];
    }
}
