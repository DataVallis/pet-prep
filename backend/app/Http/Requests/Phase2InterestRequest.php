<?php

namespace App\Http\Requests;

use App\Enums\Phase2PriceChoice;
use App\Models\User;
use App\Services\Validation\Phase2InterestService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * POST /api/parent/phase2-interest (H7, VALIDATION_PLAN §4.4). `email` is required when `notify`
 * is true → 422 with `code: email_required` (an address that is not valid → `code: email_invalid`)
 * and ignored when `notify` is false; other validation errors are the usual 422 body.
 * `price_choice` may be left out only for a WITHDRAWAL — `{notify: false}` from a parent who already
 * answered (QA #1480 B1): the stored price stays, the consent and the e-mail go.
 */
class Phase2InterestRequest extends FormRequest
{
    public const CODE_EMAIL_REQUIRED = 'email_required';

    public const CODE_EMAIL_INVALID = 'email_invalid';

    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $email = trim($email);
            $this->merge(['email' => $email === '' ? null : $email]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // »Koliko bi bili pripravljeni plačati?« — monthly options (David 2026-11-21 D3).
            // Optional only for a withdrawal `{notify: false}` of an existing answer.
            'price_choice' => [$this->isWithdrawal() ? 'sometimes' : 'required', 'string', Rule::in(Phase2PriceChoice::values())],
            // »Obvestite me, ko bo na voljo«.
            'notify' => ['required', 'boolean'],
            // Required when `notify` is true (the app prefills the account e-mail). Printable ASCII
            // only, like the website's e-mail store.
            'email' => ['exclude_unless:notify,true', 'nullable', 'required_if_accepted:notify', 'string', 'max:190', 'regex:/^[\x21-\x7E]+$/', 'email'],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $errors = $validator->errors();
        if ($errors->has('email')) {
            $failed = array_keys($validator->failed()['email'] ?? []);
            $code = in_array('RequiredIfAccepted', $failed, true) ? self::CODE_EMAIL_REQUIRED : self::CODE_EMAIL_INVALID;

            throw new HttpResponseException(response()->json([
                'message' => (string) $errors->first('email'),
                'code' => $code,
                'errors' => $errors->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    /** Null = keep the stored price (a withdrawal). */
    public function priceChoice(): ?Phase2PriceChoice
    {
        $price = $this->validated('price_choice');

        return is_string($price) ? Phase2PriceChoice::from($price) : null;
    }

    /** `{notify: false}` without a price from a parent who already has a stored answer. */
    private function isWithdrawal(): bool
    {
        if ($this->has('price_choice') || ! $this->has('notify')) {
            return false;
        }
        $notify = filter_var($this->input('notify'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $notify === false && $this->user() !== null
            && app(Phase2InterestService::class)->hasAnswer($this->user());
    }

    public function notify(): bool
    {
        return (bool) $this->validated('notify');
    }

    public function email(): ?string
    {
        $email = $this->validated('email');

        return is_string($email) ? $email : null;
    }
}
