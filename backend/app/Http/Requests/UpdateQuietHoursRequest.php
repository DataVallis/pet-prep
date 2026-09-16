<?php

namespace App\Http\Requests;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_start' => ['nullable', 'string', 'date_format:H:i'],
            'school_end' => ['nullable', 'string', 'date_format:H:i'],
            'bedtime_start' => ['nullable', 'string', 'date_format:H:i'],
            'bedtime_end' => ['nullable', 'string', 'date_format:H:i'],
            'is_active' => ['boolean'],
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
        ];
    }
}
