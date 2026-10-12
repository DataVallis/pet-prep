<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\ChallengeCertificateService;
use App\Services\PetNameService;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * POST /api/parent/certificates/{certificate}/generate (M2-01d, CERTIFICATE_SPEC §1).
 * Body `{"locale": "sl", "child_name": "Maja", "pet_name": "Luna"}` — all
 * required. Parents only (UserPolicy::manageFamily → 403 for a child).
 *
 * Names are normalized like a pet name (PetNameService::normalize: trimmed,
 * whitespace collapsed, ’ → ', NFKC), 1–30 characters, and go through the same
 * EN / SL word filter. Pet name: the pet-name characters (letters, space,
 * hyphen, apostrophe). Child name: the nickname characters (letters, digits,
 * space, hyphen, apostrophe, dot — CreateChildRequest), so the default
 * nickname always passes. 422 body = Laravel's validation body + `codes`
 * {field: code} + `reason` (first code): `locale_invalid`, `name_invalid`,
 * `name_too_long`, `name_not_allowed`.
 */
class GenerateCertificateRequest extends FormRequest
{
    /** Nickname characters (CreateChildRequest `display_name`). */
    private const CHILD_NAME_PATTERN = "/^[\\pL\\pM\\pN][\\pL\\pM\\pN '\\-.]*$/u";

    /** @var array<string, string> */
    private array $codes = [];

    public function authorize(): bool
    {
        return $this->user()?->can('manageFamily', User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['child_name', 'pet_name'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => PetNameService::normalize($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Language of the certificate: en | sl.
            'locale' => ['required', 'string', 'in:en,sl'],
            // Printed child name, 1–30 characters (default: the child's nickname).
            'child_name' => ['required', 'string'],
            // Printed pet name, 1–30 characters (default: the pet's name or breed).
            'pet_name' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('locale')) {
                    $this->codes['locale'] = 'locale_invalid';
                }
                foreach (['child_name', 'pet_name'] as $field) {
                    if ($validator->errors()->has($field)) {
                        $this->codes[$field] = 'name_invalid';

                        continue;
                    }
                    $name = (string) $this->input($field);
                    $max = ChallengeCertificateService::MAX_NAME_LENGTH;
                    $validChars = $field === 'pet_name'
                        ? PetNameService::hasValidCharacters($name)
                        : preg_match(self::CHILD_NAME_PATTERN, $name) === 1 && ! PetNameService::hasInvisibleOrStackedMarks($name);

                    if (mb_strlen($name, 'UTF-8') > $max) {
                        $this->codes[$field] = 'name_too_long';
                        $validator->errors()->add($field, "The name may not be longer than {$max} characters.");
                    } elseif (! $validChars) {
                        $this->codes[$field] = 'name_invalid';
                        $validator->errors()->add($field, 'The name contains characters that are not allowed.');
                    } elseif (! app(PetNameService::class)->isAllowed($name)) {
                        $this->codes[$field] = 'name_not_allowed';
                        $validator->errors()->add($field, 'Please choose a different name.');
                    }
                }
            },
        ];
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $errors = $validator->errors()->toArray();
        $codes = $this->codes;
        foreach (array_keys($errors) as $field) {
            $codes[$field] ??= $field === 'locale' ? 'locale_invalid' : 'name_invalid';
        }
        $first = collect($errors)->flatten()->first();

        throw new HttpResponseException(response()->json([
            'message' => is_string($first) ? $first : 'The given data was invalid.',
            'errors' => $errors,
            /** @var array<string, 'locale_invalid'|'name_invalid'|'name_too_long'|'name_not_allowed'> */
            'codes' => $codes,
            /** @var 'locale_invalid'|'name_invalid'|'name_too_long'|'name_not_allowed' */
            'reason' => (string) reset($codes),
        ], 422));
    }

    public function certificateLocale(): string
    {
        return (string) $this->validated('locale');
    }

    public function childName(): string
    {
        return (string) $this->validated('child_name');
    }

    public function petName(): string
    {
        return (string) $this->validated('pet_name');
    }
}
