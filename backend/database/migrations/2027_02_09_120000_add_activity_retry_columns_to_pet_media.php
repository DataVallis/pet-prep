<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home first, school and games locked until their clips (David 2026-10-28) — the no-dead-end
 * retry of a failed school / game clip (ActivityClipRetryService), counted per FAILURE CHAIN
 * (QA #434 M1 — never the lifetime `attempts`):
 *  - `auto_retries`: automatic retries of the current chain; reset to 0 by every other new
 *    generation of the slot (stage remake, admin Regenerate / Generate missing, quality retry).
 *    On a shared look the LOOK row carries the count (one budget for every buyer of the look).
 *  - `auto_alerted_at`: when this chain was reported to the admin (one alert per chain,
 *    cleared with the counter).
 * Additive, defaults only — existing rows start a fresh chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->unsignedSmallInteger('auto_retries')->default(0);
            $table->timestamp('auto_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['auto_retries', 'auto_alerted_at']);
        });
    }
};
