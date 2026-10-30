<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAMES_SPEC phase 1 — »Zvoki in vibracija v igrah« / "Game sounds and vibration"
 * (David 2026-10-30, GAMES_SPEC §7.6, V5 + V11).
 *
 *  - `users.game_feedback`: a parent-only switch on a CHILD profile. On (default) = the
 *    child app plays game sounds and haptics (they still respect the phone's silent mode).
 *    Off = no game sounds, no haptics. A presentation preference only: no game rule,
 *    score or server validation reads it.
 *
 * Additive; existing rows get the default `true` (sounds are on by default, V5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('game_feedback')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('game_feedback');
        });
    }
};
