<?php

namespace App\Http\Requests;

use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Support\WebsiteLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/email-signups (M6-S09). Public.
 */
class StoreEmailSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Honeypot: the website form has a hidden `website` field no person fills. A bot that did gets
     * the success answer and nothing is stored — before validation, so a bot learns nothing from a
     * 422. Also: a query string / fragment of `page_path` is cut off (only the path is wanted — a
     * query could carry personal data).
     */
    protected function prepareForValidation(): void
    {
        $honeypot = $this->input('website');
        if (is_string($honeypot) ? trim($honeypot) !== '' : ! empty($honeypot)) {
            throw new HttpResponseException(response()->noContent());
        }

        $path = $this->input('page_path');
        if (is_string($path)) {
            $this->merge(['page_path' => preg_split('/[?#]/', $path, 2)[0]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $utm = ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]*$/u'];

        return [
            // Printable ASCII without spaces only: no NUL / control bytes, no quoted local parts with
            // spaces, no non-ASCII — so the address is stored whole and lower-cases the same in PHP
            // and in PostgreSQL (the table's CHECK email = lower(email)).
            'email' => ['required', 'string', 'max:190', 'regex:/^[\x21-\x7E]+$/', 'email'],
            // The person ticked the consent box; without it nothing is stored.
            'consent' => ['required', 'accepted'],
            'consent_version' => ['required', 'string', 'max:16', Rule::in(EmailContact::CONSENT_VERSIONS)],
            'purpose' => ['required', 'string', Rule::in(EmailSignup::PURPOSES)],
            // What form on the site: real_cost | breed_quiz | early_access_form | any later one.
            'source' => ['required', 'string', 'regex:/^[a-z0-9_]{1,32}$/'],
            // Path only (no host, no query): where on the site the e-mail was left.
            'page_path' => ['required', 'string', 'max:300', 'regex:/^\/[^\s\x00-\x1F\x7F]*$/u'],
            'section' => ['nullable', 'string', 'regex:/^[a-z0-9_-]{1,40}$/'],
            'locale' => ['required', 'string', Rule::in(WebsiteLocale::ALL)],
            'species' => ['nullable', 'string', Rule::in(EmailSignup::SPECIES)],
            'breed' => ['nullable', 'string', 'regex:/^[a-z0-9_]{1,64}$/'],
            'stage' => ['nullable', 'string', Rule::in(EmailSignup::STAGES)],
            'quiz' => ['nullable', 'array'],
            'quiz.result' => ['nullable', 'array', 'max:3'],
            'quiz.result.*' => ['string', 'regex:/^[a-z0-9_]{1,64}$/'],
            'quiz.answers' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            'utm' => ['nullable', 'array'],
            'utm.source' => $utm,
            'utm.medium' => $utm,
            'utm.campaign' => $utm,
            'utm.content' => $utm,
            'utm.term' => $utm,
            // Host only (no scheme, no path), optional port.
            'referrer_host' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+(:[0-9]{1,5})?$/'],
            'website' => ['nullable'],
        ];
    }

    /**
     * The validated body flattened to the row shape EmailSignupService takes.
     *
     * @return array<string, mixed>
     */
    public function signupData(): array
    {
        $v = $this->safe();

        $data = $v->only(['email', 'consent_version', 'purpose', 'source', 'page_path', 'section', 'locale', 'species', 'breed', 'stage', 'referrer_host']);
        $data['quiz_result'] = $v->input('quiz.result');
        $data['quiz_answers'] = $v->input('quiz.answers');
        foreach (EmailSignup::UTM_KEYS as $key) {
            $data['utm_'.$key] = $v->input('utm.'.$key);
        }

        return $data;
    }
}
