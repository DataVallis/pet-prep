<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AcceptsAcquisitionSource;
use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateParentSettingsRequest extends FormRequest
{
    use AcceptsAcquisitionSource;
    use NormalizesTimezoneInput;

    /**
     * Device aliases (Etc/UTC, Asia/Calcutta, …) → canonical IANA name (PR #25);
     * an unknown acquisition source → null (VALIDATION_PLAN §4.1, never a 422).
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
            // IANA identifier, case-sensitive (e.g. "Europe/Ljubljana"). Always required on PUT; on
            // PATCH only when the body carries no acquisition field (VALIDATION_PLAN §4.1).
            'timezone' => [$this->isMethod('PATCH') && $this->sentAcquisition() ? 'sometimes' : 'required', 'string', 'timezone:all'],
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
