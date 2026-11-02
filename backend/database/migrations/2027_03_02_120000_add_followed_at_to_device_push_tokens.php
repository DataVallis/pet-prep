<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared phone follows the pet opened last (David 2026-11-02).
 *
 * `followed_at` = when a CHILD user last registered this install
 * (POST /api/devices — the app calls it on every sign-in / profile switch /
 * cold start). Per Expo token, child-audience pushes go only to the child row
 * with the latest `followed_at`; other child rows of the install are skipped
 * at delivery (kept, so switching back works at once). Parent rows never get
 * a value and are never filtered. No backfill: legacy rows stay null, and a
 * token whose rows are all null keeps the old behaviour (all of them).
 * Microsecond precision so two quick switches never tie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->timestamp('followed_at', 6)->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropColumn('followed_at');
        });
    }
};
