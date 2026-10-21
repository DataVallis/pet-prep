<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T06 — pet preparation (APP_REFINEMENT_SPEC §13, David 2026-10-20).
 *
 *  - `pets.preparation_started_at`: the birth (first contract) of a pet whose
 *    media were NOT all stored at that moment — the child sees »Pripravljamo …«.
 *    Null = the pet was ready at birth (or was born before M5-T06): no
 *    preparation flow, no "ready" news.
 *  - `pets.preparation_ready_at`: when the last entitled media item was stored
 *    (written once, PetPreparationService::settle). Measures the preparation:
 *    ready_at − started_at.
 *  - push type `pet_ready` (caretaker children, once per pet).
 *
 * Additive, nothing backfilled (existing pets keep null — no news for them).
 */
return new class extends Migration
{
    private const PUSH_TYPES_OLD = "'soft_warning', 'critical_alert', 'walk_reminder', 'parent_intervention_alarm', 'illness_triggered', "
        ."'game_over_virtual_shelter', 'trial_ending', 'payment_required', 'play_reminder', 'litter_reminder', 'challenge_completed'";

    private const PUSH_TYPES_NEW = self::PUSH_TYPES_OLD.", 'pet_ready'";

    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('preparation_started_at')->nullable();
            $table->timestamp('preparation_ready_at')->nullable();
        });

        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_NEW.'))');
    }

    public function down(): void
    {
        DB::table('push_notifications')->where('type', 'pet_ready')->delete();
        DB::statement('ALTER TABLE push_notifications DROP CONSTRAINT IF EXISTS push_notifications_type_check');
        DB::statement('ALTER TABLE push_notifications ADD CONSTRAINT push_notifications_type_check CHECK (type IN ('.self::PUSH_TYPES_OLD.'))');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['preparation_started_at', 'preparation_ready_at']);
        });
    }
};
