<?php

namespace App\Enums;

/**
 * Keys of `breed_stage_params` (M5-R01). Each row holds ONE value with its
 * provenance (source id from docs/research/dog-data/sources.md, confidence,
 * verified). Mirrored in the breed_stage_params_key_check constraint.
 *
 * Scope:
 *  - stage keys (stage = puppy|young|adult|senior, optional sub-band
 *    `age_from_months` inside the stage): STARTS_AT_MONTHS, ARRIVAL_AGE_MONTHS,
 *    MEALS_PER_DAY, FEED_WINDOWS, EXERCISE_MINUTES_PER_DAY,
 *    EXERCISE_MINUTES_PER_AGE_MONTH, SLEEP_HOURS, and the M5-R02 behaviour
 *    keys ACCIDENT_HOLD_HOURS_PER_AGE_MONTH, CHEWING_CHANCE_PER_DAY;
 *  - breed keys (stage = all): the rest.
 */
enum StageParamKey: string
{
    // Stage keys
    case StartsAtMonths = 'starts_at_months';
    case ArrivalAgeMonths = 'arrival_age_months';
    case MealsPerDay = 'meals_per_day';
    case FeedWindows = 'feed_windows';
    case ExerciseMinutesPerDay = 'exercise_minutes_per_day';
    case ExerciseMinutesPerAgeMonth = 'exercise_minutes_per_age_month';
    case SleepHours = 'sleep_hours';
    // M5-R02 behaviour (stage keys; puppy stage only today)
    case AccidentHoldHoursPerAgeMonth = 'accident_hold_hours_per_age_month';
    case ChewingChancePerDay = 'chewing_chance_per_day';

    // Breed keys (stage = all)
    case StepsPerExerciseMinute = 'steps_per_exercise_minute';
    case AdultWeightKg = 'adult_weight_kg';
    case HouseTrainedByMonths = 'house_trained_by_months';
    case TeethingMonths = 'teething_months';
    case GrowthEndMonths = 'growth_end_months';
    case CorenRank = 'coren_rank';
    case LifespanYears = 'lifespan_years';

    public function isBreedLevel(): bool
    {
        return in_array($this, [
            self::StepsPerExerciseMinute, self::AdultWeightKg, self::HouseTrainedByMonths,
            self::TeethingMonths, self::GrowthEndMonths, self::CorenRank, self::LifespanYears,
        ], true);
    }

    /**
     * Shape check for a value of this key (seeder, Filament). Returns an error
     * message or null when valid. JSON null is allowed where a source says
     * "no number" (e.g. senior sleep hours, mixed-breed Coren rank).
     */
    public function validate(mixed $value): ?string
    {
        $isNumber = fn (mixed $v): bool => (is_int($v) || is_float($v)) && $v >= 0;
        $isRange = fn (mixed $v): bool => is_array($v) && array_is_list($v) && count($v) === 2
            && $isNumber($v[0]) && $isNumber($v[1]) && $v[0] <= $v[1];

        return match ($this) {
            self::StartsAtMonths, self::ArrivalAgeMonths, self::MealsPerDay,
            self::ExerciseMinutesPerDay, self::ExerciseMinutesPerAgeMonth,
            self::StepsPerExerciseMinute => is_int($value) && $value >= 0 && $value <= 1000
                ? null : 'Expected a whole number 0–1000.',
            self::FeedWindows => $this->validateWindows($value),
            self::AccidentHoldHoursPerAgeMonth => is_int($value) && $value >= 1 && $value <= 24
                ? null : 'Expected whole hours per month of age, 1–24.',
            self::ChewingChancePerDay => (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1
                ? null : 'Expected a probability 0–1.',
            self::SleepHours, self::AdultWeightKg, self::HouseTrainedByMonths,
            self::TeethingMonths, self::GrowthEndMonths => $value === null || $isRange($value)
                ? null : 'Expected [min, max] (numbers, min ≤ max) or null.',
            self::CorenRank => $value === null || (is_int($value) && $value >= 1)
                ? null : 'Expected a rank ≥ 1 or null (not ranked).',
            self::LifespanYears => $isNumber($value) ? null : 'Expected a number of years.',
        };
    }

    private function validateWindows(mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            return 'Expected a list of ["HH:MM", "HH:MM"] windows.';
        }

        foreach ($value as $window) {
            if (! is_array($window) || count($window) !== 2
                || ! is_string($window[0] ?? null) || ! is_string($window[1] ?? null)
                || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $window[0]) !== 1
                || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $window[1]) !== 1
                || $window[0] === $window[1]) {
                return 'Every window must be ["HH:MM", "HH:MM"] with different start and end.';
            }
        }

        return null;
    }
}
