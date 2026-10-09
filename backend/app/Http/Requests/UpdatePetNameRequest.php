<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\PetNameService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/parent/pets/{pet}/name (M5-R08, David 2026-10-09). Body
 * `{"name": "Luna"}`; `null` or `""` clears the name. Parents only
 * (UserPolicy::manageFamily → 403 for a child; the route group also needs
 * the `parent` token ability); whether the pet belongs to the parent's
 * family is checked in the controller (PetPolicy::rename) so another
 * family's pet looks exactly like a missing one (404).
 *
 * Normalized before validation (PetNameService::normalize): trimmed, inner
 * whitespace collapsed, ’ → ', NFC. 422 body = Laravel's validation body +
 * `codes` {name: code} (like POST /api/register) + `reason` (the same code):
 *  - `name_too_long`   — more than 20 characters (code points, not bytes);
 *  - `name_invalid`    — not a string / missing field, characters other than
 *                        letters, space, hyphen, apostrophe, or no letter;
 *  - `name_not_allowed`— on the EN / SL filter list (config/pet_names.php).
 */
class UpdatePetNameRequest extends FormRequest
{
    public const CODE_INVALID = 'name_invalid';

    public const CODE_TOO_LONG = 'name_too_long';

    public const CODE_NOT_ALLOWED = 'name_not_allowed';

    private ?string $failureCode = null;

    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => PetNameService::normalize($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Letters (any script, incl. č š ž ä ñ), space, hyphen, apostrophe;
            // 1–20 characters; null / "" = no name.
            'name' => ['present', 'nullable', 'string'],
        ];
    }

    /**
     * Length, characters and the word filter, in that order (one code).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('name')) {
                    return;
                }
                $name = $this->input('name');
                if (! is_string($name)) {
                    return; // null = clear
                }

                $max = PetNameService::maxLength();
                if (mb_strlen($name, 'UTF-8') > $max) {
                    $this->failureCode = self::CODE_TOO_LONG;
                    $validator->errors()->add('name', "The name may not be longer than {$max} characters.");
                } elseif (! PetNameService::hasValidCharacters($name)) {
                    $this->failureCode = self::CODE_INVALID;
                    $validator->errors()->add('name', 'The name may only contain letters, spaces, hyphens and apostrophes.');
                } elseif (! app(PetNameService::class)->isAllowed($name)) {
                    $this->failureCode = self::CODE_NOT_ALLOWED;
                    $validator->errors()->add('name', 'Please choose a different name.');
                }
            },
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $code = $this->failureCode ?? self::CODE_INVALID;
        $errors = $validator->errors()->toArray();
        $first = collect($errors)->flatten()->first();

        throw new HttpResponseException(response()->json([
            'message' => is_string($first) ? $first : 'The given data was invalid.',
            'errors' => $errors,
            /** @var array{name: 'name_invalid'|'name_too_long'|'name_not_allowed'} */
            'codes' => ['name' => $code],
            /** @var 'name_invalid'|'name_too_long'|'name_not_allowed' */
            'reason' => $code,
        ], 422));
    }

    /**
     * The normalized name, or null to clear it.
     */
    public function petName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? $name : null;
    }
}
