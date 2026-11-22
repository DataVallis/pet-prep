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
     * Rules of the list itself (`school_days`).
     *
     * @return list<mixed>
     */
    protected function schoolDaysListRules(): array
    {
        return ['sometimes', 'present', 'array', 'list', 'max:7'];
    }

    /**
     * Rules of each entry (`school_days.*`): a JSON integer only ("1" or 1.0 is
     * not a day) — explicit so it never depends on the framework's
     * `integer:strict` support; `integer` stays so `between` compares numbers.
     *
     * @return list<mixed>
     */
    protected function schoolDayItemRules(): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value)) {
                    $fail('Each school day must be an integer 1..7.');
                }
            },
            'integer',
            'between:1,7',
            'distinct:strict',
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
