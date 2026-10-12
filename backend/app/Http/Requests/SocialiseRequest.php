<?php

namespace App\Http\Requests;

use App\Enums\SocialisationExperience;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/socialisation (M5-R17): which new experience the
 * child gave the puppy this week. Only children (PetPolicy); the controller
 * also checks `act` on the pet.
 */
class SocialiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('useChildApi', Pet::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** person (a new person) | dog (another dog) | sound (a new sound) | place (a new place) */
            'experience' => ['required', 'string', Rule::enum(SocialisationExperience::class)],
        ];
    }

    public function experience(): SocialisationExperience
    {
        return SocialisationExperience::from((string) $this->validated('experience'));
    }
}
