<?php

namespace App\Http\Requests;

use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/parent/pets/{pet}/quiet-hours (David 2026-11-16, quiet hours per
 * pet). Body `{"quiet_hours": {"school_start": "08:00", "school_end":
 * "13:00", "bedtime_start": "20:00", "bedtime_end": "07:00", "is_active":
 * true}}` = the pet's own schedule (times "HH:MM" or null, the same rules as
 * the family's PUT /api/parent/quiet-hours); `{"quiet_hours": null}` = follow
 * the family's quiet hours again. Authorized by PetPolicy::changeQuietHours
 * (a parent of the pet's family; another family's pet or a child → 403).
 */
class UpdatePetQuietHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pet = $this->route('pet');

        return $pet instanceof Pet && ($this->user()?->can('changeQuietHours', $pet) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The pet's own schedule; null = follow the family's quiet hours.
            'quiet_hours' => ['present', 'nullable', 'array'],
            'quiet_hours.school_start' => ['nullable', 'string', 'date_format:H:i'],
            'quiet_hours.school_end' => ['nullable', 'string', 'date_format:H:i'],
            'quiet_hours.bedtime_start' => ['nullable', 'string', 'date_format:H:i'],
            'quiet_hours.bedtime_end' => ['nullable', 'string', 'date_format:H:i'],
            'quiet_hours.is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The pet's own schedule, or null = follow the family.
     *
     * @return array{school_start?: string|null, school_end?: string|null, bedtime_start?: string|null, bedtime_end?: string|null, is_active?: bool}|null
     */
    public function petQuietHours(): ?array
    {
        $value = $this->validated('quiet_hours');

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quiet_hours.school_start.date_format' => 'School start must be in HH:MM format (e.g., 08:00).',
            'quiet_hours.school_end.date_format' => 'School end must be in HH:MM format (e.g., 13:00).',
            'quiet_hours.bedtime_start.date_format' => 'Bedtime start must be in HH:MM format (e.g., 22:00).',
            'quiet_hours.bedtime_end.date_format' => 'Bedtime end must be in HH:MM format (e.g., 06:00).',
        ];
    }
}
