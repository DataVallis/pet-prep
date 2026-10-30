<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAMES_SPEC phase 1 §8.4 (David 2026-10-30): `exit_side` of the dog's `ball_running`
 * phase clip — the side the dog leaves the frame on (`left` | `right` | `center`), measured
 * for free with ffmpeg on our server (SchoolClipDerivativeService, 0 $ — never fal).
 *
 * Stored on the clip's derivative row (key `ball_running_exit_side`, no file:
 * `storage_path` stays null), so it follows the derivative rules: one row per source FILE
 * (look row or unique pet row), stale when the source's storage_path changes, attempts per
 * source file, the same backfill. `exit_side_overridden` = a superadmin set it in the AI
 * media panel (kept until the source clip changes).
 *
 * Additive; existing rows get null / false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_media_derivatives', function (Blueprint $table) {
            $table->string('exit_side', 8)->nullable();
            $table->boolean('exit_side_overridden')->default(false);
        });

        DB::statement("ALTER TABLE pet_media_derivatives ADD CONSTRAINT pet_media_derivatives_exit_side_check CHECK (exit_side IS NULL OR exit_side IN ('left', 'right', 'center'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pet_media_derivatives DROP CONSTRAINT IF EXISTS pet_media_derivatives_exit_side_check');

        Schema::table('pet_media_derivatives', function (Blueprint $table) {
            $table->dropColumn(['exit_side', 'exit_side_overridden']);
        });
    }
};
