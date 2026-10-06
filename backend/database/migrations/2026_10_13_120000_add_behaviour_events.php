<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R02 (David 2026-10-06): behaviour events — puppy accidents ("Pelji
 * ven") and chewing ("uničil copat").
 *
 *  - pet_hygiene_events.kind: poop (M1-05, default — every existing row) |
 *    accident (a puppy was not taken out in time) | chewing (teething puppy
 *    or yesterday's walk goal missed). One row = one mess = one cleaning
 *    routine (2 h outside quiet hours). Unique per (pet, kind, instant); at
 *    most one chewing event per pet and family-local day.
 *  - pets.potty_clock_started_at: when the puppy's bladder clock last
 *    restarted (take-out, accident, end of a freeze); the accident is due
 *    `hold hours` of non-quiet time later (BehaviourEventService).
 *  - pets.behaviour_scheduled_through: last family-local day whose chewing
 *    event was decided (like hygiene_scheduled_through).
 *  - pets.behaviour_events_enabled (PR #42 B1): set at creation when the
 *    parent's app build declared `behaviour_events`; false for every
 *    existing pet (old builds have no take-out / tidy-up UI).
 *  - activities_log: took_out_pet / resolved_chewing (child actions) and
 *    pet_accident / pet_chewed (system rows, actor null — parent timeline).
 *  - pet_daily_routines.event_kind: which mess a `clean` routine was.
 *  - pet_media.state: new state videos `accident`, `chewing` (premium set,
 *    PetStateEnum); pets.pet_state keeps the six classic states.
 *  - breed_stage_params keys: accident_hold_hours_per_age_month,
 *    chewing_chance_per_day (rows from BreedStageParamsSeeder).
 *
 * Additive; down() removes the new rows / columns and restores the checks.
 */
return new class extends Migration
{
    private const STATES_OLD = "'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'";

    private const STATES_NEW = self::STATES_OLD.", 'accident', 'chewing'";

    private const ACTIVITY_TYPES_OLD = "'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract', 'parent_fed_pet'";

    private const ACTIVITY_TYPES_NEW = self::ACTIVITY_TYPES_OLD.", 'took_out_pet', 'resolved_chewing', 'pet_accident', 'pet_chewed'";

    private const KEYS_OLD = "'starts_at_months', 'arrival_age_months', 'meals_per_day', 'feed_windows', "
        ."'exercise_minutes_per_day', 'exercise_minutes_per_age_month', 'sleep_hours', "
        ."'steps_per_exercise_minute', 'adult_weight_kg', 'house_trained_by_months', 'teething_months', "
        ."'growth_end_months', 'coren_rank', 'lifespan_years'";

    private const KEYS_NEW = self::KEYS_OLD.", 'accident_hold_hours_per_age_month', 'chewing_chance_per_day'";

    private const KINDS = "'poop', 'accident', 'chewing'";

    public function up(): void
    {
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->string('kind', 16)->default('poop');
        });
        DB::statement('ALTER TABLE pet_hygiene_events ADD CONSTRAINT pet_hygiene_events_kind_check CHECK (kind IN ('.self::KINDS.'))');
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->dropUnique(['pet_id', 'scheduled_at']);
            $table->unique(['pet_id', 'kind', 'scheduled_at']);
        });
        DB::statement("CREATE UNIQUE INDEX pet_hygiene_events_one_chewing_per_day ON pet_hygiene_events (pet_id, local_date) WHERE kind = 'chewing'");

        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('potty_clock_started_at')->nullable();
            $table->date('behaviour_scheduled_through')->nullable();
            // PR #42 B1: only pets created by an app build that declared
            // `behaviour_events` (generate-pin `features`) get the events.
            // Every existing pet: false.
            $table->boolean('behaviour_events_enabled')->default(false);
        });

        Schema::table('pet_daily_routines', function (Blueprint $table) {
            $table->string('event_kind', 16)->nullable();
        });
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_event_kind_check CHECK (event_kind IS NULL OR event_kind IN ('.self::KINDS.'))');

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_NEW.'))');

        // Video slots only: pets.pet_state never takes accident / chewing
        // (pets_pet_state_check stays the six classic states, PR #42 nit).
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = \'image\' AND state IS NULL) OR (kind = \'video\' AND state IN ('.self::STATES_NEW.')))');

        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_NEW.'))');
    }

    public function down(): void
    {
        DB::table('breed_stage_params')->whereIn('key', ['accident_hold_hours_per_age_month', 'chewing_chance_per_day'])->delete();
        DB::statement('ALTER TABLE breed_stage_params DROP CONSTRAINT IF EXISTS breed_stage_params_key_check');
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS_OLD.'))');

        DB::table('pet_media')->whereIn('state', ['accident', 'chewing'])->delete();
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_state_check');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = \'image\' AND state IS NULL) OR (kind = \'video\' AND state IN ('.self::STATES_OLD.')))');

        DB::table('activities_log')->whereIn('activity_type', ['took_out_pet', 'resolved_chewing', 'pet_accident', 'pet_chewed'])->delete();
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.'))');

        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_event_kind_check');
        Schema::table('pet_daily_routines', function (Blueprint $table) {
            $table->dropColumn('event_kind');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['potty_clock_started_at', 'behaviour_scheduled_through', 'behaviour_events_enabled']);
        });

        DB::table('pet_hygiene_events')->where('kind', '!=', 'poop')->delete();
        DB::statement('DROP INDEX IF EXISTS pet_hygiene_events_one_chewing_per_day');
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->dropUnique(['pet_id', 'kind', 'scheduled_at']);
            $table->unique(['pet_id', 'scheduled_at']);
        });
        DB::statement('ALTER TABLE pet_hygiene_events DROP CONSTRAINT IF EXISTS pet_hygiene_events_kind_check');
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
