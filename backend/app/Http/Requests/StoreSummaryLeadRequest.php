<?php

namespace App\Http\Requests;

use App\Models\EmailContact;
use App\Models\EmailSignup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/summary-leads (M6-S05, THREEJS_OPPORTUNITIES T9). Public. Kept for old cached pages since M6-S09
 * (stored in the unified `email_contacts` / `email_signups`); new pages use StoreEmailSignupRequest.
 */
class StoreSummaryLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Honeypot: the page form has a hidden `website` field no person fills.
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
            // Printable ASCII without spaces only: no NUL / control bytes, no quoted local parts with
            // spaces, no non-ASCII — so the address is stored whole and lower-cases the same in PHP
            // and in PostgreSQL (the table's CHECK email = lower(email)).
            'email' => ['required', 'string', 'max:190', 'regex:/^[\x21-\x7E]+$/', 'email'],
            // The parent ticked the consent box; without it nothing is stored.
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', Rule::in(EmailContact::CONSENT_VERSIONS)],
            'source' => ['required', 'string', Rule::in(EmailSignup::LEGACY_SOURCES)],
            // Website language (two letters) and the breed slug of the website's breed register.
            'locale' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'breed' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/'],
            'stage' => ['nullable', 'string', Rule::in(EmailSignup::STAGES)],
            'website' => ['nullable'],
        ];
    }
}
