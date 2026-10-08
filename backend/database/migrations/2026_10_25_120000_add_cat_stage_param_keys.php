<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R06-03 cat life-stage data (M5-R06_PLAN T10, CAT_SPEC Q1 / Q3 / Q8 / Q10):
 * seven new `breed_stage_params` keys — values only, the rules that read
 * them come with M5-R06-04 / 05. Mirrored in StageParamKey and in
 * breed_stage_params_key_check.
 *
 * The cat rows themselves are seeded by BreedStageParamsSeeder::catRows()
 * (insert-only), which the production deploy runs after `migrate` through
 * BreedConfigsSeeder — the same way the dog rows arrived (M5-R01). No dog
 * row and no breed_configs row is touched.
 *
 * down() deletes the rows of the new keys and restores the previous check.
 */
return new class extends Migration
{
    private const KEYS_OLD = "'starts_at_months', 'arrival_age_months', 'meals_per_day', 'feed_windows', "
        ."'exercise_minutes_per_day', 'exercise_minutes_per_age_month', 'sleep_hours', "
        ."'steps_per_exercise_minute', 'adult_weight_kg', 'house_trained_by_months', 'teething_months', "
        ."'growth_end_months', 'coren_rank', 'lifespan_years', 'accident_hold_hours_per_age_month', 'chewing_chance_per_day', "
        ."'training_learning_multiplier', 'training_individual_variation', 'training_minutes_per_day', "
        ."'training_progress_per_success', 'training_decay_per_missed_day', 'potty_training_accident_reduction', "
        ."'place_training_chewing_reduction', 'training_starting_progress'";

    /** @var list<string> */
    private const NEW_KEYS = [
        'play_sessions_per_day',
        'play_min_gap_minutes',
        'litter_uses_per_day',
        'litter_scoop_deadline_hours',
        'litter_full_change_days',
        'grooming_sessions_per_week',
        'scratching_after_missed_play',
    ];

    public function up(): void
    {
        $new = implode(', ', array_map(fn (string $k): string => "'{$k}'", self::NEW_KEYS));

        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_OLD.', '.$new.'))');
    }

    public function down(): void
    {
        DB::table('breed_stage_params')->whereIn('key', self::NEW_KEYS)->delete();
        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_OLD.'))');
    }
};
