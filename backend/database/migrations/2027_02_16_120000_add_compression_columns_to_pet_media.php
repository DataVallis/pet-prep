<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stored video compression (David 2026-10-30, `media:compress`, Media\VideoCompressionService):
 * the stored AI clips are re-encoded ONCE (H.264 CRF 21, same resolution / fps) at the
 * same path when that saves enough and looks the same (SSIM). Per row that OWNS its file:
 *
 *  - `compression_path`     the storage_path the last compression decision applies to; a
 *                           new file (new generation = new path) is a candidate again, so
 *                           the generation / quality-check pipeline needs no change.
 *  - `compression_status`   compressed | rejected (kept the original — not enough saving,
 *                           SSIM, duration / frames, audio) | failed (ffmpeg / disk error,
 *                           retried up to VideoCompressionService::MAX_ATTEMPTS).
 *  - `compression_attempts` failures on this path.
 *  - `compressed_at`        when the file was replaced.
 *  - `original_bytes`       the size before (pet_media.bytes then holds the new size).
 *  - `compression_note`     why it was rejected / failed, or "-41 %, SSIM 0.991".
 *
 * Additive, nullable — existing rows are candidates (status null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('compression_path')->nullable();
            $table->string('compression_status', 16)->nullable();
            $table->unsignedSmallInteger('compression_attempts')->default(0);
            $table->timestamp('compressed_at')->nullable();
            $table->unsignedBigInteger('original_bytes')->nullable();
            $table->string('compression_note', 500)->nullable();
        });

        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_compression_status_check CHECK (compression_status IS NULL OR compression_status IN ('compressed', 'rejected', 'failed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_compression_status_check');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['compression_path', 'compression_status', 'compression_attempts', 'compressed_at', 'original_bytes', 'compression_note']);
        });
    }
};
