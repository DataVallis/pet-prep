<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Services\Results\FeedingStatus;
use App\Services\Results\WaterStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * When the child may feed and water the pet (M1-07, PRODUCT_SPEC §5).
 *
 * - Feeding: only inside the breed's `feed_windows`, family-local [start, end)
 *   "HH:MM" pairs (default 06–10 and 17–21); one feed per window. A window
 *   whose end is not after its start runs over midnight.
 * - Water: at most `water_times_per_day` refills per family-local day and at
 *   least `water_min_gap_minutes` real minutes between two refills (the gap
 *   also applies across midnight).
 *
 * Every wall-clock rule is evaluated in Pet::familyTimezone(); instants come
 * back in that zone, queries run in UTC. DST: windows follow the local wall
 * clock (06:00 is 04:00 UTC in summer, 05:00 UTC in winter); the water gap
 * counts real minutes; the fall-back day has 25 h, the spring-forward day 23 h.
 *
 * The source of truth for "already fed / watered" is `activities_log`
 * (fed_pet / watered_pet rows). Callers that act on the result hold the pet's
 * row lock, so the count can't change underneath them.
 */
class CareScheduleService
{
    /**
     * How many local days around today are scanned for feed windows
     * (yesterday covers windows over midnight, +2 finds the next window).
     */
    private const WINDOW_DAYS = [-1, 0, 1, 2];

    public function feeding(Pet $pet, BreedConfig $config, CarbonInterface $now): FeedingStatus
    {
        $tz = $pet->familyTimezone();
        $now = CarbonImmutable::instance($now)->setTimezone($tz);
        $windows = $this->configuredWindows($config);

        $current = null;
        $upcoming = null;
        foreach ($this->windowInstances($windows, $now, $tz) as [$start, $end]) {
            if ($current === null && $start->lessThanOrEqualTo($now) && $now->lessThan($end)) {
                $current = [$start, $end];
            } elseif ($upcoming === null && $start->greaterThan($now)) {
                $upcoming = [$start, $end];
            }
        }

        $lastFed = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::FedPet->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');
        $lastFedAt = $this->instant($lastFed, $tz);

        $fedInCurrent = $current !== null
            && $lastFedAt !== null
            && $lastFedAt->greaterThanOrEqualTo($current[0]);

        // The next window in which feeding is possible: the current one while
        // it is unused, otherwise the next one that starts later.
        $next = $current !== null && ! $fedInCurrent ? $current : $upcoming;

        return new FeedingStatus(
            windows: $windows,
            currentStart: $current[0] ?? null,
            currentEnd: $current[1] ?? null,
            fedInCurrent: $fedInCurrent,
            nextStart: $next[0] ?? null,
            nextEnd: $next[1] ?? null,
            lastFedAt: $lastFedAt,
        );
    }

    public function water(Pet $pet, BreedConfig $config, CarbonInterface $now): WaterStatus
    {
        $tz = $pet->familyTimezone();
        $now = CarbonImmutable::instance($now)->setTimezone($tz);
        $timesPerDay = max(0, (int) $config->water_times_per_day);
        $gapMinutes = max(0, (int) $config->water_min_gap_minutes);

        $dayStart = $now->startOfDay();
        $nextDayStart = $now->addDay()->startOfDay();

        $usedToday = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::WateredPet->value)
            ->where('created_at', '>=', $dayStart->utc())
            ->where('created_at', '<', $nextDayStart->utc())
            ->count();

        $last = ActivityLog::where('pet_id', $pet->id)
            ->where('activity_type', ActivityType::WateredPet->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');
        $lastAt = $this->instant($last, $tz);

        $gapEnd = $lastAt?->addMinutes($gapMinutes);
        $tooSoon = $gapEnd !== null && $now->lessThan($gapEnd);
        $limitReached = $usedToday >= $timesPerDay;

        $nextAllowedAt = null;
        if ($limitReached) {
            // Tomorrow, unless the breed allows no water at all.
            if ($timesPerDay > 0) {
                $nextAllowedAt = $gapEnd !== null && $gapEnd->greaterThan($nextDayStart) ? $gapEnd : $nextDayStart;
            }
        } elseif ($tooSoon) {
            $nextAllowedAt = $gapEnd;
        }

        return new WaterStatus(
            timesPerDay: $timesPerDay,
            minGapMinutes: $gapMinutes,
            usedToday: $usedToday,
            lastAt: $lastAt,
            nextAllowedAt: $nextAllowedAt,
            limitReached: $limitReached,
            tooSoon: $tooSoon,
        );
    }

    /**
     * The breed's windows; an empty or broken config falls back to the spec
     * default (06–10, 17–21) instead of making feeding impossible.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function configuredWindows(BreedConfig $config): array
    {
        $valid = [];
        foreach ((array) $config->feed_windows as $window) {
            $start = $window[0] ?? null;
            $end = $window[1] ?? null;
            if (is_string($start) && is_string($end) && $this->isTime($start) && $this->isTime($end) && $start !== $end) {
                $valid[] = [$start, $end];
            }
        }

        if ($valid === []) {
            Log::warning('CareScheduleService: breed has no valid feed windows, using the default', [
                'breed_slug' => $config->breed_slug,
            ]);

            return BreedConfig::DEFAULT_FEED_WINDOWS;
        }

        return $valid;
    }

    /**
     * Concrete window instances for the local days around $now, sorted by start.
     *
     * @param  list<array{0: string, 1: string}>  $windows
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windowInstances(array $windows, CarbonImmutable $now, string $tz): array
    {
        $today = $now->toDateString();
        $instances = [];

        foreach (self::WINDOW_DAYS as $offset) {
            $date = CarbonImmutable::parse($today, 'UTC')->addDays($offset)->toDateString();
            foreach ($windows as [$start, $end]) {
                $endDate = $end > $start
                    ? $date
                    : CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString();

                $instances[] = [$this->localTime($date, $start, $tz), $this->localTime($endDate, $end, $tz)];
            }
        }

        usort($instances, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $instances;
    }

    /**
     * A family-local wall-clock time on a date. A time inside the
     * spring-forward gap resolves to the instant after the jump.
     */
    private function localTime(string $date, string $time, string $tz): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', "{$date} {$time}:00", $tz);
    }

    /**
     * A stored UTC timestamp (cast or raw) in the family timezone.
     */
    private function instant(mixed $value, string $tz): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value, 'UTC');

        return $instant->setTimezone($tz);
    }

    private function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
