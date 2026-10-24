<?php

namespace App\Services;

use App\Enums\StageParamKey;
use App\Models\Pet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * M5-R14 (David 2026-10-24): flat-faced breeds whose source says not to
 * exercise them in the heat (English Bulldog S180, French Bulldog S81, Boston
 * Terrier S259, Pug S357 — `no_walk_in_heat` row) get a calm warning when the
 * child starts / does a walk at summer midday. No weather API (CLAUDE.md
 * scope guard): only the calendar and the clock of the family timezone.
 *
 *  - Summer = June–August, or December–February when the family timezone lies
 *    in the southern hemisphere (the IANA zone's location latitude < 0; a zone
 *    without a location, e.g. UTC, counts as northern — David's default).
 *  - Window = 11:00–16:00 family-local wall clock (config/care_tasks.php heat).
 *  - Only a warning: steps still count, no score, no penalty (like the
 *    digestion warning, M5-R16).
 */
class WalkHeatService
{
    public function __construct(private readonly LifeStageService $lifeStages) {}

    public function appliesTo(Pet $pet, string $localDate): bool
    {
        if (! $pet->careTasksEnabled()) {
            return false;
        }

        return ($this->lifeStages->stageValueOn($pet, $localDate, StageParamKey::NoWalkInHeat)['value'] ?? null) === true;
    }

    /** The family timezone lies in the southern hemisphere (its IANA location). */
    public static function isSouthern(string $timezone): bool
    {
        try {
            $location = (new DateTimeZone($timezone))->getLocation();
        } catch (Throwable) {
            return false;
        }

        return is_array($location) && isset($location['latitude']) && (float) $location['latitude'] < 0
            && ($location['country_code'] ?? '??') !== '??';
    }

    /** A family-local month is summer in that hemisphere. */
    public static function isSummerMonth(int $month, string $timezone): bool
    {
        $months = self::isSouthern($timezone)
            ? (array) config('care_tasks.heat.summer_months_south', [12, 1, 2])
            : (array) config('care_tasks.heat.summer_months_north', [6, 7, 8]);

        return in_array($month, array_map('intval', $months), true);
    }

    /**
     * Today's heat window of the pet (family-local day of $now) when it is a
     * summer day and the breed has the rule; null otherwise.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    public function windowOn(Pet $pet, CarbonInterface $now): ?array
    {
        $tz = $pet->familyTimezone();
        $local = CarbonImmutable::instance($now)->setTimezone($tz);
        if (! $this->appliesTo($pet, $local->toDateString()) || ! self::isSummerMonth((int) $local->month, $tz)) {
            return null;
        }
        $from = CarbonImmutable::parse($local->toDateString().' '.config('care_tasks.heat.from', '11:00'), $tz);
        $to = CarbonImmutable::parse($local->toDateString().' '.config('care_tasks.heat.to', '16:00'), $tz);

        return $from->lessThan($to) ? ['starts_at' => $from->utc(), 'ends_at' => $to->utc()] : null;
    }

    /** The warning is on at $now. */
    public function activeAt(Pet $pet, CarbonInterface $now): bool
    {
        $window = $this->windowOn($pet, $now);
        $at = CarbonImmutable::instance($now)->utc();

        return $window !== null && $at->greaterThanOrEqualTo($window['starts_at']) && $at->lessThan($window['ends_at']);
    }
}
