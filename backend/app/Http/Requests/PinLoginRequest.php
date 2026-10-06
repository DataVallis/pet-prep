<?php

namespace App\Http\Requests;

use App\Enums\ClientFeature;
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
            // M5-R02 (PR #42): what this child app build can show, e.g.
            // ["behaviour_events"]. ≤ 10 strings; unknown values are ignored.
            // Only matters when this login creates a new pet.
            'features' => ['sometimes', 'nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:64'],
        ];
    }

    /**
     * Known client features of this device (unknown values dropped).
     *
     * @return list<string>
     */
    public function features(): array
    {
        return ClientFeature::known($this->validated('features') ?? []);
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
