<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-F11 (David 2026-10-12, "Dolij, ko se prazni"): water has no daily cap any
 * more. A refill is allowed when the live thirst shows at or below the breed's
 * `water_refill_below` (percent, default 60) AND `water_min_gap_minutes` have
 * passed since the last refill. `water_times_per_day` stays — only as the Care
 * Score's expected minimum.
 *
 * Every existing row gets the default 60 (the same value BreedConfigsSeeder
 * sets for every breed; the seeder is insert-only and never touches existing
 * rows). CHECK 1..100 like the other breed tunables.
 *
 * Expand-only; down() drops the constraint and the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breed_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('water_refill_below')->default(60);
        });

        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_water_refill_below_check CHECK (water_refill_below BETWEEN 1 AND 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE breed_configs DROP CONSTRAINT IF EXISTS breed_configs_water_refill_below_check');

        Schema::table('breed_configs', function (Blueprint $table) {
            $table->dropColumn('water_refill_below');
        });
    }
};
