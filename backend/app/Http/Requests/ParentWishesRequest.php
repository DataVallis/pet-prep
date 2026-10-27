<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/parent/wishes and POST /api/parent/wishes/{wish}/decline (M6-W01).
 * Parents only (UserPolicy::viewBilling); the wish's family is checked in the
 * controller (another family's wish looks like a missing one — 404). No body.
 */
class ParentWishesRequest extends FormRequest
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
