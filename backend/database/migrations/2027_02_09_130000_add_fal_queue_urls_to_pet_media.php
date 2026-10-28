<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 2026-10-28 (David): key images and pose images go through the fal QUEUE
 * (like videos) instead of the 60 s sync endpoint. The queue's status / result
 * URLs of the submitted request are kept on the slot so media:sweep can POLL a
 * request whose webhook got lost (free reads) before it gives up on it.
 * Nullable, no backfill: existing rows never had a queued image request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->text('status_url')->nullable();
            $table->text('response_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['status_url', 'response_url']);
        });
    }
};
