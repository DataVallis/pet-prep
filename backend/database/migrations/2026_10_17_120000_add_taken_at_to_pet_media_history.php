<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R04 part 2 — growth album.
 *
 * pet_media_history.taken_at: when the archived reference image was stored
 * (became the pet's picture). `archived_at` is when it stopped being current,
 * which is the wrong moment for the album's age label. Filled at archive time
 * by PetMediaService::startStageTransition(); rows archived before this
 * migration stay null and the album falls back to the file's modification
 * time (PetGrowthService) — no backfill that touches the disk here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media_history', function (Blueprint $table) {
            $table->timestamp('taken_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pet_media_history', function (Blueprint $table) {
            $table->dropColumn('taken_at');
        });
    }
};
