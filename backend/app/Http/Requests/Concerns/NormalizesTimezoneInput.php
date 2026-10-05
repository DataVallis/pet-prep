<?php

namespace App\Http\Requests\Concerns;

use App\Support\TimezoneNormalizer;

/**
 * Replace a device-reported timezone with its canonical IANA name before
 * validation (TimezoneNormalizer): aliases such as Etc/UTC or Asia/Calcutta
 * pass `timezone:all`; with $fallbackToDefault, unknown-but-valid zones become
 * the default (logged); anything else stays as sent so the rule answers 422.
 */
trait NormalizesTimezoneInput
{
    /**
     * @param  bool  $fallbackToDefault  unknown-but-valid zones → default
     *                                   (sign-up only; see TimezoneNormalizer)
     */
    protected function normalizeTimezoneInput(string $field = 'timezone', bool $fallbackToDefault = false): void
    {
        $value = $this->input($field);
        if (! is_string($value)) {
            return;
        }

        $canonical = TimezoneNormalizer::canonicalize($value, static::class, $fallbackToDefault);
        $this->merge([$field => $canonical ?? trim($value)]);
    }
}
