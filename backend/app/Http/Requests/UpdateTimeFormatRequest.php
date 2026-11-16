<?php

namespace App\Http\Requests;

use App\Enums\TimeFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /api/user/time-format (David 2026-11-16): the signed-in person's own
 * 12 / 24 h choice — `auto` (follow the phone) | `24h` | `12h`. Any app
 * token (parent or child) sets only its own user; nothing else changes.
 */
class UpdateTimeFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'time_format' => ['required', 'string', Rule::enum(TimeFormat::class)],
        ];
    }

    public function timeFormat(): TimeFormat
    {
        return TimeFormat::from((string) $this->validated('time_format'));
    }
}
