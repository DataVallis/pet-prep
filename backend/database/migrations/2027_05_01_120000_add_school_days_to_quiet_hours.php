<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School days in quiet hours (David 2026-11-22, »Starš izbere dneve, privzeto
 * pon–pet«; bug: on Sunday the school window still applied and the cat could
 * not be played with). `quiet_hours.school_days` = jsonb array of distinct ISO
 * weekdays 1..7 (1 = Monday … 7 = Sunday), sorted; `[]` = no school window on
 * any day. Bedtime is not affected (every day).
 *
 *  - Every existing family row is backfilled to [1,2,3,4,5] (the default
 *    Monday–Friday — the value of QuietHours::DEFAULT_SCHOOL_DAYS on
 *    2026-11-22, inlined so this migration never changes with app code).
 *  - Pet schedules (`pets.own_quiet_hours` JSON) are not rewritten: a missing
 *    key there reads as [1,2,3,4,5] (QuietHours::normaliseValues()).
 *  - No game state is rewritten: closed days in pet_daily_routines are never
 *    recomputed; the new rule applies from the next tick (like any change of
 *    quiet hours).
 *
 * down() drops the column (the windows then apply every day again, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiet_hours', function (Blueprint $table): void {
            $table->jsonb('school_days')->nullable();
        });

        DB::table('quiet_hours')->whereNull('school_days')->update(['school_days' => '[1,2,3,4,5]']);

        DB::statement("ALTER TABLE quiet_hours ALTER COLUMN school_days SET DEFAULT '[1,2,3,4,5]'::jsonb");
        DB::statement('ALTER TABLE quiet_hours ALTER COLUMN school_days SET NOT NULL');
        DB::statement("ALTER TABLE quiet_hours ADD CONSTRAINT quiet_hours_school_days_array CHECK (jsonb_typeof(school_days) = 'array')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quiet_hours DROP CONSTRAINT IF EXISTS quiet_hours_school_days_array');

        Schema::table('quiet_hours', function (Blueprint $table): void {
            $table->dropColumn('school_days');
        });
    }
};
