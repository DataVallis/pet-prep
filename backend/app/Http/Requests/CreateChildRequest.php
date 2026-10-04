<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/children (M2-02). Minimal data about a minor: a nickname
 * (no surname needed) and an optional birth year — no e-mail, no birthdate.
 */
class CreateChildRequest extends FormRequest
{
    public const MAX_NAME_LENGTH = 30;

    /**
     * Parents only (UserPolicy::manageFamily) → 403 for a child.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manageFamily', User::class);
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('display_name');
        if (is_string($name)) {
            // Trim and collapse inner whitespace.
            $this->merge(['display_name' => trim((string) preg_replace('/\s+/u', ' ', $name))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $year = (int) now()->format('Y');

        return [
            // Letters (any script), digits, spaces, hyphen, apostrophe, dot.
            // No "@": a nickname, not an e-mail address.
            'display_name' => ['required', 'string', 'min:1', 'max:'.self::MAX_NAME_LENGTH, "regex:/^[\\pL\\pM\\pN][\\pL\\pM\\pN '\\-.]*$/u"],
            // Under 18 (a child profile); optional.
            'birth_year' => ['sometimes', 'nullable', 'integer', 'between:'.($year - 18).','.$year],
        ];
    }

    public function displayName(): string
    {
        return (string) $this->validated('display_name');
    }

    public function birthYear(): ?int
    {
        $year = $this->validated('birth_year');

        return $year === null ? null : (int) $year;
    }
}
