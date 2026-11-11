<?php

namespace Database\Factories;

use App\Enums\PartnerInquiryType;
use App\Models\PartnerInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerInquiry>
 */
class PartnerInquiryFactory extends Factory
{
    protected $model = PartnerInquiry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'type' => fake()->randomElement(PartnerInquiryType::cases())->value,
            'message' => fake()->paragraph(),
            'locale' => fake()->randomElement(['sl', 'en', null]),
        ];
    }
}
