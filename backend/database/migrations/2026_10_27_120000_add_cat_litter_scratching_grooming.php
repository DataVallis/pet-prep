<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R06-05 — cat rules part 2: litter, scratching, grooming (CAT_SPEC Q3 /
 * Q8 / Q10, §4, §6, §7; M5-R06_PLAN T6 / T7; David 2026-10-08 ~22:20).
 * Cats stay hidden (PETPREP_CATS_ENABLED).
 *
 *  - pet_hygiene_events.kind: + `litter_use` (the cat used the tray — NOT a
 *    mess, one "scoop the litter" routine), `litter_accident` (the scoop
 *    deadline passed → a mess next to the tray, the dog hygiene ladder),
 *    `scratching` ("scratched the sofa" the day after a missed play routine,
 *    resolved by "carry to the scratcher + praise within 3 s"). At most one
 *    scratching event per pet and family-local day.
 *  - pet_hygiene_events.due_at: the scoop deadline of a `litter_use` (4 h,
 *    2 h while the weekly change is overdue — counted outside quiet hours),
 *    fixed when the use happens. escalated_at: when the tick handled an
 *    unscooped use after its deadline (the accident was written, or skipped
 *    after a freeze / outage) — so it is handled once.
 *  - pets.coat_matted_at: the Maine Coon's matted coat (≥ 2 of the week's 3
 *    combings missed, set at the end of the program week); cleared by the
 *    next (longer) grooming. pets.grooming_weeks_checked: program weeks
 *    whose grooming was already evaluated (pointer).
 *  - pet_care_sessions.kind: + `scratching` (the redirect + praise session).
 *  - activities_log: scooped_litter, changed_litter, groomed_pet,
 *    resolved_scratching (child actions); pet_scratched,
 *    pet_litter_accident, pet_coat_matted (system rows, actor null).
 *  - pet_daily_routines.routine_type: + litter_scoop, litter_change,
 *    grooming; event_kind: + litter_accident, scratching.
 *  - push_notifications.type: + litter_reminder.
 *
 * Additive; down() removes the new rows / columns and restores the checks.
 */
return new class extends Migration
{
    private const KINDS_OLD = "'poop', 'accident', 'chewing'";

    private const KINDS_NEW = self::KINDS_OLD.", 'litter_use', 'litter_accident', 'scratching'";

    private const EVENT_KINDS_NEW = self::KINDS_OLD.", 'litter_accident', 'scratching'";

    private const ACTIVITY_TYPES_OLD = "'fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning', 'signed_contract', 'parent_fed_pet', "
        ."'took_out_pet', 'resolved_chewing', 'pet_accident', 'pet_chewed', 'trained_pet', 'played_with_pet', 'cuddled_pet', 'played_wand'";

    private const ACTIVITY_TYPES_NEW = self::ACTIVITY_TYPES_OLD.", 'scooped_litter', 'changed_litter', 'groomed_pet', 'resolved_scratching', "
        ."'pet_scratched', 'pet_litter_accident', 'pet_coat_matted'";

    private const ROUTINES_OLD = "'feed', 'water', 'clean', 'walk', 'training', 'play'";

    private const ROUTINES_NEW = self::ROUTINES_OLD.", 'litter_scoop', 'litter_change', 'grooming'";

    private const PUSH_TYPES_OLD = "'soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', "
        ."'game_over_virtual_shelter', 'trial_ending', 'payment_required', 'play_reminder'";

    private const PUSH_TYPES_NEW = self::PUSH_TYPES_OLD.", 'litter_reminder'";

    private const SESSION_KINDS_OLD = "'wand_play', 'grooming', 'litter_change'";

    private const SESSION_KINDS_NEW = self::SESSION_KINDS_OLD.", 'scratching'";

    public function up(): void
    {
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
        });
        DB::statement('ALTER TABLE pet_hygiene_events DROP CONSTRAINT IF EXISTS pet_hygiene_events_kind_check');
        DB::statement('ALTER TABLE pet_hygiene_events ADD CONSTRAINT pet_hygiene_events_kind_check CHECK (kind IN ('.self::KINDS_NEW.'))');
        DB::statement("CREATE UNIQUE INDEX pet_hygiene_events_one_scratching_per_day ON pet_hygiene_events (pet_id, local_date) WHERE kind = 'scratching'");

        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('coat_matted_at')->nullable();
            $table->unsignedInteger('grooming_weeks_checked')->nullable();
        });

        DB::statement('ALTER TABLE pet_care_sessions DROP CONSTRAINT IF EXISTS pet_care_sessions_kind_check');
        DB::statement('ALTER TABLE pet_care_sessions ADD CONSTRAINT pet_care_sessions_kind_check CHECK (kind IN ('.self::SESSION_KINDS_NEW.'))');

        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_NEW.'))');

        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_type_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_type_check CHECK (routine_type IN ('.self::ROUTINES_NEW.'))');
        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_event_kind_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_event_kind_check CHECK (event_kind IS NULL OR event_kind IN ('.self::EVENT_KINDS_NEW.'))');

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', 'litter_reminder')->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_OLD.'))');

        DB::table('pet_daily_routines')->whereIn('routine_type', ['litter_scoop', 'litter_change', 'grooming'])->delete();
        DB::table('pet_daily_routines')->whereIn('event_kind', ['litter_accident', 'scratching'])->delete();
        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_type_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_type_check CHECK (routine_type IN ('.self::ROUTINES_OLD.'))');
        DB::statement('ALTER TABLE pet_daily_routines DROP CONSTRAINT IF EXISTS pet_daily_routines_event_kind_check');
        DB::statement('ALTER TABLE pet_daily_routines ADD CONSTRAINT pet_daily_routines_event_kind_check CHECK (event_kind IS NULL OR event_kind IN ('.self::KINDS_OLD.'))');

        DB::table('activities_log')->whereIn('activity_type', ['scooped_litter', 'changed_litter', 'groomed_pet', 'resolved_scratching',
            'pet_scratched', 'pet_litter_accident', 'pet_coat_matted'])->delete();
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');
        DB::statement('ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('.self::ACTIVITY_TYPES_OLD.'))');

        DB::table('pet_care_sessions')->where('kind', 'scratching')->delete();
        DB::statement('ALTER TABLE pet_care_sessions DROP CONSTRAINT IF EXISTS pet_care_sessions_kind_check');
        DB::statement('ALTER TABLE pet_care_sessions ADD CONSTRAINT pet_care_sessions_kind_check CHECK (kind IN ('.self::SESSION_KINDS_OLD.'))');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['coat_matted_at', 'grooming_weeks_checked']);
        });

        DB::table('pet_hygiene_events')->whereIn('kind', ['litter_use', 'litter_accident', 'scratching'])->delete();
        DB::statement('DROP INDEX IF EXISTS pet_hygiene_events_one_scratching_per_day');
        DB::statement('ALTER TABLE pet_hygiene_events DROP CONSTRAINT IF EXISTS pet_hygiene_events_kind_check');
        DB::statement('ALTER TABLE pet_hygiene_events ADD CONSTRAINT pet_hygiene_events_kind_check CHECK (kind IN ('.self::KINDS_OLD.'))');
        Schema::table('pet_hygiene_events', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'escalated_at']);
        });
    }
};
