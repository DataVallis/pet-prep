<?php

namespace App\Services;

use App\Enums\LifeStage;
use App\Enums\StageParamKey;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Services\Results\StageRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Age, life stage and stage-dependent care rules of a pet (M5-R01,
 * David 2026-10-05, REALISM_SPEC §2/§7, PRODUCT_SPEC §4/§5).
 *
 * Age: `pets.arrival_age_months` (parent's choice at creation) + one month
 * per real week since birth (contract signature). A "week" is 7 family-local
 * days at the birth's wall-clock time, so DST never moves the birthday hour.
 * An unborn pet is as old as it arrived.
 *
 * Stage + rules: from `breed_stage_params` (sourced, cached per breed).
 * Rules of a family-local DAY follow the age at the START of that day, so a
 * stage or meal-band change takes effect at the next local midnight after
 * the weekly birthday ("rules switch next day").
 *
 * Resolution of a stage key at age A: the rows of the current stage with
 * `age_from_months` ≤ A (the greatest wins — puppy meal bands 0 / 3 / 6),
 * else the breed-level row (stage `all`). Feed windows belong to the meal
 * band: the windows row with the same (stage, from) as the meals row, else
 * the breed's breed_configs.feed_windows when the count matches, else N
 * derived windows (07:00 … 19:00, 1 h — logged). Step goal = exercise
 * minutes × steps per minute (puppy / young: minutes per month of age,
 * capped at the adult minutes), capped by breed_configs.daily_steps_cap.
 *
 * Without stage data for the breed every rule falls back to the pre-M5
 * breed config (feed_windows, daily_steps_required) and the stage is null.
 *
 * Legacy profile (`pets.arrival_age_months` null — pets created before
 * M5-R01 or without a profile choice; grandfathered, orchestrator
 * 2026-10-05, pending David): no age, no stage, never a transition, and
 * exactly the pre-M5 rules on every day, whatever the stage data says.
 */
class LifeStageService
{
    public const CACHE_SECONDS = 300;

    /** Derived windows when data is inconsistent (same rule as the seeder). */
    private const DERIVED_FIRST_HOUR = 7;

    private const DERIVED_LAST_HOUR = 19;

    /** @var array<string, list<array<string, mixed>>> */
    private array $memo = [];

    public static function cacheKey(string $breedSlug): string
    {
        return 'breed-stage-params:v1:'.$breedSlug;
    }

    public static function forgetBreed(string $breedSlug): void
    {
        Cache::forget(self::cacheKey($breedSlug));
    }

    /**
     * Rows of one breed as plain arrays (cached).
     *
     * @return list<array{stage: string, from: int, key: string, value: mixed, source_id: string|null, verified: bool, confidence: string}>
     */
    public function paramsFor(string $breedSlug): array
    {
        return $this->memo[$breedSlug] ??= Cache::remember(self::cacheKey($breedSlug), self::CACHE_SECONDS, fn (): array => BreedStageParam::query()
            ->where('breed_slug', $breedSlug)
            ->orderBy('stage')->orderBy('age_from_months')->orderBy('key')
            ->get()
            ->map(fn (BreedStageParam $p): array => [
                'stage' => $p->stage,
                'from' => (int) $p->age_from_months,
                'key' => $p->key,
                'value' => $p->value,
                'source_id' => $p->source_id,
                'verified' => (bool) $p->verified,
                'confidence' => $p->confidence,
            ])->all());
    }

    public function hasStageData(string $breedSlug): bool
    {
        return $this->stageStarts($breedSlug) !== [];
    }

    // ──────────────────────────────────────────────────────────────
    //  Age
    // ──────────────────────────────────────────────────────────────

    /**
     * Dog age in months at an instant (arrival + completed local weeks since
     * birth); null for a legacy-profile pet.
     */
    public function ageMonthsAt(Pet $pet, CarbonInterface $at): ?int
    {
        if ($pet->isLegacyProfile()) {
            return null;
        }

        $arrival = (int) $pet->arrival_age_months;
        if ($pet->born_at === null) {
            return $arrival;
        }

        return $arrival + $this->weeksSinceBirth($pet, $at);
    }

    /**
     * Dog age at the start (local midnight) of a family-local date — the age
     * the rules of that day use (null for a legacy-profile pet).
     */
    public function ageMonthsOn(Pet $pet, string $localDate): ?int
    {
        return $this->ageMonthsAt($pet, CarbonImmutable::parse($localDate, $pet->familyTimezone())->startOfDay());
    }

    /**
     * Completed weeks since birth: 7 family-local days at the birth's wall-clock time.
     */
    public function weeksSinceBirth(Pet $pet, CarbonInterface $at): int
    {
        if ($pet->born_at === null) {
            return 0;
        }

        $tz = $pet->familyTimezone();
        $born = CarbonImmutable::instance($pet->born_at)->setTimezone($tz);
        $t = CarbonImmutable::instance($at)->setTimezone($tz);
        if ($t->lessThanOrEqualTo($born)) {
            return 0;
        }

        $days = (int) CarbonImmutable::parse($born->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($t->toDateString(), 'UTC'), false);
        $weeks = intdiv(max(0, $days), 7);

        while ($weeks > 0 && $t->lessThan($this->anniversary($born, $weeks, $tz))) {
            $weeks--;
        }

        return $weeks;
    }

    /**
     * The instant the dog completes $weeks weeks: same local wall-clock time,
     * 7 × $weeks local days later (a time in the spring-forward gap resolves
     * after the jump).
     */
    public function anniversary(CarbonImmutable $bornLocal, int $weeks, string $tz): CarbonImmutable
    {
        $date = CarbonImmutable::parse($bornLocal->toDateString(), 'UTC')->addDays(7 * $weeks)->toDateString();

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date.' '.$bornLocal->format('H:i:s'), $tz);
    }

    // ──────────────────────────────────────────────────────────────
    //  Stage
    // ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, int> stage value → first month, ascending
     */
    public function stageStarts(string $breedSlug): array
    {
        $starts = [];
        foreach ($this->paramsFor($breedSlug) as $row) {
            if ($row['key'] === StageParamKey::StartsAtMonths->value && LifeStage::tryFrom($row['stage']) !== null && is_int($row['value'])) {
                $starts[$row['stage']] = $row['value'];
            }
        }
        asort($starts);

        return $starts;
    }

    public function stageForAge(string $breedSlug, int $ageMonths): ?LifeStage
    {
        $stage = null;
        foreach ($this->stageStarts($breedSlug) as $value => $from) {
            if ($from <= $ageMonths) {
                $stage = LifeStage::from($value);
            }
        }

        return $stage;
    }

    /**
     * Age in months a dog of this stage arrives with (parent's choice), or
     * null without stage data.
     */
    public function arrivalAgeFor(string $breedSlug, LifeStage $stage): ?int
    {
        foreach ($this->paramsFor($breedSlug) as $row) {
            if ($row['stage'] === $stage->value && $row['key'] === StageParamKey::ArrivalAgeMonths->value && is_int($row['value'])) {
                return $row['value'];
            }
        }

        // No explicit arrival age: the first month of the stage.
        return $this->stageStarts($breedSlug)[$stage->value] ?? null;
    }

    /**
     * Today's stage written onto the pet (attribute only — the caller holds
     * the row lock and saves). Returns the transition [from, to] only when
     * the dog moved FORWARD from a known stage (→ new stage images); null
     * otherwise: no change, first assignment, a backward move (an admin edit
     * of the stage boundaries — the stage follows, no new images), unborn
     * pet, no stage data, legacy profile (never touched).
     *
     * @return array{from: LifeStage, to: LifeStage}|null
     */
    public function syncStage(Pet $pet, CarbonInterface $now): ?array
    {
        if ($pet->isUnborn() || $pet->isLegacyProfile()) {
            return null;
        }

        $stage = $this->rulesOn($pet, $pet->localDate($now))->lifeStage;
        $previous = $pet->life_stage;
        if ($stage === null || $previous === $stage) {
            return null;
        }

        $pet->life_stage = $stage;

        if (! $previous instanceof LifeStage) {
            return null;
        }

        $order = array_flip(array_map(fn (LifeStage $s) => $s->value, LifeStage::ordered()));

        return $order[$stage->value] > $order[$previous->value] ? ['from' => $previous, 'to' => $stage] : null;
    }

    /**
     * The next stage change of a born pet: the stage and the family-local
     * date from which its rules apply. Null for an unborn pet, a senior, a
     * legacy-profile pet or without stage data.
     *
     * @return array{stage: LifeStage, date: string}|null
     */
    public function nextTransition(Pet $pet, CarbonInterface $now): ?array
    {
        if ($pet->born_at === null || $pet->isLegacyProfile()) {
            return null;
        }

        $slug = $pet->breed_type->slug();
        $age = $this->ageMonthsOn($pet, $pet->localDate($now));
        foreach ($this->stageStarts($slug) as $value => $from) {
            if ($from > $age) {
                $tz = $pet->familyTimezone();
                $born = CarbonImmutable::instance($pet->born_at)->setTimezone($tz);
                $at = $this->anniversary($born, $from - (int) $pet->arrival_age_months, $tz);
                // Rules follow the age at local midnight: the day after the
                // birthday (or the day itself when born exactly at midnight).
                $date = $at->equalTo($at->startOfDay()) ? $at->toDateString() : $at->addDay()->toDateString();

                return ['stage' => LifeStage::from($value), 'date' => $date];
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    //  Rules
    // ──────────────────────────────────────────────────────────────

    public function rulesAt(Pet $pet, CarbonInterface $at, ?BreedConfig $config = null): StageRules
    {
        return $this->rulesOn($pet, $pet->localDate($at), $config);
    }

    /**
     * Care rules of a family-local date.
     */
    public function rulesOn(Pet $pet, string $localDate, ?BreedConfig $config = null): StageRules
    {
        $config ??= $pet->breedConfig();
        $slug = $pet->breed_type->slug();
        $age = $this->ageMonthsOn($pet, $localDate);
        // Legacy profile → always the pre-M5 rules (grandfathered).
        $stage = $age !== null ? $this->stageForAge($slug, $age) : null;
        $breedWindows = $this->breedWindows($config);

        if ($stage === null) {
            // Pre-M5 rules (legacy profile or no stage data). The raw breed
            // windows: CareScheduleService validates them (and logs a broken
            // config) as before.
            return new StageRules(
                date: $localDate,
                ageMonths: $age,
                lifeStage: null,
                mealsPerDay: count($breedWindows),
                feedWindows: array_values((array) ($config->feed_windows ?? [])),
                exerciseMinutes: null,
                stepGoal: max(0, (int) ($config->daily_steps_required ?? 0)),
                sleepHours: null,
                provenance: [],
            );
        }

        $params = $this->paramsFor($slug);
        $provenance = [];
        $use = function (?array $row) use (&$provenance): mixed {
            if ($row !== null) {
                $provenance[$row['key']] = ['source_id' => $row['source_id'], 'verified' => $row['verified'], 'confidence' => $row['confidence']];
            }

            return $row['value'] ?? null;
        };

        // The stage itself rests on the stage boundary and the age at
        // arrival: their provenance counts for `data_verified` too.
        $use($this->row($params, $stage->value, 0, StageParamKey::StartsAtMonths));
        $arrivalStage = $this->stageForAge($slug, (int) $pet->arrival_age_months);
        if ($arrivalStage !== null) {
            $use($this->row($params, $arrivalStage->value, 0, StageParamKey::ArrivalAgeMonths));
        }

        // Meals and their windows (same band).
        $mealsRow = $this->band($params, $stage, StageParamKey::MealsPerDay, $age);
        $meals = is_int($mealsRow['value'] ?? null) && $mealsRow['value'] > 0 ? $mealsRow['value'] : null;
        $use($mealsRow);
        $windows = $breedWindows;
        if ($meals !== null) {
            $windowsRow = $this->row($params, $mealsRow['stage'], $mealsRow['from'], StageParamKey::FeedWindows);
            if ($windowsRow !== null && is_array($windowsRow['value']) && count($windowsRow['value']) === $meals) {
                $windows = array_values(array_map(fn (array $w): array => [(string) $w[0], (string) $w[1]], $windowsRow['value']));
                $use($windowsRow);
            } elseif (count($breedWindows) !== $meals) {
                $windows = $this->derivedWindows($meals);
                Log::warning('LifeStageService: no feed windows for the meal count, derived them', ['breed_slug' => $slug, 'meals' => $meals]);
            }
        }

        // Exercise minutes → step goal.
        $perAge = $use($this->band($params, $stage, StageParamKey::ExerciseMinutesPerAgeMonth, $age));
        $stageMinutes = $this->band($params, $stage, StageParamKey::ExerciseMinutesPerDay, $age);
        $adultMinutes = $this->band($params, LifeStage::Adult, StageParamKey::ExerciseMinutesPerDay, PHP_INT_MAX);
        $minutes = null;
        if (is_int($perAge)) {
            $minutes = $perAge * $age;
            $cap = $adultMinutes['value'] ?? $stageMinutes['value'] ?? null;
            if (is_int($cap)) {
                $minutes = min($minutes, $cap);
                $use($cap === ($adultMinutes['value'] ?? null) ? $adultMinutes : $stageMinutes);
            }
        } else {
            $row = $stageMinutes ?? $adultMinutes;
            $minutes = is_int($row['value'] ?? null) ? $row['value'] : null;
            $use($row);
        }

        $stepsPerMinute = $use($this->band($params, $stage, StageParamKey::StepsPerExerciseMinute, $age));
        $goal = $minutes !== null && is_int($stepsPerMinute)
            ? $minutes * $stepsPerMinute
            : max(0, (int) ($config->daily_steps_required ?? 0));
        $cap = $config?->daily_steps_cap;
        if ($cap !== null && (int) $cap > 0) {
            $goal = min($goal, (int) $cap);
        }

        $sleep = $use($this->band($params, $stage, StageParamKey::SleepHours, $age));

        return new StageRules(
            date: $localDate,
            ageMonths: $age,
            lifeStage: $stage,
            mealsPerDay: $meals ?? count($windows),
            feedWindows: $windows,
            exerciseMinutes: $minutes,
            stepGoal: max(0, $goal),
            sleepHours: is_array($sleep) ? [$sleep[0], $sleep[1]] : null,
            provenance: $provenance,
        );
    }

    /**
     * Evenly spread windows (Claude proposal, same as the seeded ones):
     * first 07:00, last 19:00, 1 hour each.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function derivedWindows(int $meals): array
    {
        if ($meals <= 1) {
            return [['07:00', '08:00']];
        }

        $windows = [];
        $span = (self::DERIVED_LAST_HOUR - self::DERIVED_FIRST_HOUR) * 60;
        for ($i = 0; $i < $meals; $i++) {
            $start = self::DERIVED_FIRST_HOUR * 60 + intdiv($span * $i, $meals - 1);
            $windows[] = [sprintf('%02d:%02d', intdiv($start, 60), $start % 60), sprintf('%02d:%02d', intdiv($start + 60, 60), ($start + 60) % 60)];
        }

        return $windows;
    }

    /**
     * @param  list<array<string, mixed>>  $params
     * @return array<string, mixed>|null
     */
    private function band(array $params, LifeStage $stage, StageParamKey $key, int $age): ?array
    {
        $best = null;
        foreach ($params as $row) {
            if ($row['key'] === $key->value && $row['stage'] === $stage->value && $row['from'] <= $age
                && ($best === null || $row['from'] > $best['from'])) {
                $best = $row;
            }
        }

        return $best ?? $this->row($params, BreedStageParam::STAGE_ALL, 0, $key);
    }

    /**
     * @param  list<array<string, mixed>>  $params
     * @return array<string, mixed>|null
     */
    private function row(array $params, string $stage, int $from, StageParamKey $key): ?array
    {
        foreach ($params as $row) {
            if ($row['key'] === $key->value && $row['stage'] === $stage && $row['from'] === $from) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function breedWindows(?BreedConfig $config): array
    {
        $windows = [];
        foreach ((array) ($config?->feed_windows ?? []) as $w) {
            if (is_array($w) && is_string($w[0] ?? null) && is_string($w[1] ?? null)) {
                $windows[] = [$w[0], $w[1]];
            }
        }

        return $windows !== [] ? $windows : BreedConfig::DEFAULT_FEED_WINDOWS;
    }
}
