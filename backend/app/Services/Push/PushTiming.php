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

    /**
     * Earliest family-local time for the walk reminder when the family has
     * no night window to derive it from (fix/quiet-hours-default; confirmed by
     * David 2026-10-08 08:54).
     */
    public const WALK_FLOOR_FALLBACK = '09:00';

    /** A bedtime that does not wrap midnight is a night window if it starts by then. */
    public const NIGHT_START_LATEST = '04:00';

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
     * stretch, else now — and never before walkReminderFloor() (also when
     * the parent switched quiet hours off: no walk reminder right after the
     * midnight reset, 2026-10-08).
     */
    public static function walkReminderEarliest(?QuietHours $quietHours, CarbonInterface $now, string $timezone): Carbon
    {
        $floor = self::walkReminderFloor($quietHours, $now, $timezone);

        if ($quietHours?->isQuietNow($now) ?? false) {
            $afterStretch = self::quietStretchEnd($quietHours, $now)->addHours(self::WALK_DELAY_HOURS);

            return $afterStretch->greaterThan($floor) ? $afterStretch : $floor;
        }

        $lastEnd = self::lastQuietEndToday($quietHours, $now, $timezone);
        $earliest = $lastEnd?->copy()->addHours(self::WALK_DELAY_HOURS);
        if ($earliest === null || $floor->greaterThan($earliest)) {
            $earliest = $floor;
        }

        return $earliest->greaterThan($now) ? $earliest : Carbon::instance($now);
    }

    /**
     * The family-local time on $now's local day before which no walk
     * reminder goes out, whether quiet hours are on or off: 2 h after the
     * stored night window ends, else WALK_FLOOR_FALLBACK (no night window, or
     * one whose end + 2 h would fall on the next day). A night window is a
     * bedtime that wraps midnight (21:00–07:00 → 09:00) or one that starts
     * at or after midnight up to NIGHT_START_LATEST (00:00–06:00 → 08:00).
     * Returned in the family timezone. (Confirmed by David 2026-10-08 08:54.)
     */
    public static function walkReminderFloor(?QuietHours $quietHours, CarbonInterface $now, string $timezone): Carbon
    {
        $floor = self::WALK_FLOOR_FALLBACK;

        $start = $quietHours?->bedtime_start !== null ? substr((string) $quietHours->bedtime_start, 0, 5) : null;
        $end = $quietHours?->bedtime_end !== null ? substr((string) $quietHours->bedtime_end, 0, 5) : null;
        $isNight = $start !== null && $end !== null
            && ($end < $start || ($start <= self::NIGHT_START_LATEST && $start < $end));
        if ($isNight) {
            [$hour, $minute] = array_map('intval', explode(':', $end));
            $minutes = $hour * 60 + $minute + self::WALK_DELAY_HOURS * 60;
            if ($minutes < 24 * 60) {
                $floor = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
        }

        [$hour, $minute] = array_map('intval', explode(':', $floor));

        return Carbon::instance($now)->setTimezone($timezone)->startOfDay()->setTime($hour, $minute);
    }
}
