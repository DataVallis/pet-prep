<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds game loop, escalation, and neglect tracking fields to the pets table.
     */
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            // Current pet visual/behavioral state (maps to PetStateEnum)
            $table->string('pet_state')->default('idle')->after('is_active');

            // Thirst level (separate from hunger — decay rates differ per breed)
            $table->integer('thirst_level')->default(100)->after('hunger_level');

            // Illness / vet state lockout: pet is in illness state until this timestamp
            $table->timestamp('illness_until')->nullable()->after('pet_state');

            // Step count tracking for daily walk goals (reset at midnight)
            $table->integer('daily_step_count')->default(0)->after('energy_level');
            $table->timestamp('last_step_reset_at')->nullable()->after('daily_step_count');

            // Escalation level: 0=none, 1=soft warning(30%), 2=critical(10%), 3=parent intervention(0%>1hr)
            $table->integer('escalation_level')->default(0)->after('illness_until');

            // Tracking when each metric first hit 0% — used for neglect calculations
            $table->timestamp('hunger_zero_since')->nullable()->after('escalation_level');
            $table->timestamp('thirst_zero_since')->nullable()->after('hunger_zero_since');
            $table->timestamp('energy_zero_since')->nullable()->after('thirst_zero_since');
            $table->timestamp('hygiene_zero_since')->nullable()->after('energy_zero_since');

            // Game over / Virtual Shelter Protocol flag
            $table->boolean('is_game_over')->default(false)->after('hygiene_zero_since');

            // Responsibility certificate eligibility (12 virtual months reached with satisfactory performance)
            $table->boolean('certificate_eligible')->default(false)->after('is_game_over');
        });

        // DB-level check constraints for new metric
        Schema::getConnection()->statement('ALTER TABLE pets ADD CONSTRAINT pets_thirst_level_check CHECK (thirst_level >= 0 AND thirst_level <= 100)');
        Schema::getConnection()->statement("ALTER TABLE pets ADD CONSTRAINT pets_pet_state_check CHECK (pet_state IN ('idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::getConnection()->statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_pet_state_check');
        Schema::getConnection()->statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_thirst_level_check');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn([
                'pet_state',
                'thirst_level',
                'illness_until',
                'daily_step_count',
                'last_step_reset_at',
                'escalation_level',
                'hunger_zero_since',
                'thirst_zero_since',
                'energy_zero_since',
                'hygiene_zero_since',
                'is_game_over',
                'certificate_eligible',
            ]);
        });
    }
};
