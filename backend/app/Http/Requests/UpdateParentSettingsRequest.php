<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateParentSettingsRequest extends FormRequest
{
    use NormalizesTimezoneInput;

    /**
     * Device aliases (Etc/UTC, Asia/Calcutta, …) → canonical IANA name (PR #25).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTimezoneInput();
    }

    /**
     * Only the parent profile may change family settings (UserPolicy).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('updateFamilySettings', User::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // IANA identifier, case-sensitive (e.g. "Europe/Ljubljana").
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Timezone must be a valid IANA name (e.g., Europe/Ljubljana).',
        ];
    }
}
