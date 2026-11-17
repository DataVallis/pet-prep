<?php

namespace App\Http\Requests;

use App\Support\WebsiteLocale;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/shop/checkout (M6-S09). The website sends the product, its language and
 * its own localised return paths; only a path on petprep.si is accepted (no scheme,
 * no host, no `//`, no query beyond what we add) — never an open redirect.
 */
class ShopCheckoutRequest extends FormRequest
{
    public const PATH_RULE = 'regex:#^/(?!/)[a-z0-9/\-]{0,200}$#';

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
            // A key of config('shop.products'): gift_voucher | promise_kit.
            'product' => ['required', 'string', Rule::in(array_keys((array) config('shop.products')))],
            // One of the 15 website languages: checkout page, e-mail and files.
            'locale' => ['required', 'string', Rule::in(WebsiteLocale::ALL)],
            // Website path of the thank-you page, e.g. `/sl/darilni-bon/hvala`.
            'success_path' => ['required', 'string', self::PATH_RULE],
            // Website path to return to when the buyer leaves the checkout, e.g. `/sl/darilni-bon`.
            'cancel_path' => ['required', 'string', self::PATH_RULE],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid_request'], 422));
    }
}
