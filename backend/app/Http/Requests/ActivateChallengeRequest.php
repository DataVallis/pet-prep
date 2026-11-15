<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/pets/{pet}/challenge/activate (M3-11). Parents only
 * (UserPolicy::viewBilling → 403 for a child); whether the pet belongs to the
 * parent's family is checked in the controller (PetPolicy::manage) so another
 * family's pet looks exactly like a missing one (404). Optional body
 * `keep_score` (M6-U03).
 */
class ActivateChallengeRequest extends FormRequest
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
        return [
            // M6-U03 (U7): upgrade of a free pet — keep the Care Score so far (true) or start
            // again at the purchase (false / absent). Ignored for a pet that is not free.
            'keep_score' => ['sometimes', 'boolean'],
        ];
    }
}
