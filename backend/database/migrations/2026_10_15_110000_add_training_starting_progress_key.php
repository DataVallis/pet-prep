<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M5-R03b (David 2026-10-06): a dog arriving young, adult or senior already
 * knows some commands. New `breed_stage_params` stage key
 * `training_starting_progress` ({command: percent}, one row per stage, seeded
 * by BreedStageParamsSeeder) — mirrored in breed_stage_params_key_check.
 *
 * down() deletes the rows of the key and restores the previous check.
 */
return new class extends Migration
{
    private const KEYS_OLD = "'starts_at_months', 'arrival_age_months', 'meals_per_day', 'feed_windows', "
        ."'exercise_minutes_per_day', 'exercise_minutes_per_age_month', 'sleep_hours', "
        ."'steps_per_exercise_minute', 'adult_weight_kg', 'house_trained_by_months', 'teething_months', "
        ."'growth_end_months', 'coren_rank', 'lifespan_years', 'accident_hold_hours_per_age_month', 'chewing_chance_per_day', "
        ."'training_learning_multiplier', 'training_individual_variation', 'training_minutes_per_day', "
        ."'training_progress_per_success', 'training_decay_per_missed_day', 'potty_training_accident_reduction', "
        ."'place_training_chewing_reduction'";

    private const NEW_KEY = 'training_starting_progress';

    public function up(): void
    {
        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_OLD.", '".self::NEW_KEY."'))");
    }

    public function down(): void
    {
        DB::table('breed_stage_params')->where('key', self::NEW_KEY)->delete();
        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_OLD.'))');
    }
};
