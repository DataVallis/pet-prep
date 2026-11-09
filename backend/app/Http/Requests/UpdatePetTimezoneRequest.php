<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/parent/pets/{pet}/timezone (David 2026-11-09, time zone per pet).
 * Body `{"timezone": "Europe/Lisbon"}` = the pet's own zone; `{"timezone":
 * null}` = follow the family zone again. Authorized by
 * PetPolicy::changeTimezone (a parent of the pet's family; another family's
 * pet or a child → 403).
 */
class UpdatePetTimezoneRequest extends FormRequest
{
    use NormalizesTimezoneInput;

    /**
     * Device aliases (Etc/UTC, Asia/Calcutta, …) → canonical IANA name (PR #25).
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTimezoneInput();
    }

    public function authorize(): bool
    {
        $pet = $this->route('pet');

        return $pet instanceof Pet && ($this->user()?->can('changeTimezone', $pet) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // IANA identifier (e.g. "Europe/Lisbon"); null = follow the family zone.
            'timezone' => ['present', 'nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * The pet's own zone, or null = follow the family zone.
     */
    public function petTimezone(): ?string
    {
        $value = $this->validated('timezone');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Timezone must be a valid IANA name (e.g., Europe/Lisbon) or null.',
        ];
    }
}
