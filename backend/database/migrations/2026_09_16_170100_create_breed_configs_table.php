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
        Schema::create('breed_configs', function (Blueprint $table) {
            $table->id();
            $table->string('breed_slug')->unique();
            $table->integer('daily_steps_required');
            $table->float('hunger_decay_rate');
            $table->boolean('premium_unlock')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('breed_configs');
    }
};
