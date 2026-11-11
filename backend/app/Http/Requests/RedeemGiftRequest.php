<?php

namespace App\Http\Requests;

use App\Services\Gift\GiftRedeemResult;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * POST /api/gift/redeem (M6-S01, CONTRACT §2.4 step 1). Public (no auth). A failure
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
        foreach (['code', 'email'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
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
            // The parent's account e-mail in the app.
            'email' => ['required', 'string', 'max:254', 'email:rfc'],
            // Language of the `message` text only (the website maps `error`).
            'locale' => ['sometimes', 'nullable', 'string', 'in:sl,en'],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if (in_array($this->input('locale'), ['sl', 'en'], true)) {
            app()->setLocale((string) $this->input('locale'));
        }

        throw new HttpResponseException(response()->json([
            'error' => GiftRedeemResult::INVALID_REQUEST,
            'message' => __('gift.'.GiftRedeemResult::INVALID_REQUEST),
        ], 422));
    }

    public function code(): string
    {
        return (string) $this->validated('code');
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }
}
