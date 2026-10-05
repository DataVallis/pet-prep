<?php

namespace App\Http\Requests;

use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/devices/unregister (M3-02, PR #35 review): stop pushes to this
 * install. The token travels in the body (not the URL, so it never lands in
 * access logs).
 */
class UnregisterDeviceRequest extends FormRequest
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
        ];
    }
}
