<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class JoinFamilyRequest extends FormRequest
{
    /**
     * Parents only (UserPolicy::manageFamily) → 403 for a child.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageFamily', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 8 characters; case and surrounding spaces are forgiven.
            'code' => ['required', 'string', 'min:6', 'max:16', 'regex:/^\s*[A-Za-z0-9]+\s*$/'],
        ];
    }
}
