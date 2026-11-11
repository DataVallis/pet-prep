<?php

namespace App\Http\Requests;

use App\Enums\SocialProvider;
use App\Exceptions\WebSessionException;
use App\Support\WebsiteLocale;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/web/parent-session (M6-S01b, CONTRACT_WEB_SIGNIN §1.1). Public;
 * `throttle:web-session`. Every other body field (`abilities`, `device_name`,
 * `name`, `terms_accepted`, `timezone` …) is ignored — nothing from the request
 * shapes the token. A failure answers 422 `invalid_request` without echoing
 * field names or values.
 */
class WebParentSessionRequest extends FormRequest
{
    public const METHODS = ['password', 'google', 'apple'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => trim($email)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @example password */
            'method' => ['required', 'string', Rule::in(self::METHODS)],
            // password: the parent's app account e-mail + password.
            'email' => ['exclude_unless:method,password', 'required', 'string', 'max:254', 'email:rfc'],
            'password' => ['exclude_unless:method,password', 'required', 'string', 'max:255'],
            // google / apple: the provider's ID token from the redirect (website client id only)
            // and the RAW nonce whose SHA-256 hex the website passed to the provider.
            'id_token' => ['exclude_if:method,password', 'required', 'string', 'max:'.SocialSignInRequest::MAX_ID_TOKEN_LENGTH],
            'nonce' => ['exclude_if:method,password', 'required', 'string', 'min:16', 'max:255'],
            // Language of `message` only (sl / en; the other website languages get English).
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(WebsiteLocale::ALL)],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        WebsiteLocale::apply($this->input('locale'));

        throw new HttpResponseException(response()->json([
            'error' => WebSessionException::INVALID_REQUEST,
            'message' => __('web_session.'.WebSessionException::INVALID_REQUEST),
        ], 422));
    }

    public function signInMethod(): string
    {
        return (string) $this->validated('method');
    }

    public function provider(): SocialProvider
    {
        return SocialProvider::from($this->signInMethod());
    }

    public function emailAddress(): string
    {
        return (string) $this->validated('email');
    }

    public function plainPassword(): string
    {
        return (string) $this->validated('password');
    }

    public function idToken(): string
    {
        return (string) $this->validated('id_token');
    }

    public function rawNonce(): string
    {
        return (string) $this->validated('nonce');
    }

    public function messageLocale(): mixed
    {
        return $this->validated('locale');
    }
}
