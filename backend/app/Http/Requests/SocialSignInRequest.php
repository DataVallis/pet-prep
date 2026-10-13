<?php

namespace App\Http\Requests;

use App\Enums\SocialProvider;
use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Support\RequestLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/auth/social (M2-10c) — parent sign-in / sign-up with a Google or
 * Apple ID token from the native SDK. Public; throttled per IP
 * (`throttle:social`).
 */
class SocialSignInRequest extends FormRequest
{
    use NormalizesTimezoneInput;

    public const MAX_ID_TOKEN_LENGTH = 4096;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Device aliases (Etc/UTC, Asia/Calcutta …) → canonical name; unknown
        // but valid → default (logged), as at POST /api/register.
        $this->normalizeTimezoneInput('timezone', fallbackToDefault: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @example google */
            'provider' => ['required', 'string', Rule::in(SocialProvider::values())],
            // The provider's ID token (JWT) from the native sign-in SDK.
            'id_token' => ['required', 'string', 'max:'.self::MAX_ID_TOKEN_LENGTH],
            // Apple: the RAW nonce (the app passed its SHA-256 to Apple). Ignored for Google.
            'nonce' => ['required_if:provider,apple', 'nullable', 'string', 'max:255'],
            // Apple sends the name only on the first authorization (client side);
            // Google's name comes from the token and wins.
            'name' => ['nullable', 'string', 'max:'.RegisterParentRequest::MAX_NAME_LENGTH],
            // IANA identifier of the device (e.g. "Europe/Ljubljana").
            'timezone' => ['required', 'string', 'timezone:all'],
            // Must be true to create a new account (terms of use + privacy policy).
            'terms_accepted' => ['sometimes', 'nullable', 'boolean'],
            // Language of the app; accepted for forward compatibility, texts
            // follow Accept-Language.
            'locale' => ['nullable', 'string', Rule::in(RequestLocale::supported())],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::from((string) $this->validated('provider'));
    }

    public function idToken(): string
    {
        return (string) $this->validated('id_token');
    }

    public function rawNonce(): ?string
    {
        $nonce = $this->validated('nonce');

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    public function displayName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    public function familyTimezone(): string
    {
        return (string) $this->validated('timezone');
    }

    public function termsAccepted(): bool
    {
        return $this->boolean('terms_accepted');
    }

    public function deviceName(): string
    {
        $name = $this->validated('device_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'mobile-app';
    }
}
