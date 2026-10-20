<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Lab clip comparison (2026-10-20): a video run may carry a custom motion
 * prompt (replaces the pet-state motion) and a clip-length override. Each
 * video result stores the clip length it was billed for. Admin data only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_lab_runs', function (Blueprint $table) {
            $table->text('motion_prompt')->nullable();                     // admin free text, null = state motion
            $table->unsignedSmallInteger('duration_seconds')->nullable(); // null = each profile's default
        });

        Schema::table('media_lab_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_seconds')->nullable(); // effective clip length (videos)
        });
    }

    public function down(): void
    {
        Schema::table('media_lab_results', function (Blueprint $table) {
            $table->dropColumn('duration_seconds');
        });

        Schema::table('media_lab_runs', function (Blueprint $table) {
            $table->dropColumn(['motion_prompt', 'duration_seconds']);
        });
    }
};
