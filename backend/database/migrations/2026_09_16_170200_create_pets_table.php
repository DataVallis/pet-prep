<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('breed_type');
            $table->integer('hunger_level')->default(100);
            $table->integer('energy_level')->default(100);
            $table->integer('hygiene_level')->default(100);
            $table->timestamp('born_at')->useCurrent();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // DB-level check constraint for breed_type enum values
        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_breed_type_check CHECK (breed_type IN ('mutt', 'border_collie'))");

        // Ensure metric levels stay within 0–100
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_hunger_level_check CHECK (hunger_level >= 0 AND hunger_level <= 100)');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_energy_level_check CHECK (energy_level >= 0 AND energy_level <= 100)');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_hygiene_level_check CHECK (hygiene_level >= 0 AND hygiene_level <= 100)');

        // Index for high-frequency queries: active pet lookup by child user
        Schema::table('pets', function (Blueprint $table) {
            $table->index(['user_id', 'is_active'], 'pets_user_id_is_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_hygiene_level_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_energy_level_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_hunger_level_check');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_breed_type_check');

        Schema::dropIfExists('pets');
    }
};
