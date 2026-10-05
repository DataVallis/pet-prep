<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTimezoneInput;
use App\Models\Family;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * POST /api/register (M2-10a) — parent self-registration with e-mail +
 * password. Public; throttled per IP (`throttle:register`).
 *
 * Password policy: `Password::defaults()` (AppServiceProvider) = at least
 * 10 characters with upper + lower case letters and a digit. No
 * `uncompromised()` check: it calls the external HIBP API, and the API makes
 * no external HTTP during sign-up (decision 2026-10-05, DECISIONS.md).
 */
class RegisterParentRequest extends FormRequest
{
    use NormalizesTimezoneInput;

    public const MAX_NAME_LENGTH = 60;

    /**
     * Machine-readable reason per field in the 422 body (`codes`), so the
     * app can show its own text without parsing English messages.
     */
    public const CODE_NAME_INVALID = 'name_invalid';

    public const CODE_EMAIL_INVALID = 'email_invalid';

    public const CODE_EMAIL_TAKEN = 'email_taken';

    public const CODE_PASSWORD_WEAK = 'password_weak';

    public const CODE_PASSWORD_MISMATCH = 'password_mismatch';

    public const CODE_TERMS_REQUIRED = 'terms_required';

    public const CODE_TIMEZONE_INVALID = 'timezone_invalid';

    public const CODE_DEVICE_NAME_INVALID = 'device_name_invalid';

    private bool $emailTaken = false;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $name = $this->input('name');
        if (is_string($name)) {
            // Trim and collapse inner whitespace.
            $merge['name'] = trim((string) preg_replace('/\s+/u', ' ', $name));
        }

        $email = $this->input('email');
        if (is_string($email)) {
            // E-mail addresses are compared case-insensitively (stored lower case).
            $merge['email'] = mb_strtolower(trim($email));
        }

        $timezone = $this->input('timezone');
        if ($timezone === null || (is_string($timezone) && trim($timezone) === '')) {
            $merge['timezone'] = Family::DEFAULT_TIMEZONE;
        }

        $this->merge($merge);

        // Device aliases (Etc/UTC, Asia/Calcutta, Europe/Kiev …) → canonical
        // name; unknown but valid → default (logged) instead of a 422.
        $this->normalizeTimezoneInput('timezone', fallbackToDefault: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Display name shown to the family (e.g. "Mama", "Ana"); any script.
            'name' => ['required', 'string', 'min:1', 'max:'.self::MAX_NAME_LENGTH],
            'email' => ['required', 'string', 'max:255', 'email:rfc'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
            // IANA identifier (e.g. "Europe/Ljubljana"); default when omitted.
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'accept_terms' => ['required', 'accepted'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Duplicate e-mail, case-insensitive (legacy rows may hold mixed case,
     * new rows are stored lower case). Telling a parent that the address is
     * taken is an accepted enumeration trade-off for UX (DECISIONS
     * 2026-10-05) — the endpoint is throttled per IP.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }
                if (User::emailTaken((string) $this->input('email'))) {
                    $this->emailTaken = true;
                    $validator->errors()->add('email', __('validation.unique', ['attribute' => 'email']));
                }
            },
        ];
    }

    /**
     * 422 = Laravel's validation body + `codes` {field: code}.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $failed = $validator->failed();
        $codes = [];

        foreach ($validator->errors()->keys() as $field) {
            $rules = array_keys($failed[$field] ?? []);
            $codes[$field] = match ($field) {
                'name' => self::CODE_NAME_INVALID,
                'email' => $this->emailTaken ? self::CODE_EMAIL_TAKEN : self::CODE_EMAIL_INVALID,
                'password' => $rules === ['Confirmed'] ? self::CODE_PASSWORD_MISMATCH : self::CODE_PASSWORD_WEAK,
                'accept_terms' => self::CODE_TERMS_REQUIRED,
                'timezone' => self::CODE_TIMEZONE_INVALID,
                'device_name' => self::CODE_DEVICE_NAME_INVALID,
                default => 'invalid',
            };
        }

        throw new HttpResponseException(self::errorResponse($validator->errors()->toArray(), $codes));
    }

    /**
     * The 422 body of POST /api/register (also used for a lost sign-up race).
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, string>  $codes
     */
    public static function errorResponse(array $errors, array $codes): JsonResponse
    {
        $first = collect($errors)->flatten()->first();

        return response()->json([
            'message' => is_string($first) ? $first : 'The given data was invalid.',
            'errors' => $errors,
            'codes' => $codes,
        ], 422);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'timezone.timezone' => 'Timezone must be a valid IANA name (e.g., Europe/Ljubljana).',
            'accept_terms.accepted' => 'You must accept the terms of use and the privacy policy.',
            'accept_terms.required' => 'You must accept the terms of use and the privacy policy.',
        ];
    }

    public function displayName(): string
    {
        return (string) $this->validated('name');
    }

    public function emailAddress(): string
    {
        return (string) $this->validated('email');
    }

    public function plainPassword(): string
    {
        return (string) $this->validated('password');
    }

    public function familyTimezone(): string
    {
        return (string) ($this->validated('timezone') ?? Family::DEFAULT_TIMEZONE);
    }

    public function deviceName(): string
    {
        $name = $this->validated('device_name');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'mobile-app';
    }
}
