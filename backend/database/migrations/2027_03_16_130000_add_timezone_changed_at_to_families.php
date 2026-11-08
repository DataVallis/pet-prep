<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family time zone setting (David 2026-11-08, »kar B«): when a parent last
 * changed `families.timezone` — the at-most-once-per-24-h limit
 * (FamilySettingsService::MIN_HOURS_BETWEEN_CHANGES). Null = never changed
 * since sign-up (the sign-up zone does not count). Additive and nullable: no
 * backfill, every existing family may change once right away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->timestamp('timezone_changed_at')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('timezone_changed_at');
        });
    }
};
