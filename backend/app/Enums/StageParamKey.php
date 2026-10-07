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
 *    keys ACCIDENT_HOLD_HOURS_PER_AGE_MONTH, CHEWING_CHANCE_PER_DAY, and the
 *    M5-R03b TRAINING_STARTING_PROGRESS (the arrival stage's command
 *    progress, {command: percent}, applied once when the pet is created);
 *  - breed keys (stage = all): the rest, including the M5-R03 training
 *    keys (learning multiplier, individual variation, minutes per day,
 *    progress per success, decay per missed day, and the effects of potty /
 *    place training on accidents / chewing).
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
    // M5-R03b training (stage key): starting progress per command of a dog arriving in this stage
    case TrainingStartingProgress = 'training_starting_progress';

    // Breed keys (stage = all)
    case StepsPerExerciseMinute = 'steps_per_exercise_minute';
    case AdultWeightKg = 'adult_weight_kg';
    case HouseTrainedByMonths = 'house_trained_by_months';
    case TeethingMonths = 'teething_months';
    case GrowthEndMonths = 'growth_end_months';
    case CorenRank = 'coren_rank';
    case LifespanYears = 'lifespan_years';
    // M5-R03 training (breed keys)
    case TrainingLearningMultiplier = 'training_learning_multiplier';
    case TrainingIndividualVariation = 'training_individual_variation';
    case TrainingMinutesPerDay = 'training_minutes_per_day';
    case TrainingProgressPerSuccess = 'training_progress_per_success';
    case TrainingDecayPerMissedDay = 'training_decay_per_missed_day';
    case PottyTrainingAccidentReduction = 'potty_training_accident_reduction';
    case PlaceTrainingChewingReduction = 'place_training_chewing_reduction';

    public function isBreedLevel(): bool
    {
        return in_array($this, [
            self::StepsPerExerciseMinute, self::AdultWeightKg, self::HouseTrainedByMonths,
            self::TeethingMonths, self::GrowthEndMonths, self::CorenRank, self::LifespanYears,
            self::TrainingLearningMultiplier, self::TrainingIndividualVariation, self::TrainingMinutesPerDay,
            self::TrainingProgressPerSuccess, self::TrainingDecayPerMissedDay,
            self::PottyTrainingAccidentReduction, self::PlaceTrainingChewingReduction,
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
            self::TrainingLearningMultiplier => $isNumber($value) && $value > 0 && $value <= 10
                ? null : 'Expected a learning speed factor > 0 and ≤ 10 (mixed breed = 1).',
            self::TrainingIndividualVariation, self::PottyTrainingAccidentReduction,
            self::PlaceTrainingChewingReduction => $isNumber($value) && $value <= 1
                ? null : 'Expected a share 0–1.',
            self::TrainingMinutesPerDay => is_int($value) && $value >= 1 && $value <= 60
                ? null : 'Expected whole minutes per day, 1–60.',
            self::TrainingProgressPerSuccess, self::TrainingDecayPerMissedDay => $isNumber($value) && $value <= 100
                ? null : 'Expected percentage points 0–100.',
            self::TrainingStartingProgress => $this->validateStartingProgress($value),
        };
    }

    /**
     * {"sit": 50, "come": 30, "place": 0, "potty": 70}: known commands only,
     * each 0–100 (a missing command starts at 0).
     */
    private function validateStartingProgress(mixed $value): ?string
    {
        $error = 'Expected {"sit"|"come"|"place"|"potty": percent 0–100, …}.';
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return $error;
        }
        foreach ($value as $command => $progress) {
            if (TrainingCommand::tryFrom((string) $command) === null
                || ! (is_int($progress) || is_float($progress)) || $progress < 0 || $progress > 100) {
                return $error;
            }
        }

        return null;
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
