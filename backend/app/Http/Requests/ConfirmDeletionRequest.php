<?php

namespace App\Http\Requests;

use App\Enums\SocialProvider;
use App\Models\User;
use App\Support\RequestLocale;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of every irreversible deletion (M2-08):
 * `POST /api/parent/account/delete` and `DELETE /api/parent/children/{child}`.
 *
 * `{password: the parent's current password, confirm: true, confirm_word?}`.
 * A parent WITHOUT a password (signed up with Google / Apple, M2-10c) sends
 * `{provider, id_token, nonce?, authorization_code?}` instead of `password`
 * (`authorization_code`: Apple only, reserved for token revocation, unused): a fresh ID token (≤ 10
 * min) of a provider linked to the account; wrong / stale / unlinked → 422
 * `reauth_required` (AccountDeletionService::confirmIdentity). For a parent
 * WITH a password the password stays required and the provider fields are
 * ignored.
 * The app makes the parent type the confirmation word of its language
 * ("IZBRIŠI" in Slovenian, "DELETE" in English — lang/<locale>/account.php)
 * before it sends the request. `confirm_word` is optional (app builds before
 * M1-18 don't send it), but once the key is present it must match — an empty,
 * whitespace-only or null value is refused. The word of **any** supported
 * language is accepted, with the app's normalisation (Unicode NFC, surrounding
 * spaces and letter case forgiven, "Š" required). A wrong password is checked in
 * AccountDeletionService (422 `invalid_password`). Error texts follow the
 * request language (M1-18).
 */
class ConfirmDeletionRequest extends FormRequest
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
            // Required for a parent who has a password (checked in after()).
            'password' => ['nullable', 'string', 'max:255'],
            // M2-10c — parents without a password: re-authenticate with a linked provider.
            'provider' => ['required_with:id_token', 'nullable', 'string', Rule::in(SocialProvider::values())],
            // Fresh ID token (issued ≤ 10 minutes ago) from the provider's native SDK.
            'id_token' => ['nullable', 'string', 'max:'.SocialSignInRequest::MAX_ID_TOKEN_LENGTH],
            // Apple: the RAW nonce of that sign-in.
            'nonce' => ['nullable', 'string', 'max:255'],
            // Apple only: the sign-in's authorization code. RESERVED for revoking
            // the Apple token on account deletion (needs David's Apple .p8 key) —
            // accepted and validated, not used yet; never logged, never stored.
            'authorization_code' => ['nullable', 'string', 'max:2048', 'prohibited_unless:provider,apple'],
            'confirm' => ['required', 'accepted'],
            // M3-11 P5: the parent saw that a paid, unfinished 12-week challenge
            // is lost with the pet (the purchase stays used). Without it such a
            // deletion → 422 `paid_challenge_ack_required`.
            'acknowledge_paid_challenge' => ['sometimes', 'boolean'],
            // `required` under `sometimes`: present ⇒ non-empty (TrimStrings +
            // ConvertEmptyStringsToNull turn "  " into null, which must fail).
            'confirm_word' => ['sometimes', 'required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! self::isConfirmWord($value)) {
                    $fail(__('account.deletion.confirm_word_invalid', ['word' => __('account.deletion.confirm_word')]));
                }
            }],
        ];
    }

    /**
     * `password` is required unless the parent has no password and
     * re-authenticates with a provider token instead (M2-10c).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('password') || $this->filled('password')) {
                    return;
                }
                $user = $this->user();
                $socialOnly = $user instanceof User && ! $user->hasPassword();
                if ($socialOnly && $this->filled('id_token')) {
                    return;
                }
                $validator->errors()->add('password', __('account.deletion.password_required'));
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => __('account.deletion.password_required'),
            'confirm.required' => __('account.deletion.confirm_required'),
            'confirm.accepted' => __('account.deletion.confirm_required'),
            'confirm_word.required' => $this->confirmWordInvalid(),
            'confirm_word.string' => $this->confirmWordInvalid(),
            'confirm_word.max' => $this->confirmWordInvalid(),
        ];
    }

    private function confirmWordInvalid(): string
    {
        return __('account.deletion.confirm_word_invalid', ['word' => __('account.deletion.confirm_word')]);
    }

    /** True for the confirmation word of any supported language (NFC, trimmed, any letter case). */
    public static function isConfirmWord(string $text): bool
    {
        $typed = self::normalise($text);
        if ($typed === '') {
            return false;
        }

        foreach (RequestLocale::supported() as $locale) {
            $word = trans('account.deletion.confirm_word', [], $locale);
            if (is_string($word) && $word !== 'account.deletion.confirm_word' && $typed === self::normalise($word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * NFC first (a decomposed "S + combining caron" from some keyboards must
     * equal "Š"), then trim + upper case like the app. Without ext-intl the
     * text is compared as sent.
     */
    private static function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = is_string($nfc) ? $nfc : $text;
        }

        return mb_strtoupper(trim($text), 'UTF-8');
    }

    public function password(): ?string
    {
        $password = $this->input('password');

        return is_string($password) ? $password : null;
    }

    /** M2-10c re-authentication provider (null when not sent). */
    public function provider(): ?SocialProvider
    {
        $provider = $this->input('provider');

        return is_string($provider) ? SocialProvider::tryFrom($provider) : null;
    }

    public function idToken(): ?string
    {
        $token = $this->input('id_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function nonce(): ?string
    {
        $nonce = $this->input('nonce');

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /** M3-11 P5: losing a paid, unfinished challenge was acknowledged. */
    public function acknowledgesPaidChallenge(): bool
    {
        return $this->boolean('acknowledge_paid_challenge');
    }
}
