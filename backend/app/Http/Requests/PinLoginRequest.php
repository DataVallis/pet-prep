<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/child/pin-login (M2-02) — unauthenticated.
 */
class PinLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $pin = $this->input('pin');
        if (is_string($pin)) {
            // "734 912" as shown on the parent's screen is fine.
            $this->merge(['pin' => preg_replace('/\s+/', '', $pin)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'device_name' => ['required', 'string', 'min:1', 'max:100'],
        ];
    }

    public function pin(): string
    {
        return (string) $this->validated('pin');
    }

    public function deviceName(): string
    {
        return (string) $this->validated('device_name');
    }
}
