<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/pets/{pet}/challenge/intent (M6-U01, FREE_PET_UPGRADE_SPEC
 * §5.3). Parents only (UserPolicy::viewBilling → 403 for a child); the pet is
 * authorized in the controller with PetPolicy::buyChallenge so another
 * family's pet looks exactly like a missing one (404). No body.
 */
class ChallengeIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewBilling', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
