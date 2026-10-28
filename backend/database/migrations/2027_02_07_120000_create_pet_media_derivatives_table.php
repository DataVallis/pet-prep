<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School smooth playback (docs/engineering/SCHOOL_SMOOTH_PLAYBACK.md §A, David 2026-10-28):
 * clips DERIVED on our server with ffmpeg from a stored school phase clip — zero AI cost,
 * never through fal / AiSpendGuard / ai_spend_ledger.
 *
 *  - `pet_media_id` = the row that OWNS the source file: a shared-look row (pet_look_id)
 *    or a pet's own unique row — never a pool pet's row that points at a look
 *    (look_media_id), so a derived clip exists once per file.
 *  - `key` = the payload key (`school_<cmd>_attention_loop`, `school_<cmd>_success_loop`,
 *    `school_<cmd>_release`); one row per (source, key).
 *  - `source_path` = the source's storage_path the derived file was made from (or tried):
 *    a different storage_path on the source = stale → made again.
 *  - status ready | failed (attempts per source_path; the backfill gives up after 3).
 *
 * Additive; cascades with the source row (pet deletion / reset).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_media_derivatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_media_id')->constrained('pet_media')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('status', 16);
            $table->string('source_path');
            $table->string('storage_path')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->double('duration_seconds')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['pet_media_id', 'key']);
        });

        DB::statement("ALTER TABLE pet_media_derivatives ADD CONSTRAINT pet_media_derivatives_status_check CHECK (status IN ('ready', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_media_derivatives');
    }
};
