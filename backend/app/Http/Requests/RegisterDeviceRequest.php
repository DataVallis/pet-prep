<?php

namespace App\Http\Requests;

use App\Enums\DevicePlatform;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Support\RequestLocale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/devices (M3-02): register this app install for pushes. Parent or
 * child token. Only the Expo token, the platform, the app version and the
 * app language (`locale`, M1-18) are stored — no device name or model (child data minimisation).
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
            // M1-18: the app's language for pushes (en | sl, config/locales.php).
            // Never derived from Accept-Language: iOS adds that header on its own.
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(RequestLocale::supported())],
        ];
    }

    /**
     * Push language of this install (M1-18): only the explicit body field
     * `locale`. Null when not sent → the stored language is kept (a new row
     * gets `locales.unstated_device`). `Accept-Language` is NOT used here:
     * iOS sends one implicitly (e.g. `en-US,en;q=0.9` for builds without
     * declared localisations), which would flip Slovenian installs to English.
     */
    public function pushLocale(): ?string
    {
        $locale = $this->validated('locale');

        return is_string($locale) ? $locale : null;
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from((string) $this->validated('platform'));
    }
}
