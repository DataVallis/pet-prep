<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/parent/pets/{pet}/growth (M5-R04). Parents only (UserPolicy
 * manageFamily → 403); whether the pet belongs to the parent's family is
 * checked in the controller (PetPolicy::manage) so another family's pet looks
 * exactly like a missing one (404).
 */
class ParentPetGrowthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
