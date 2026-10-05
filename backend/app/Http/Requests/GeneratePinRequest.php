<?php

namespace App\Http\Requests;

use App\Enums\BreedType;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Models\User;
use App\Services\Results\PetProfileChoice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // M5-R01: the new pet's profile (only for a new pet of a child
            // profile — not with pet_id, and 422 on the deprecated flow
            // without child_id, which creates a legacy-profile pet).
            // Defaults: mutt, bought, puppy. Premium breeds need the
            // purchase (422 breed_locked); every origin / age is free.
            'breed' => ['sometimes', 'nullable', Rule::enum(BreedType::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
            'origin' => ['sometimes', 'nullable', Rule::enum(PetOrigin::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
            'age_stage' => ['sometimes', 'nullable', Rule::enum(LifeStage::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
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

    /**
     * The new pet's profile (M5-R01); omitted fields → mutt / bought / puppy.
     */
    public function petProfile(): PetProfileChoice
    {
        return PetProfileChoice::fromArray([
            'breed' => $this->validated('breed'),
            'origin' => $this->validated('origin'),
            'age_stage' => $this->validated('age_stage'),
        ]);
    }
}
