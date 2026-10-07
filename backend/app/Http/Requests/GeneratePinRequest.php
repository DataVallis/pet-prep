<?php

namespace App\Http\Requests;

use App\Enums\BreedType;
use App\Enums\ClientFeature;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
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
            // without child_id). All or nothing: NONE of the three → no
            // profile → a legacy-profile pet on the pre-M5 rules (old app
            // builds); ANY of them → origin AND age_stage are required,
            // breed defaults to the mutt. Premium breeds need the purchase
            // (422 breed_locked); every origin / age is free.
            'breed' => ['sometimes', 'nullable', Rule::enum(BreedType::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
            'origin' => ['nullable', 'required_with:breed,age_stage', Rule::enum(PetOrigin::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
            'age_stage' => ['nullable', 'required_with:breed,origin', Rule::enum(LifeStage::class), 'prohibited_unless:pet_id,null', 'prohibited_if:child_id,null'],
            // M5-R02 (PR #42 B1): what this app build can show for the new pet,
            // e.g. ["behaviour_events", "training"]. An array of ≤ 10 strings; values this
            // server doesn't know (newer apps) are dropped, not refused
            // (ClientFeature::known). Stored with the profile; the pet gets a
            // feature only if the child's device declares it too at pin-login.
            // Ignored without a profile (legacy pet) and when joining a pet.
            'features' => ['sometimes', 'nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:64'],
            // M3-11 (PAYMENTS_SPEC): plan of the new pet — `free` (mutt only,
            // 422 breed_locked for a premium breed) or `challenge` (7-day trial
            // from birth, paid breed only — M5-F03). Omitted (old app builds) →
            // `challenge` for a paid breed, `free` for the mutt (P4). Ignored when
            // joining a pet / re-login.
            'plan' => ['sometimes', 'nullable', Rule::enum(PetPlan::class)],
        ];
    }

    public function plan(): PetPlan
    {
        $plan = $this->validated('plan');

        return $plan === null ? PetPlan::Challenge : PetPlan::from((string) $plan);
    }

    /**
     * M5-F03: the parent chose a plan explicitly (new app builds). Only then
     * is `challenge` + mutt refused (422); an omitted plan picks the default
     * per breed (PairingService::defaultPlanFor — the mutt is free).
     */
    public function planChosen(): bool
    {
        return $this->validated('plan') !== null;
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
     * The new pet's profile (M5-R01), or null when none of breed / origin /
     * age_stage was sent (→ legacy-profile pet, pre-M5 rules). Validation
     * guarantees origin + age_stage when any field is sent; breed → mutt.
     */
    public function petProfile(): ?PetProfileChoice
    {
        if ($this->validated('origin') === null && $this->validated('age_stage') === null && $this->validated('breed') === null) {
            return null;
        }

        return PetProfileChoice::fromArray([
            'breed' => $this->validated('breed'),
            'origin' => $this->validated('origin'),
            'age_stage' => $this->validated('age_stage'),
            'features' => ClientFeature::known($this->validated('features') ?? []),
        ]);
    }
}
