<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frugal clips (David 2026-10-30, switch `frugal_clips`, default off): `pet_media.frugal_source`
 * = the clip a pet's frugal TARGET slot is served from because the switch left it to its source
 * (`school_sit_attention` for come / place / potty attention; `idle_to_sleeping` — reversed with
 * ffmpeg — for sleeping_to_idle). Written only by PetMediaService::queueStateVideos while the
 * switch is on and the slot has no clip of its own; read by MediaEntitlementService::servesFromSource
 * so such a pet keeps being served from the source after the switch is turned off (until it has
 * its own clip). Null everywhere else — every existing row stays null (additive, no backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('frugal_source', 40)->nullable();
        });

        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_frugal_source_check CHECK (frugal_source IS NULL OR frugal_source IN ('school_sit_attention', 'idle_to_sleeping'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_frugal_source_check');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn('frugal_source');
        });
    }
};
