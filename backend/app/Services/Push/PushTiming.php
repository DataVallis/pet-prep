<?php

namespace App\Services\Push;

use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Wall-clock helpers for push timing (PR #35 review), on the family-local
 * clock of QuietHours (DST-exact through nextBoundaryAfter / segmentsBetween).
 */
final class PushTiming
{
    /** The walk reminder comes at least this long after a quiet stretch ended. */
    public const WALK_DELAY_HOURS = 2;

    /** Adjacent windows (school right after bedtime …) are walked through. */
    private const MAX_BOUNDARIES = 12;

    /**
     * End of the quiet stretch that contains $now (= $now when not quiet).
     */
    public static function quietStretchEnd(?QuietHours $quietHours, CarbonInterface $now): Carbon
    {
        $cursor = Carbon::instance($now);
        if ($quietHours === null) {
            return $cursor;
        }

        for ($i = 0; $i < self::MAX_BOUNDARIES && $quietHours->isQuietNow($cursor); $i++) {
            $next = $quietHours->nextBoundaryAfter($cursor);
            if ($next === null) {
                break;
            }
            $cursor = Carbon::instance($next);
        }

        return $cursor;
    }

    /**
     * End of the last quiet stretch on $now's family-local day that ended at
     * or before $now; null when none did (no quiet hours that day so far).
     */
    public static function lastQuietEndToday(?QuietHours $quietHours, CarbonInterface $now, string $timezone): ?Carbon
    {
        if ($quietHours === null || ! $quietHours->is_active) {
            return null;
        }

        $dayStart = Carbon::instance($now)->setTimezone($timezone)->startOfDay();
        $last = null;
        foreach (QuietHours::segmentsBetween($quietHours, $dayStart, $now) as [, $end, $isQuiet]) {
            if ($isQuiet) {
                $last = $end;
            }
        }

        return $last;
    }

    /**
     * Earliest moment for the daily walk reminder: 2 h after the quiet
     * stretch that is running now, else 2 h after today's last quiet
     * stretch, else now.
     */
    public static function walkReminderEarliest(?QuietHours $quietHours, CarbonInterface $now, string $timezone): Carbon
    {
        if ($quietHours?->isQuietNow($now) ?? false) {
            return self::quietStretchEnd($quietHours, $now)->addHours(self::WALK_DELAY_HOURS);
        }

        $lastEnd = self::lastQuietEndToday($quietHours, $now, $timezone);
        $earliest = $lastEnd?->copy()->addHours(self::WALK_DELAY_HOURS);

        return $earliest !== null && $earliest->greaterThan($now) ? $earliest : Carbon::instance($now);
    }
}
