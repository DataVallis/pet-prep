<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePinRequest extends FormRequest
{
    /**
     * Parents only (UserPolicy::manageFamily) → 403 for a child.
     */
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null && $user->can('manageFamily', User::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // M2-02: the child profile the PIN is for (POST /api/child/pin-login).
            // Omitted = deprecated flow (the child is already signed in with
            // e-mail and calls POST /api/child/pair).
            'child_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // Family model (M2-01): omit for a new pet; an existing pet of
            // the family = the child will share it (shared custody).
            'pet_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function childId(): ?int
    {
        $childId = $this->validated('child_id');

        return $childId === null ? null : (int) $childId;
    }

    public function joinPetId(): ?int
    {
        $petId = $this->validated('pet_id');

        return $petId === null ? null : (int) $petId;
    }
}
