<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T11 — »Prikaz: Video / Slika« (APP_REFINEMENT_SPEC §1, David 2026-10-20).
 *
 *  - `users.media_display`: how the child app shows the pet — `video` (default, the
 *    state loops) or `picture` (the key image of the current state, no video
 *    download). Set on a CHILD profile by a parent of the family or by the child;
 *    a display preference, not personal data. Parents keep the default (unused).
 *
 * Additive, nothing backfilled (every existing child keeps video).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('media_display', 8)->default('video');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_media_display_check CHECK (media_display IN ('video', 'picture'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_media_display_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('media_display');
        });
    }
};
