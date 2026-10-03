<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-05: scheduled random hygiene events ("the dog made a mess").
 *
 * Each family-local day gets breed_configs.poops_per_day rows at random
 * instants outside quiet hours. The decay tick applies a pending event once
 * its time has passed inside the interval it decays (hygiene → 0); events
 * that fall in a freeze (hard stop / illness), before the pet existed or into
 * quiet hours changed after scheduling are skipped. Cleaning stamps
 * cleaned_at on applied events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_hygiene_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->date('local_date');
            $table->timestamp('scheduled_at');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'scheduled_at']);
            $table->index(['pet_id', 'status', 'scheduled_at']);
        });

        DB::statement("ALTER TABLE pet_hygiene_events ADD CONSTRAINT pet_hygiene_events_status_check CHECK (status IN ('pending', 'applied', 'skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pet_hygiene_events');
    }
};
