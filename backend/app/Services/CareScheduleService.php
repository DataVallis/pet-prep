<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Services\Results\FeedingStatus;
use App\Services\Results\WaterStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
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
 * (fed_pet / parent_fed_pet / watered_pet rows). Callers that act on the
 * result hold the pet's row lock, so the count can't change underneath them.
 *
 * Life stages (M5-R01): the windows of a family-local day come from the
 * pet's stage rules of that day (LifeStageService::rulesOn — e.g. a 2-month
 * puppy 4 meals 07–08, 11–12, 15–16, 19–20; adults the breed windows). A
 * window that lies entirely inside quiet hours (school / sleep) is "done by
 * the parent" (David 2026-10-05): not expected from the child, and the decay
 * tick feeds the dog at its start (dueParentMeals → parent_fed_pet row).
 */
class CareScheduleService
{
    /** Activity rows that count as "this window is fed". */
    public const FED_TYPES = [ActivityType::FedPet, ActivityType::ParentFedPet];

    public function __construct(private readonly LifeStageService $lifeStages) {}

    /**
     * How many local days around today are scanned for feed windows
     * (yesterday covers windows over midnight, +2 finds the next window).
     */
    private const WINDOW_DAYS = [-1, 0, 1, 2];

    public function feeding(Pet $pet, BreedConfig $config, CarbonInterface $now): FeedingStatus
    {
        $tz = $pet->familyTimezone();
        $now = CarbonImmutable::instance($now)->setTimezone($tz);
        $windows = $this->windowsOn($pet, $config, $now->toDateString());

        $current = null;
        $upcoming = null;
        foreach ($this->windowInstances($pet, $config, $now, $tz) as [$start, $end]) {
            if ($current === null && $start->lessThanOrEqualTo($now) && $now->lessThan($end)) {
                $current = [$start, $end];
            } elseif ($upcoming === null && $start->greaterThan($now)) {
                $upcoming = [$start, $end];
            }
        }

        $lastFed = ActivityLog::where('pet_id', $pet->id)
            ->whereIn('activity_type', array_map(fn (ActivityType $t) => $t->value, self::FED_TYPES))
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
     * The feed-window instances that START on the family-local date $date,
     * sorted by start (routine ledger, M2-06: one feed routine per window).
     * Same rules as feeding(): [start, end), an end ≤ start runs over
     * midnight, a time in the spring-forward gap resolves after the jump,
     * a broken config falls back to the default windows. Windows are the
     * pet's stage rules of $date (M5-R01).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function feedWindowsStartingOn(Pet $pet, BreedConfig $config, string $date): array
    {
        $tz = $pet->familyTimezone();
        $instances = [];
        foreach ($this->windowsOn($pet, $config, $date) as [$start, $end]) {
            $endDate = $end > $start
                ? $date
                : CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString();

            $instances[] = [$this->localTime($date, $start, $tz), $this->localTime($endDate, $end, $tz)];
        }

        usort($instances, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $instances;
    }

    /**
     * Per window: was the dog fed in it — a `fed_pet` (any caretaker child)
     * or `parent_fed_pet` (decay tick, parent-covered window) row with
     * created_at in [start, end). One query for all windows (M5-R04 HUD).
     *
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $windows
     * @return list<bool>
     */
    public function fedInWindows(Pet $pet, array $windows): array
    {
        if ($windows === [] || $pet->born_at === null) {
            return array_fill(0, count($windows), false);
        }

        $from = min(array_map(fn (array $w) => CarbonImmutable::instance($w[0])->utc(), $windows));
        $to = max(array_map(fn (array $w) => CarbonImmutable::instance($w[1])->utc(), $windows));

        $fedAt = ActivityLog::where('pet_id', $pet->id)
            ->whereIn('activity_type', array_map(fn (ActivityType $t) => $t->value, self::FED_TYPES))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->pluck('created_at')
            ->map(fn ($v) => $this->instant($v, 'UTC'))
            ->all();

        return array_map(function (array $w) use ($fedAt): bool {
            $start = CarbonImmutable::instance($w[0])->utc();
            $end = CarbonImmutable::instance($w[1])->utc();
            foreach ($fedAt as $at) {
                if ($at !== null && $at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                    return true;
                }
            }

            return false;
        }, $windows);
    }

    /**
     * The validated windows of a family-local date (the pet's stage rules).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function windowsOn(Pet $pet, BreedConfig $config, string $date): array
    {
        return $this->configuredWindows($config, $this->lifeStages->rulesOn($pet, $date, $config)->feedWindows);
    }

    /**
     * A feed window that lies entirely inside the family's quiet hours
     * (school / sleep): the parent feeds the dog (M5-R01, David 2026-10-05).
     */
    public function isParentCovered(?QuietHours $quiet, CarbonInterface $start, CarbonInterface $end): bool
    {
        if ($quiet === null || ! $quiet->is_active) {
            return false;
        }

        return QuietHours::splitSecondsBetween($quiet, $start, $end)['normal'] <= 0;
    }

    /**
     * A window of this pet that the parent feeds: entirely in quiet hours and
     * the pet is not on the legacy (pre-M5) profile.
     */
    public function isParentCoveredFor(Pet $pet, ?QuietHours $quiet, CarbonInterface $start, CarbonInterface $end): bool
    {
        return ! $pet->isLegacyProfile() && $this->isParentCovered($quiet, $start, $end);
    }

    /**
     * Parent-covered windows that STARTED in ($from, $to] after the birth and
     * are not fed yet (no fed_pet / parent_fed_pet row inside the window):
     * the decay tick feeds the dog for each (M5-R01). Oldest first.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function dueParentMeals(Pet $pet, BreedConfig $config, CarbonInterface $from, CarbonInterface $to, ?QuietHours $quiet): array
    {
        // Legacy-profile pets keep the pre-M5 rules: nobody feeds in quiet hours.
        if ($pet->born_at === null || $pet->isLegacyProfile() || $quiet === null || ! $quiet->is_active) {
            return [];
        }

        $tz = $pet->familyTimezone();
        $fromUtc = CarbonImmutable::instance($from)->utc();
        $toUtc = CarbonImmutable::instance($to)->utc();
        $born = CarbonImmutable::instance($pet->born_at)->utc();
        $firstDate = $fromUtc->setTimezone($tz)->subDay()->toDateString();
        $lastDate = $toUtc->setTimezone($tz)->toDateString();

        $due = [];
        for ($date = $firstDate; $date <= $lastDate; $date = CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString()) {
            foreach ($this->feedWindowsStartingOn($pet, $config, $date) as [$start, $end]) {
                $startUtc = $start->utc();
                if ($startUtc->lessThanOrEqualTo($fromUtc) || $startUtc->greaterThan($toUtc) || $startUtc->lessThan($born)) {
                    continue;
                }
                if (! $this->isParentCovered($quiet, $start, $end)) {
                    continue;
                }
                $fed = ActivityLog::where('pet_id', $pet->id)
                    ->whereIn('activity_type', array_map(fn (ActivityType $t) => $t->value, self::FED_TYPES))
                    ->where('created_at', '>=', $startUtc)
                    ->where('created_at', '<', $end->utc())
                    ->exists();
                if (! $fed) {
                    $due[] = [$start, $end];
                }
            }
        }

        return $due;
    }

    /**
     * Valid windows of a list; empty or broken → the spec default (06–10,
     * 17–21) instead of making feeding impossible.
     *
     * @param  list<array{0: string, 1: string}>|null  $windows  null = the breed's feed_windows
     * @return list<array{0: string, 1: string}>
     */
    private function configuredWindows(BreedConfig $config, ?array $windows = null): array
    {
        $valid = [];
        $invalid = 0;
        foreach ((array) ($windows ?? $config->feed_windows) as $window) {
            $start = is_array($window) ? ($window[0] ?? null) : null;
            $end = is_array($window) ? ($window[1] ?? null) : null;
            if (is_string($start) && is_string($end) && $this->isTime($start) && $this->isTime($end) && $start !== $end) {
                $valid[] = [$start, $end];
            } else {
                $invalid++;
            }
        }

        if ($valid === []) {
            $this->warnOncePerDay($config, 'CareScheduleService: breed has no valid feed windows, using the default');

            return BreedConfig::DEFAULT_FEED_WINDOWS;
        }

        if ($invalid > 0) {
            $this->warnOncePerDay($config, 'CareScheduleService: breed has invalid feed windows, ignoring them', ['ignored' => $invalid]);
        }

        return $valid;
    }

    /**
     * This runs on every GET /api/child/pet and every feed: log a broken
     * config at most once per breed per UTC day instead of flooding the log.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnOncePerDay(BreedConfig $config, string $message, array $context = []): void
    {
        $key = 'care-schedule:invalid-feed-windows:'.$config->breed_slug.':'.now()->utc()->toDateString();

        if (Cache::add($key, true, now()->utc()->endOfDay())) {
            Log::warning($message, array_merge(['breed_slug' => $config->breed_slug], $context));
        }
    }

    /**
     * Concrete window instances for the local days around $now, sorted by
     * start — each day with the windows of its own stage rules.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windowInstances(Pet $pet, BreedConfig $config, CarbonImmutable $now, string $tz): array
    {
        $today = $now->toDateString();
        $instances = [];

        foreach (self::WINDOW_DAYS as $offset) {
            $date = CarbonImmutable::parse($today, 'UTC')->addDays($offset)->toDateString();
            foreach ($this->windowsOn($pet, $config, $date) as [$start, $end]) {
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
