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
        Schema::create('activities_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')
                ->constrained('pets')
                ->cascadeOnDelete();
            $table->string('activity_type');
            $table->integer('value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Index for high-frequency queries: chronological activity lookup per pet
            $table->index(['pet_id', 'created_at'], 'activities_log_pet_id_created_at_index');
        });

        // DB-level check constraint for activity_type enum values
        DB::statement("ALTER TABLE activities_log ADD CONSTRAINT activities_log_activity_type_check CHECK (activity_type IN ('fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop', 'ignored_warning'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE activities_log DROP CONSTRAINT IF EXISTS activities_log_activity_type_check');

        Schema::dropIfExists('activities_log');
    }
};
