<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R06-04 — cat rules part 1: play instead of steps (CAT_SPEC Q1 / Q2,
 * §5.2; M5-R06_PLAN T5 / T6). Cats stay hidden (PETPREP_CATS_ENABLED).
 *
 *  - pet_care_sessions (plan T6): server-driven cat care sessions — one
 *    table for `wand_play` (used now), `grooming` and `litter_change`
 *    (M5-R06-05; the kind CHECK is ready). A row = one started mini-game:
 *    the child, the server schedule, start / scheduled end / TTL, the
 *    finish and the server's verdict (`result`). Only `completed` counts.
 *    At most one `active` session per (pet, kind) — partial unique index.
 *    `user_id` = the child (null after the child profile is deleted).
 *  - pets.play_missed_on: the last family-local day whose cat play routine
 *    was MISSED (written at the cat's midnight close) — what M5-R06-05
 *    needs for "scratched the sofa the next day" (CAT_SPEC Q2 / Q10). Hidden.
 *  - activities_log: `played_wand` (a successful wand session; the day's
 *    rows are the cat's play routine).
 *  - pet_daily_routines.routine_type: + `play`.
 *  - push_notifications.type: + `play_reminder` (the cat's daily reminder).
 *
 * Additive; down() removes the new rows / columns and restores the checks.
 */
return new class extends Migration
{
    private const ACTIVITY_TYPES_OLD = "'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract', 'parent_fed_pet', "
        ."'took_out_pet', 'resolved_chewing', 'pet_accident', 'pet_chewed', 'trained_pet', 'played_with_pet', 'cuddled_pet'";

    private const ACTIVITY_TYPES_NEW = self::ACTIVITY_TYPES_OLD.", 'played_wand'";

    private const ROUTINES_OLD = "'feed', 'water', 'clean', 'walk', 'training'";

    private const ROUTINES_NEW = self::ROUTINES_OLD.", 'play'";

    private const PUSH_TYPES_OLD = "'soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', "
        ."'game_over_virtual_shelter', 'trial_ending', 'payment_required'";

    private const PUSH_TYPES_NEW = self::PUSH_TYPES_OLD.", 'play_reminder'";

    public function up(): void
    {
        Schema::create('pet_care_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);
            // Family-local date of the start: the day the session counts for.
            $table->date('local_date');
            $table->timestamp('started_at');
            // When the game has run its course (start + duration).
            $table->timestamp('ends_at');
            // Last moment a finish is accepted (TTL).
            $table->timestamp('expires_at');
            $table->unsignedInteger('duration_ms');
            $table->jsonb('schedule');
            $table->string('status', 16)->default('active');
            $table->timestamp('finished_at')->nullable();
            // The server's verdict: counts of the reported moves, success, reason.
            $table->jsonb('result')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'kind', 'local_date']);
            $table->index(['pet_id', 'kind', 'status', 'finished_at']);
        });
        DB::statement("ALTER TABLE pet_care_sessions ADD CONSTRAINT pet_care_sessions_kind_check CHECK (kind IN ('wand_play', 'grooming', 'litter_change'))");
        DB::statement("ALTER TABLE pet_care_sessions ADD CONSTRAINT pet_care_sessions_status_check CHECK (status IN ('active', 'completed', 'failed', 'aborted', 'expired', 'interrupted'))");
        DB::statement("ALTER TABLE pet_care_sessions ADD CONSTRAINT pet_care_sessions_finished_check CHECK ((status IN ('completed', 'failed')) = (finished_at IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX pet_care_sessions_one_active ON pet_care_sessions (pet_id, kind) WHERE status = 'active'");

        Schema::table('pets', function (Blueprint $table) {
            $table->date('play_missed_on')->nullable();
        });

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_NEW.'))');

        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_type_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_type_check CHECK (routine_type IN ('.self::ROUTINES_NEW.'))');

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', 'play_reminder')->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_OLD.'))');

        DB::table('pet_daily_routines')->where('routine_type', 'play')->delete();
        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_type_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_type_check CHECK (routine_type IN ('.self::ROUTINES_OLD.'))');

        DB::table('activities_log')->where('activity_type', 'played_wand')->delete();
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.'))');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('play_missed_on');
        });

        Schema::dropIfExists('pet_care_sessions');
    }
};
