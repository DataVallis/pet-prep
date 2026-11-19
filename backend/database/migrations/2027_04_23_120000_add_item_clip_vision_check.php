<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item content realism check (David 2026-11-19: a bought »Navy cushion bed« looked like a pasted
 * product photo under a big Dobermann lying ON TOP of a too-small bed — »tega ne smemo dopustiti«).
 *
 *  - `item_media_quality_checks.rubric`: the vision rubric that decided the row (`item` = item key
 *    image with the realism / scale questions, `item_clip` = the item clip's AI check; older rows
 *    null = the plain animal / object rubric). `media:item-recheck` selects stored files whose
 *    current generation has no decided check of the current rubric.
 *  - `item_media_quality_checks.sheet_path`: the contact sheet of an item clip sent to the vision
 *    model (kept only for a failed clip, like `pet_media_quality_checks.sheet_path`).
 *  - trigger `recheck` = a `media:item-recheck` check of a stored file.
 *  - `item_media.flagged_at`: when a STORED file was flagged by a check from this change on. Only
 *    such a file is hidden (ItemMedia::isShown()); rows flagged before this migration (null) keep
 *    their old behaviour — still shown, no newly locked treats (QA #1414 M3: deploy-safe without
 *    reading production data). Cleared whenever a new file is stored.
 * Additive (nullable columns, a wider CHECK) — existing rows stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('item_media_quality_checks', function (Blueprint $table) {
            $table->string('rubric')->nullable();
            $table->string('sheet_path')->nullable();
        });

        DB::statement('ALTER TABLE item_media_quality_checks DROP CONSTRAINT IF EXISTS item_media_quality_checks_trigger_check');
        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_trigger_check CHECK (trigger IN ('pipeline', 'command', 'recheck'))");

        Schema::table('item_media', function (Blueprint $table) {
            $table->timestamp('flagged_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        if (DB::table('item_media_quality_checks')->where('trigger', 'recheck')->exists()) {
            throw new RuntimeException('item_media_quality_checks has `recheck` rows — delete them deliberately before rolling back.');
        }

        DB::statement('ALTER TABLE item_media_quality_checks DROP CONSTRAINT IF EXISTS item_media_quality_checks_trigger_check');
        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_trigger_check CHECK (trigger IN ('pipeline', 'command'))");

        Schema::table('item_media_quality_checks', function (Blueprint $table) {
            $table->dropColumn(['rubric', 'sheet_path']);
        });

        Schema::table('item_media', function (Blueprint $table) {
            $table->dropColumn('flagged_at');
        });
    }
};
