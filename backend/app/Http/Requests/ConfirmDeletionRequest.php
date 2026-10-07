<?php

namespace App\Http\Requests;

use App\Support\RequestLocale;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of every irreversible deletion (M2-08):
 * `POST /api/parent/account/delete` and `DELETE /api/parent/children/{child}`.
 *
 * `{password: the parent's current password, confirm: true, confirm_word?}`.
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
            'password' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
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

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
