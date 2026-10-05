<?php

namespace App\Support;

use App\Models\Family;
use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canonical family timezone from what a device reports (M2-10a, PR #25).
 *
 * Phones still send legacy / alias names that PHP's `timezone:all` rule
 * rejects although `new DateTimeZone()` accepts them (Etc/UTC, Asia/Calcutta,
 * Europe/Kiev, …). A parent must not fail sign-up or settings because of that:
 *  1. a canonical name (case-insensitive match) → itself, correctly cased;
 *  2. a known alias (TimezoneAliases) → its canonical name;
 *  3. anything else `new DateTimeZone()` accepts (offsets, abbreviations,
 *     unknown aliases) → Family::DEFAULT_TIMEZONE, logged — only when the
 *     caller allows the fallback (sign-up: the device reported it, the parent
 *     never typed it). Settings / quiet hours pass false: an explicit change
 *     to "+02:00" must be a 422, not a silent switch to Ljubljana;
 *  4. not a timezone at all → null (the caller keeps the value, validation
 *     answers 422).
 */
final class TimezoneNormalizer
{
    /** @var array<string, string>|null lower-case name → canonical name */
    private static ?array $canonical = null;

    public static function canonicalize(string $input, string $context = 'timezone', bool $fallbackToDefault = true): ?string
    {
        $tz = trim($input);
        if ($tz === '') {
            return null;
        }

        $canonical = self::canonicalNames();

        if (isset($canonical[strtolower($tz)])) {
            return $canonical[strtolower($tz)];
        }

        foreach (TimezoneAliases::MAP as $alias => $target) {
            if (strcasecmp($alias, $tz) === 0 && isset($canonical[strtolower($target)])) {
                return $canonical[strtolower($target)];
            }
        }

        if (! $fallbackToDefault) {
            return null;
        }

        try {
            new DateTimeZone($tz);
        } catch (Throwable) {
            return null;
        }

        Log::warning("Unknown timezone alias \"{$tz}\" ({$context}) mapped to the default ".Family::DEFAULT_TIMEZONE.'.');

        return Family::DEFAULT_TIMEZONE;
    }

    /**
     * @return array<string, string>
     */
    private static function canonicalNames(): array
    {
        if (self::$canonical === null) {
            self::$canonical = [];
            foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL) as $name) {
                self::$canonical[strtolower($name)] = $name;
            }
        }

        return self::$canonical;
    }
}
