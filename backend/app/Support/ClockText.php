<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The ONE way a clock time is shown to a person in server-made text (push
 * copy …). API fields stay ISO 8601 / "HH:MM" data — only words for people
 * use this.
 *
 *  - 24 h (David 2026-10-21): "7:00", "9:30", "17:00" — minutes always two
 *    digits, no leading zero on the hour, SL and EN alike.
 *  - 12 h (David 2026-11-16, »ker gremo globalno«): "7:00 AM", "5:00 PM",
 *    "12:00 PM" (noon), "12:00 AM" (midnight); the suffix comes from
 *    lang/<locale>/clock.php (EN "AM" / "PM", SL "dop." / "pop.").
 *
 * The apps format exactly the same way (`mobile/src/modules/time/clock.ts`,
 * own code on purpose — Intl output differs between engines, e.g. U+202F
 * before "PM"), so a push reads like the app on the same phone.
 */
final class ClockText
{
    /** "7:00" / "7:00 AM" for $at on the wall clock of $timezone. */
    public static function at(CarbonInterface $at, string $timezone, bool $hour12 = false, ?string $locale = null): string
    {
        $local = $at->copy()->setTimezone($timezone);

        return self::of((int) $local->format('G'), (int) $local->format('i'), $hour12, $locale);
    }

    /** "17:00" / "5:00 PM" for a wall clock hour (0–23) and minute. */
    public static function of(int $hour, int $minute, bool $hour12 = false, ?string $locale = null): string
    {
        $mm = str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
        if (! $hour12) {
            return $hour.':'.$mm;
        }

        $suffix = trans($hour < 12 ? 'clock.am' : 'clock.pm', [], $locale);
        $h12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return $h12.':'.$mm.' '.$suffix;
    }

    /**
     * A 24 h text made by at() ("17:00", "7:05") in the 12 h format of
     * $locale ("5:00 PM"); anything else is returned unchanged.
     */
    public static function to12h(string $text, ?string $locale = null): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return $text;
        }

        return self::of((int) $m[1], (int) $m[2], true, $locale);
    }
}
