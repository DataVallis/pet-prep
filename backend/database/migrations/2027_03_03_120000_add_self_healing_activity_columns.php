<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-healing activity clips (David 2026-11-02, option B: »it must heal itself«) —
 * ActivityClipRetryService:
 *  - `pet_media.prompt_variant`: which prompt variant the NEXT pose image request uses
 *    (PetAppearancePrompt::posePrompt). Bumped only after fal refused the request
 *    deterministically (`no_media_generated`) — the identical request is never sent again.
 *    On a shared look the LOOK row carries it.
 *  - `pet_media.recovery_version`: which recovery generation already handled this reported
 *    chain (0 = reported before 2026-11-02 → picked up once by the hourly run; the run writes
 *    ActivityClipRetryService::RECOVERY_VERSION with every new report).
 *  - `pets.simple_activities`: {activity: switched-at} — the activities (play / wand / school)
 *    whose automatic recovery is exhausted: they run in the app's no-phase-group mode until
 *    every phase clip of the activity is shown again (GameMediaReadiness::simpleGames()).
 * Additive, defaults only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->unsignedSmallInteger('prompt_variant')->default(0);
            $table->unsignedSmallInteger('recovery_version')->default(0);
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->jsonb('simple_activities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['prompt_variant', 'recovery_version']);
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('simple_activities');
        });
    }
};
