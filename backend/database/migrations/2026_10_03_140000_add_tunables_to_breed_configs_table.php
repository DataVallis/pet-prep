<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-06: breed_configs owns every tunable game number.
 *
 * Column defaults are the mutt values so a breed added later without explicit
 * values gets the free-tier rules; existing rows are backfilled per slug.
 * BreedConfigsSeeder (run on every production deploy) sets the same values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breed_configs', function (Blueprint $table) {
            // Same type as hunger_decay_rate (% per hour, outside quiet hours).
            $table->float('thirst_decay_rate')->default(10);
            // Random hygiene events ("poops") per family-local day, outside quiet hours.
            $table->smallInteger('poops_per_day')->default(1);
            // Family-local [start, end) "HH:MM" pairs in which feeding is allowed (M1-07).
            $table->jsonb('feed_windows')->default(json_encode([['06:00', '10:00'], ['17:00', '21:00']]));
            // Water: max refills per family-local day and minimum gap between them (M1-07).
            $table->smallInteger('water_times_per_day')->default(3);
            $table->smallInteger('water_min_gap_minutes')->default(180);
        });

        DB::table('breed_configs')->where('breed_slug', 'border-collie')->update([
            'thirst_decay_rate' => 15,
            'poops_per_day' => 2,
        ]);

        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_thirst_decay_rate_check CHECK (thirst_decay_rate >= 0)');
        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_poops_per_day_check CHECK (poops_per_day BETWEEN 0 AND 10)');
        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_water_times_per_day_check CHECK (water_times_per_day >= 0)');
        DB::statement('ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_water_min_gap_minutes_check CHECK (water_min_gap_minutes >= 0)');
        DB::statement("ALTER TABLE breed_configs ADD CONSTRAINT breed_configs_feed_windows_check CHECK (jsonb_typeof(feed_windows) = 'array')");
    }

    public function down(): void
    {
        foreach (['thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $column) {
            DB::statement("ALTER TABLE breed_configs DROP CONSTRAINT IF EXISTS breed_configs_{$column}_check");
        }

        Schema::table('breed_configs', function (Blueprint $table) {
            $table->dropColumn(['thirst_decay_rate', 'poops_per_day', 'feed_windows', 'water_times_per_day', 'water_min_gap_minutes']);
        });
    }
};
