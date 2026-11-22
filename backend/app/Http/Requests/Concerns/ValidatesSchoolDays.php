<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `school_days` in the quiet-hours PUTs (David 2026-11-22, »Starš izbere
 * dneve«): optional; an array of distinct ISO weekdays 1..7 (1 = Monday …
 * 7 = Sunday), `[]` = no school window on any day. Missing = keep the stored
 * value (old apps never send it). Anything else → 422 with
 * `reason: school_days_invalid` (nothing is saved).
 */
trait ValidatesSchoolDays
{
    public const REASON_SCHOOL_DAYS_INVALID = 'school_days_invalid';

    /**
     * @return array<string, list<mixed>>
     */
    protected function schoolDaysRules(string $field): array
    {
        return [
            $field => ['sometimes', 'present', 'array', 'list', 'max:7'],
            // A JSON integer only ("1" or 1.0 is not a day); explicit so it never depends on
            // the framework's `integer:strict` support.
            $field.'.*' => [
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('Each school day must be an integer 1..7.');
                    }
                },
                'integer',
                'between:1,7',
                'distinct:strict',
            ],
        ];
    }

    /**
     * Throws the coded 422 when any school_days rule failed.
     */
    protected function failOnSchoolDays(ValidatorContract $validator, string $field): void
    {
        $errors = $validator->errors();
        $failed = $errors->has($field) || collect($errors->keys())->contains(fn (string $key): bool => str_starts_with($key, $field.'.'));
        if (! $failed) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'School days must be a list of distinct weekdays 1 (Monday) … 7 (Sunday).',
            'reason' => self::REASON_SCHOOL_DAYS_INVALID,
            'errors' => $errors->toArray(),
        ], 422));
    }
}
