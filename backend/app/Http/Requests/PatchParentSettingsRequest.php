<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsAcquisitionSource;
use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/parent/settings — a partial body (VALIDATION_PLAN §4.1, David 2026-11-21): the family
 * timezone and / or the one-time »Kje ste izvedeli za PetPrep?« answer. PUT keeps
 * UpdateParentSettingsRequest (timezone only, required).
 */
class PatchParentSettingsRequest extends FormRequest
{
    use AcceptsAcquisitionSource;
    use NormalizesTimezoneInput;

    /**
     * Device aliases → canonical IANA name (PR #25); an unknown acquisition source → null (never a 422).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTimezoneInput();
        $this->prepareAcquisitionInput();
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
            // IANA identifier, case-sensitive (e.g. "Europe/Ljubljana"). Optional; required only
            // when the body carries no acquisition field.
            'timezone' => [$this->sentAcquisition() ? 'sometimes' : 'required', 'string', 'timezone:all'],
            ...$this->acquisitionRules(),
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
