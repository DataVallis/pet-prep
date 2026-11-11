<?php

namespace App\Http\Requests;

use App\Enums\PartnerInquiryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/partner-inquiries (M6-S03, WEBSITE_SALES_SPEC §5.2). Public.
 */
class StorePartnerInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Honeypot: the website form has a hidden `website` field no person fills.
     * A bot that did gets the success answer and nothing is stored — before
     * validation, so a bot learns nothing from a 422.
     */
    protected function prepareForValidation(): void
    {
        $honeypot = $this->input('website');
        if (is_string($honeypot) ? trim($honeypot) !== '' : ! empty($honeypot)) {
            throw new HttpResponseException(response()->json(['ok' => true], 201));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company' => ['required', 'string', 'max:160'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'type' => ['required', 'string', Rule::enum(PartnerInquiryType::class)],
            'message' => ['required', 'string', 'max:4000'],
            'website' => ['nullable'],
            'locale' => ['nullable', 'string', Rule::in(['sl', 'en'])],
        ];
    }
}
