<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The ONE way a clock time is shown to a person (David 2026-10-21): "7:00",
 * "9:30", "17:00" — 24 h, minutes always two digits, no leading zero on the
 * hour, SL and EN alike (the apps do the same with `formatClock`). Used for
 * every time placed in a user-visible text (push copy …). API fields stay
 * ISO 8601 / "HH:MM" data — only words for people use this.
 */
final class ClockText
{
    /** "7:00" for $at on the wall clock of $timezone. */
    public static function at(CarbonInterface $at, string $timezone): string
    {
        return $at->copy()->setTimezone($timezone)->format('G:i');
    }
}
