<?php

namespace App\Http\Requests;

use App\Enums\ClientFeature;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/parent/children/{child}/device-session (M2-11) — the parent opens
 * the child's profile on this phone. Same optional fields as a PIN login.
 */
class ChildDeviceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Phone model only (never the user-given name); stored through
            // ChildProfileService::deviceLabel() like at PIN login.
            'device_name' => ['nullable', 'string', 'max:255'],
            // What this app build can show (as at PIN login), e.g. ["behaviour_events",
            // "training", "species_cat"]; ≤ 10 strings, unknown values ignored. A cat
            // needs `species_cat` (else 422 app_update_required).
            'features' => ['sometimes', 'nullable', 'array', 'max:10'],
            'features.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return ClientFeature::known($this->validated('features') ?? []);
    }

    public function deviceName(): string
    {
        return (string) ($this->validated('device_name') ?? '');
    }
}
