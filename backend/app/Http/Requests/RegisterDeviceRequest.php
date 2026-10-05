<?php

namespace App\Http\Requests;

use App\Enums\DevicePlatform;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/devices (M3-02): register this app install for pushes. Parent or
 * child token. Only the Expo token, the platform and the app version are
 * stored — no device name or model (child data minimisation).
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('managePushDevices', User::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expo_push_token' => ['required', 'string', 'max:255', 'regex:'.DevicePushToken::TOKEN_PATTERN],
            'platform' => ['required', 'string', Rule::enum(DevicePlatform::class)],
            // e.g. "1.4.0" or "1.4.0 (57)"; free text would invite PII.
            'app_version' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z.\-+() ]+$/'],
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from((string) $this->validated('platform'));
    }
}
