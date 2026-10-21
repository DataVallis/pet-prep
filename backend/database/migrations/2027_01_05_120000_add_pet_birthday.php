<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T13 — the pet's first birthday (APP_REFINEMENT_SPEC §9, David 2026-10-20).
 *
 *  - `pets.birthday_celebrated_at`: when the tick marked the pet's 1st birthday
 *    (written once, PetBirthdayService::celebrate — one PetUpdated
 *    `pet_birthday`, one push to the caretaker children and the parents).
 *  - `pets.birthday_next_check_at`: when the tick looks at the pet again (the
 *    family-local start of the birthday, or "never" once it is celebrated or
 *    passed) — so the minutely tick never recomputes every pet.
 *  - push type `pet_birthday`.
 *
 * Additive, nothing backfilled: a pet whose birthday already passed before the
 * deploy simply has none (its first check sees a past date).
 */
return new class extends Migration
{
    private const PUSH_TYPES_OLD = "'soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', "
        ."'game_over_virtual_shelter', 'trial_ending', 'payment_required', 'play_reminder', 'litter_reminder', 'challenge_completed', 'pet_ready'";

    private const PUSH_TYPES_NEW = self::PUSH_TYPES_OLD.", 'pet_birthday'";

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('birthday_celebrated_at')->nullable();
            $table->timestamp('birthday_next_check_at')->nullable();
        });

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', 'pet_birthday')->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_OLD.'))');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['birthday_celebrated_at', 'birthday_next_check_at']);
        });
    }
};
