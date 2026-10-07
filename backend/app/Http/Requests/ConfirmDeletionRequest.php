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
 * M1-18 don't send it); when sent, the word of **any** supported language is
 * accepted, with the app's normalisation (surrounding spaces and letter case
 * forgiven, "Š" required). A wrong password is checked in
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
            'confirm_word' => ['sometimes', 'nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
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
        ];
    }

    /** True for the confirmation word of any supported language (trimmed, any letter case). */
    public static function isConfirmWord(string $text): bool
    {
        $typed = mb_strtoupper(trim($text), 'UTF-8');
        if ($typed === '') {
            return false;
        }

        foreach (RequestLocale::supported() as $locale) {
            $word = trans('account.deletion.confirm_word', [], $locale);
            if (is_string($word) && $word !== 'account.deletion.confirm_word' && $typed === mb_strtoupper($word, 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }
}
