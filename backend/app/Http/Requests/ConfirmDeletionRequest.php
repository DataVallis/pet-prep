<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of every irreversible deletion (M2-08):
 * `POST /api/parent/account/delete` and `DELETE /api/parent/children/{child}`.
 *
 * `{password: the parent's current password, confirm: true}`. The app also
 * makes the parent type "IZBRIŠI" before it sends the request; the server
 * requires the password re-entry + the explicit `confirm` flag. A wrong
 * password is checked in AccountDeletionService (422 `invalid_password`).
 */
class ConfirmDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
