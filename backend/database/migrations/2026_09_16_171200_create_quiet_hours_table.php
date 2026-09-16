<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Quiet Hours: Parent-defined periods during which metric decay
     * is reduced by 90%. Supports two windows:
     * - School hours (e.g., 08:00–13:00)
     * - Bedtime hours (e.g., 22:00–06:00, overnight wrap)
     */
    public function up(): void
    {
        Schema::create('quiet_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->time('school_start')->nullable();
            $table->time('school_end')->nullable();
            $table->time('bedtime_start')->nullable();
            $table->time('bedtime_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Each parent has at most one quiet hours configuration
            $table->unique('parent_id', 'quiet_hours_parent_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiet_hours');
    }
};
