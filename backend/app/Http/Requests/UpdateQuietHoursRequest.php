<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQuietHoursRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled by Sanctum middleware + role check in controller
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_start' => ['nullable', 'string', 'date_format:H:i'],
            'school_end' => ['nullable', 'string', 'date_format:H:i'],
            'bedtime_start' => ['nullable', 'string', 'date_format:H:i'],
            'bedtime_end' => ['nullable', 'string', 'date_format:H:i'],
            'is_active' => ['boolean'],
            // Optional family timezone (IANA), saved on the parent (M1-03).
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_start.date_format' => 'School start must be in HH:MM format (e.g., 08:00).',
            'school_end.date_format' => 'School end must be in HH:MM format (e.g., 13:00).',
            'bedtime_start.date_format' => 'Bedtime start must be in HH:MM format (e.g., 22:00).',
            'bedtime_end.date_format' => 'Bedtime end must be in HH:MM format (e.g., 06:00).',
            'timezone.timezone' => 'Timezone must be a valid IANA name (e.g., Europe/Ljubljana).',
        ];
    }
}
