<?php

namespace App\Enums;

/**
 * Keys of `breed_stage_params` (M5-R01). Each row holds ONE value with its
 * provenance (source id from docs/research/dog-data/sources.md S1–S47 for
 * dogs, docs/research/cat-data/sources.md C1–C25 for cats, confidence,
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
 *  - M5-R06-03 cat keys (values only — the rules that read them are
 *    M5-R06-04 / 05): stage keys PLAY_SESSIONS_PER_DAY and
 *    LITTER_USES_PER_DAY (kitten vs. grown cat), breed keys
 *    PLAY_MIN_GAP_MINUTES, LITTER_SCOOP_DEADLINE_HOURS,
 *    LITTER_FULL_CHANGE_DAYS, GROOMING_SESSIONS_PER_WEEK (Maine Coon 3, Ragdoll 2,
 *    British Shorthair 1; no row = no grooming routine) and SCRATCHING_AFTER_MISSED_PLAY (bool).
 *  - M5-R17 stage key SOCIALISATION_TASKS_PER_WEEK (David 2026-10-12): the
 *    puppy's weekly "new experience" (1, stage puppy only; every dog breed since
 *    David 2026-10-12 — no row = no task, cats never). Never scored.
 *  - M5-R16 dog breed key MEAL_EXERCISE_GAP_MINUTES (David 2026-10-12, "1 ura,
 *    samo opozorilo"): minutes after a meal during which the child app shows
 *    "wait with the walk, the dog is digesting" (GDV / bloat advice of an A/B
 *    source). Only a warning — steps still count, no score. No row = no warning.
 *  - M5-R14 / R15 / R18 / R19 / R20 dog breed keys (David 2026-10-24): the breed
 *    care tasks `*_FREQUENCY` = [times, period_days] (e.g. [7, 7] = every day,
 *    [1, 28] = once a month — a month is 4 program weeks), only where the
 *    breed's source states a frequency (no row = no task): TOOTH_BRUSHING,
 *    FOLD_CLEANING, EAR_CLEANING, NAIL_TRIM (the child's mini-games) and
 *    GROOMER_VISIT, BATH (parent reminders, never scored); NO_WALK_IN_HEAT
 *    (true: flat-faced breeds whose source says "never in the heat of the day" —
 *    the summer midday walk warning). See CareTask.
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
    // M5-R06-03 cat (stage keys): kitten 3 / grown cat 2 (CAT_SPEC Q1, Q3)
    case PlaySessionsPerDay = 'play_sessions_per_day';
    case LitterUsesPerDay = 'litter_uses_per_day';

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
    // M5-R06-03 cat (breed keys)
    case PlayMinGapMinutes = 'play_min_gap_minutes';
    case LitterScoopDeadlineHours = 'litter_scoop_deadline_hours';
    case LitterFullChangeDays = 'litter_full_change_days';
    case GroomingSessionsPerWeek = 'grooming_sessions_per_week';
    case ScratchingAfterMissedPlay = 'scratching_after_missed_play';
    // M5-R17 stage key (puppy only): "new experience" tasks per program week.
    case SocialisationTasksPerWeek = 'socialisation_tasks_per_week';
    // M5-R16 dog (breed key): digestion warning after a meal (bloat / GDV advice)
    case MealExerciseGapMinutes = 'meal_exercise_gap_minutes';
    // M5-R14 / R15 / R18 / R19 / R20 dog (breed keys): care task frequencies [times, period_days]
    case ToothBrushingFrequency = 'tooth_brushing_frequency';
    case FoldCleaningFrequency = 'fold_cleaning_frequency';
    case EarCleaningFrequency = 'ear_cleaning_frequency';
    case NailTrimFrequency = 'nail_trim_frequency';
    case GroomerVisitFrequency = 'groomer_visit_frequency';
    case BathFrequency = 'bath_frequency';
    // M5-R14 dog (breed key): true = warn before a walk at summer midday (no weather data)
    case NoWalkInHeat = 'no_walk_in_heat';

    /** M5-R14 / R15 / R18 / R19 / R20: the care task frequency keys. */
    public function isCareTaskFrequency(): bool
    {
        return in_array($this, [
            self::ToothBrushingFrequency, self::FoldCleaningFrequency, self::EarCleaningFrequency,
            self::NailTrimFrequency, self::GroomerVisitFrequency, self::BathFrequency,
        ], true);
    }

    public function isBreedLevel(): bool
    {
        return in_array($this, [
            self::StepsPerExerciseMinute, self::AdultWeightKg, self::HouseTrainedByMonths,
            self::TeethingMonths, self::GrowthEndMonths, self::CorenRank, self::LifespanYears,
            self::TrainingLearningMultiplier, self::TrainingIndividualVariation, self::TrainingMinutesPerDay,
            self::TrainingProgressPerSuccess, self::TrainingDecayPerMissedDay,
            self::PottyTrainingAccidentReduction, self::PlaceTrainingChewingReduction,
            self::PlayMinGapMinutes, self::LitterScoopDeadlineHours, self::LitterFullChangeDays,
            self::GroomingSessionsPerWeek, self::ScratchingAfterMissedPlay,
            self::MealExerciseGapMinutes,
            self::ToothBrushingFrequency, self::FoldCleaningFrequency, self::EarCleaningFrequency,
            self::NailTrimFrequency, self::GroomerVisitFrequency, self::BathFrequency, self::NoWalkInHeat,
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
            self::PlaySessionsPerDay, self::LitterUsesPerDay => is_int($value) && $value >= 0 && $value <= 12
                ? null : 'Expected a whole number per day, 0–12.',
            self::PlayMinGapMinutes => is_int($value) && $value >= 0 && $value <= 1440
                ? null : 'Expected whole minutes, 0–1440.',
            self::LitterScoopDeadlineHours => $isNumber($value) && $value > 0 && $value <= 24
                ? null : 'Expected hours > 0 and ≤ 24.',
            self::LitterFullChangeDays => is_int($value) && $value >= 1 && $value <= 60
                ? null : 'Expected whole days, 1–60.',
            self::GroomingSessionsPerWeek => is_int($value) && $value >= 0 && $value <= 7
                ? null : 'Expected whole sessions per week, 0–7.',
            self::ScratchingAfterMissedPlay => is_bool($value)
                ? null : 'Expected true or false.',
            self::SocialisationTasksPerWeek => is_int($value) && $value >= 0 && $value <= 7
                ? null : 'Expected whole tasks per week, 0–7.',
            self::MealExerciseGapMinutes => is_int($value) && $value >= 1 && $value <= 240
                ? null : 'Expected whole minutes after a meal, 1–240.',
            self::ToothBrushingFrequency, self::FoldCleaningFrequency, self::EarCleaningFrequency,
            self::NailTrimFrequency, self::GroomerVisitFrequency, self::BathFrequency => self::validFrequency($value)
                ? null : 'Expected [times, period_days]: whole numbers, 1 ≤ times ≤ 7, times ≤ period_days ≤ 120.',
            self::NoWalkInHeat => is_bool($value) ? null : 'Expected true or false.',
        };
    }

    /** [times, period_days] — at most one per family-local day, so times ≤ period_days. */
    public static function validFrequency(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) === 2
            && is_int($value[0]) && is_int($value[1])
            && $value[0] >= 1 && $value[0] <= 7 && $value[1] >= 1 && $value[1] <= 120 && $value[0] <= $value[1];
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
