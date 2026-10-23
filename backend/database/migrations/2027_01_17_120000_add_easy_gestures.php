<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T17 — »Lažje kretnje« / "Easier gestures" (David 2026-10-23).
 *
 *  - `users.easy_gestures`: a parent-only switch on a CHILD profile. On = the child app
 *    uses press-and-hold instead of swipe in every swipe game (brushing, cat wand play,
 *    litter change, dog ball play). Off (default) = swipe only. Independent of
 *    `adapted_mode` (M5-T12). An input preference, not health data: no reason is stored.
 *    Game rules, scores, durations and server validation do not change.
 *
 * Additive, nothing backfilled (every existing child keeps swipe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('easy_gestures')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('easy_gestures');
        });
    }
};
