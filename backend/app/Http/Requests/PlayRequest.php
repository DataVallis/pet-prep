<?php

namespace App\Http\Requests;

use App\Enums\PlayKind;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/child/pet/play (M5-R05): which mini-game the child finished.
 * Only children (PetPolicy); the controller also checks `act` on the pet.
 */
class PlayRequest extends FormRequest
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
            /** play (ball game) | cuddle */
            'kind' => ['required', 'string', Rule::enum(PlayKind::class)],
        ];
    }

    public function kind(): PlayKind
    {
        return PlayKind::from((string) $this->validated('kind'));
    }
}
