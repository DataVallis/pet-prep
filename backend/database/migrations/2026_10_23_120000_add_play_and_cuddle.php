<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R05 play & cuddle (David 2026-10-07 / 2026-10-08, PLAY_CUDDLE_SPEC §12.4):
 * mood and video only — never a score, routine, metric or illness.
 *
 *  - pet_play_events: the dog's invitations (2 per family-local day,
 *    server-scheduled) and every completed ball game / cuddle. A free play
 *    that completes an open invitation updates that invitation row, so the
 *    day's count = `done` rows of the day. NOT pet_hygiene_events (every
 *    hygiene row is a scored `clean` routine).
 *  - pets.happy_until: end of the 30-minute "happy" scene after a play.
 *  - pets.play_scheduled_through: last family-local day whose invitations
 *    were decided (like behaviour_scheduled_through; hidden).
 *  - activities_log: `played_with_pet`, `cuddled_pet` (parent timeline only;
 *    RoutineLedgerService / CareScoreService use explicit allow-lists).
 *
 * Additive; down() removes the rows / columns and restores the check.
 */
return new class extends Migration
{
    private const ACTIVITY_TYPES_OLD = "'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract', 'parent_fed_pet', "
        ."'took_out_pet', 'resolved_chewing', 'pet_accident', 'pet_chewed', 'trained_pet'";

    private const ACTIVITY_TYPES_NEW = self::ACTIVITY_TYPES_OLD.", 'played_with_pet', 'cuddled_pet'";

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('happy_until')->nullable();
            $table->date('play_scheduled_through')->nullable();
        });

        Schema::create('pet_play_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('source', 16);
            $table->date('local_date');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 16);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'local_date', 'status']);
        });

        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_kind_check CHECK (kind IN ('play', 'cuddle'))");
        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_source_check CHECK (source IN ('invitation', 'free'))");
        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_status_check CHECK (status IN ('pending', 'done', 'expired', 'skipped'))");
        // A free play is always a completed play with no schedule.
        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_free_check CHECK (source <> 'free' OR (status = 'done' AND scheduled_at IS NULL AND expires_at IS NULL))");
        // An invitation always has its time and its end.
        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_invitation_check CHECK (source <> 'invitation' OR (scheduled_at IS NOT NULL AND expires_at IS NOT NULL))");
        // A done row knows when it was completed.
        DB::statement("ALTER TABLE pet_play_events ADD CONSTRAINT pet_play_events_done_check CHECK (status <> 'done' OR completed_at IS NOT NULL)");
        // One invitation per kind and family-local day (re-scheduling is insert-or-ignore).
        DB::statement("CREATE UNIQUE INDEX pet_play_events_one_invitation ON pet_play_events (pet_id, local_date, kind) WHERE source = 'invitation'");

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('activities_log')->whereIn('activity_type', ['played_with_pet', 'cuddled_pet'])->delete();
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.'))');

        Schema::dropIfExists('pet_play_events');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['happy_until', 'play_scheduled_through']);
        });
    }
};
