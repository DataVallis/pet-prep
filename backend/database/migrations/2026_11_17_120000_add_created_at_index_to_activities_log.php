<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin dashboard counts care actions per day over up to 12 months
 * (David 2026-10-11); a plain created_at index keeps that range scan cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities_log', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activities_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
