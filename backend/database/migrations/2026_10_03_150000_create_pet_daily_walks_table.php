<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily walk rule (David, 2026-10-03, PRODUCT_SPEC §5/§7).
 *
 * Energy is the daily walk, not an hourly neglect metric. At the family-local
 * midnight the decay tick closes the finished day: one `pet_daily_walks` row
 * (steps, breed goal, achieved) for the parent dashboard. If the finished day
 * ended with energy showing 0 % (no walk at all), the dog falls ill at the end
 * of that night's quiet hours: `pets.walk_illness_due_at` holds the planned
 * start until EscalationService starts the illness (or drops it while the pet
 * is frozen). The neglect clock `energy_zero_since` is no longer used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_daily_walks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->date('local_date');
            $table->unsignedInteger('steps');
            $table->unsignedInteger('goal');
            $table->boolean('achieved');
            $table->boolean('birth_day')->default(false);
            $table->timestamp('illness_due_at')->nullable();
            $table->timestamp('illness_started_at')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'local_date']);
        });

        DB::statement('ALTER TABLE pet_daily_walks ADD CONSTRAINT pet_daily_walks_steps_check CHECK (steps >= 0 AND goal >= 0)');

        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('walk_illness_due_at')->nullable()->after('illness_until');
        });

        // Energy no longer drives neglect clocks.
        DB::table('pets')->whereNotNull('energy_zero_since')->update(['energy_zero_since' => null]);
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('walk_illness_due_at');
        });

        Schema::dropIfExists('pet_daily_walks');
    }
};
