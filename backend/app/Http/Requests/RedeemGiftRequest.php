<?php

namespace App\Http\Requests;

use App\Services\Gift\GiftRedeemResult;
use App\Support\WebsiteLocale;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/gift/redeem (M6-S01, CONTRACT §2.4 step 1; M6-S01b). Authenticated by the
 * website sign-in token (RequireWebSession) — the family comes from the token, never
 * from the body: a sent `email` is ignored (never validated, never logged). A failure
 * answers 422 `invalid_request` without echoing field names or values.
 */
class RedeemGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        if (is_string($code)) {
            $this->merge(['code' => trim($code)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The voucher code (a Lemon Squeezy license key) as printed: letters, digits, dashes, spaces.
            'code' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9\- ]+$/'],
            // Language of the `message` text only (one of the 15 website languages; the
            // server answers sl / en, the rest en — the website maps `error`).
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(WebsiteLocale::ALL)],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        WebsiteLocale::apply($this->input('locale'));

        throw new HttpResponseException(response()->json([
            'error' => GiftRedeemResult::INVALID_REQUEST,
            'message' => __('gift.'.GiftRedeemResult::INVALID_REQUEST),
        ], 422));
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }
}
