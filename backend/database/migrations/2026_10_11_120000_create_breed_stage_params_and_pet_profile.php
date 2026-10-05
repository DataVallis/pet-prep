<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R01 (David 2026-10-05): pet profile (origin + age at arrival), life
 * stages and stage-dependent rules from SOURCED data.
 *
 *  - breed_stage_params: one sourced value per row (breed, stage, optional
 *    sub-band from month, key) with source id / confidence / verified.
 *    Rows are inserted by BreedStageParamsSeeder (insert-only, run on every
 *    deploy through BreedConfigsSeeder) — never by this migration.
 *  - breed_stage_param_changes: audit of every edit (who, old, new).
 *  - breed_configs.daily_steps_cap: optional per-breed cap on the derived
 *    step goal (David: no cap for now → null).
 *  - pets.origin / arrival_age_months / life_stage, all NULLABLE without a
 *    default. Existing pets stay NULL = "legacy profile" (grandfathered,
 *    orchestrator 2026-10-05, pending David): they keep exactly the pre-M5
 *    rules (breed feed windows, daily_steps_required, no parent-covered
 *    meals, no stage transitions, no stage images) until their challenge
 *    ends. Only pets created from a PIN that carries a profile choice
 *    (generate-pin with child_id, M5-R01) get a profile and stage rules.
 *  - child_login_pins.pet_options: the parent's choice {breed, origin,
 *    age_stage} for a new pet, used when the PIN creates the pet.
 *  - activities_log: new type parent_fed_pet (meal in quiet hours).
 *  - pet_media.life_stage + pet_media_history: the reference image follows
 *    the stage; images of earlier stages are kept (growth album, later).
 *
 * Additive only. down() drops the new objects.
 */
return new class extends Migration
{
    private const STAGES = "'puppy', 'young', 'adult', 'senior'";

    private const KEYS = "'starts_at_months', 'arrival_age_months', 'meals_per_day', 'feed_windows', "
        ."'exercise_minutes_per_day', 'exercise_minutes_per_age_month', 'sleep_hours', "
        ."'steps_per_exercise_minute', 'adult_weight_kg', 'house_trained_by_months', 'teething_months', "
        ."'growth_end_months', 'coren_rank', 'lifespan_years'";

    private const ACTIVITY_TYPES_OLD = "'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract'";

    public function up(): void
    {
        Schema::create('breed_stage_params', function (Blueprint $table) {
            $table->id();
            $table->string('breed_slug');
            $table->string('stage');                                  // puppy | young | adult | senior | all (breed-level)
            $table->unsignedSmallInteger('age_from_months')->default(0); // sub-band start inside the stage (0 = stage start)
            $table->string('key');
            $table->jsonb('value')->nullable();
            $table->string('unit')->nullable();
            $table->string('source_id')->nullable();                  // e.g. "S18" or "S11,S15" (docs/research/dog-data/sources.md)
            $table->string('confidence')->default('low');             // high | medium | low
            $table->boolean('verified')->default(false);              // false = UNSOURCED proposal (never shown as fact)
            $table->text('quote')->nullable();
            $table->text('notes')->nullable();
            $table->string('data_ref')->nullable();                   // path in docs/research/dog-data/data.json
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('breed_slug')->references('breed_slug')->on('breed_configs')
                ->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['breed_slug', 'stage', 'age_from_months', 'key'], 'breed_stage_params_unique');
            $table->index(['breed_slug', 'key']);
        });

        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_stage_check CHECK (stage IN ('.self::STAGES.", 'all'))");
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_key_check CHECK (key IN ('.self::KEYS.'))');
        DB::statement("ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_confidence_check CHECK (confidence IN ('high', 'medium', 'low'))");
        DB::statement('ALTER TABLE breed_stage_params ADD CONSTRAINT breed_stage_params_age_from_check CHECK (age_from_months <= 600)');

        Schema::create('breed_stage_param_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('breed_stage_param_id')->nullable()->constrained('breed_stage_params')->nullOnDelete();
            $table->string('breed_slug');
            $table->string('stage');
            $table->unsignedSmallInteger('age_from_months');
            $table->string('key');
            $table->string('action');                                 // created | updated | deleted
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('old')->nullable();
            $table->jsonb('new')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['breed_slug', 'key']);
        });

        DB::statement("ALTER TABLE breed_stage_param_changes ADD CONSTRAINT breed_stage_param_changes_action_check CHECK (action IN ('created', 'updated', 'deleted'))");

        Schema::table('breed_configs', function (Blueprint $table) {
            $table->integer('daily_steps_cap')->nullable();
        });
        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_daily_steps_cap_check CHECK (daily_steps_cap IS NULL OR daily_steps_cap > 0)');

        Schema::table('pets', function (Blueprint $table) {
            // NULL = legacy profile (pet created before M5-R01 / without a profile choice).
            $table->string('origin')->nullable();
            $table->unsignedSmallInteger('arrival_age_months')->nullable();
            $table->string('life_stage')->nullable();
        });
        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_origin_check CHECK (origin IS NULL OR origin IN ('bought', 'adopted'))");
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_arrival_age_months_check CHECK (arrival_age_months IS NULL OR arrival_age_months <= 300)');
        // A legacy pet has no stage: life_stage only with an arrival age.
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_life_stage_profile_check CHECK (life_stage IS NULL OR arrival_age_months IS NOT NULL)');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_life_stage_check CHECK (life_stage IS NULL OR life_stage IN ('.self::STAGES.'))');

        Schema::table('child_login_pins', function (Blueprint $table) {
            $table->jsonb('pet_options')->nullable();
        });

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.", 'parent_fed_pet'))");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('life_stage')->nullable();                 // stage the current generation depicts (image)
        });
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_life_stage_check CHECK (life_stage IS NULL OR life_stage IN ('.self::STAGES.'))');

        Schema::create('pet_media_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('kind');
            $table->string('life_stage')->nullable();
            $table->unsignedSmallInteger('generation');
            $table->string('storage_path')->unique();                 // the file stays on the pet-media disk
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime')->nullable();
            $table->timestamp('archived_at')->useCurrent();

            $table->index(['pet_id', 'archived_at']);
        });
        DB::statement("ALTER TABLE pet_media_history ADD CONSTRAINT pet_media_history_kind_check CHECK (kind IN ('image'))");
        DB::statement('ALTER TABLE pet_media_history ADD CONSTRAINT pet_media_history_life_stage_check CHECK (life_stage IS NULL OR life_stage IN ('.self::STAGES.'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_media_history');

        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_life_stage_check');
        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn('life_stage');
        });

        DB::table('activities_log')->where('activity_type', 'parent_fed_pet')->delete();
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.'))');

        Schema::table('child_login_pins', function (Blueprint $table) {
            $table->dropColumn('pet_options');
        });

        foreach (['pets_origin_check', 'pets_arrival_age_months_check', 'pets_life_stage_check', 'pets_life_stage_profile_check'] as $constraint) {
            DB::statement("ALTER TABLE pets DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['origin', 'arrival_age_months', 'life_stage']);
        });

        DB::statement('ALTER TABLE breed_configs DROP CONSTRAINT IF EXISTS breed_configs_daily_steps_cap_check');
        Schema::table('breed_configs', function (Blueprint $table) {
            $table->dropColumn('daily_steps_cap');
        });

        Schema::dropIfExists('breed_stage_param_changes');
        Schema::dropIfExists('breed_stage_params');
    }
};
