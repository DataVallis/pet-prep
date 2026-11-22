<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSchoolDays;
use App\Models\Pet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/parent/pets/{pet}/quiet-hours (David 2026-11-16, quiet hours per
 * pet). Body `{"quiet_hours": {"school_start": "08:00", "school_end":
 * "13:00", "bedtime_start": "20:00", "bedtime_end": "07:00", "is_active":
 * true, "school_days": [1, 2, 3, 4, 5]}}` = the pet's own schedule (times
 * "HH:MM" or null, the same rules as the family's PUT /api/parent/quiet-hours;
 * `school_days` optional — missing keeps the pet's stored days, a pet that
 * followed the family gets Monday–Friday; invalid → 422
 * `school_days_invalid`); `{"quiet_hours": null}` = follow
 * the family's quiet hours again. Authorized by PetPolicy::changeQuietHours
 * (a parent of the pet's family; another family's pet or a child → 403).
 */
class UpdatePetQuietHoursRequest extends FormRequest
{
    use ValidatesSchoolDays;

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
        // QA #1259 M5: an object must carry the WHOLE schedule — a partial object or `{}`
        // would otherwise silently wipe windows (missing → null) or switch them on. Every
        // key is required to be present (times may be null = no window, as in the family
        // editor); skipped entirely when `quiet_hours` is null (follow the family).
        $notObject = Rule::excludeIf(fn (): bool => ! is_array($this->input('quiet_hours')));

        return [
            // The pet's own schedule; null = follow the family's quiet hours.
            'quiet_hours' => ['present', 'nullable', 'array'],
            'quiet_hours.school_start' => [$notObject, 'present', 'nullable', 'string', 'date_format:H:i'],
            'quiet_hours.school_end' => [$notObject, 'present', 'nullable', 'string', 'date_format:H:i'],
            'quiet_hours.bedtime_start' => [$notObject, 'present', 'nullable', 'string', 'date_format:H:i'],
            'quiet_hours.bedtime_end' => [$notObject, 'present', 'nullable', 'string', 'date_format:H:i'],
            'quiet_hours.is_active' => [$notObject, 'required', 'boolean'],
            // David 2026-11-22: optional (old apps never send it — keep the stored days).
            'quiet_hours.school_days' => [$notObject, ...$this->schoolDaysRules('quiet_hours.school_days')['quiet_hours.school_days']],
            'quiet_hours.school_days.*' => [$notObject, ...$this->schoolDaysRules('quiet_hours.school_days')['quiet_hours.school_days.*']],
        ];
    }

    /**
     * The pet's own schedule, or null = follow the family.
     *
     * `school_days` is present only when the app sent it.
     *
     * @return array{school_start: string|null, school_end: string|null, bedtime_start: string|null, bedtime_end: string|null, is_active: bool, school_days?: list<int>}|null
     */
    public function petQuietHours(): ?array
    {
        $value = $this->validated('quiet_hours');

        return is_array($value) ? $value : null;
    }

    /**
     * Invalid `quiet_hours.school_days` → 422 `reason: school_days_invalid`.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $this->failOnSchoolDays($validator, 'quiet_hours.school_days');

        parent::failedValidation($validator);
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
