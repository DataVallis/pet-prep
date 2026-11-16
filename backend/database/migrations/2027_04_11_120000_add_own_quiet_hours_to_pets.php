<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiet hours per pet (David 2026-11-16: »Mi imamo 3 pse in mačko … ko
 * nastavim school hours mi avtomatsko za vse živali velja. To ni ok.«).
 * `pets.own_quiet_hours` null = the pet follows the family's quiet hours
 * (the `quiet_hours` row of its family, unchanged); a JSON object
 * {school_start, school_end, bedtime_start, bedtime_end, is_active} = the
 * pet's own schedule (Pet::quietHours(), written only by
 * PetQuietHoursService). Same pattern as `pets.timezone` (#891).
 *
 * Additive and nullable: no backfill — every existing pet keeps following
 * its family, and every existing `quiet_hours` row stays the family row.
 * Adding a nullable column without a default is a catalogue-only change in
 * PostgreSQL (no table rewrite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->jsonb('own_quiet_hours')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('own_quiet_hours');
        });
    }
};
