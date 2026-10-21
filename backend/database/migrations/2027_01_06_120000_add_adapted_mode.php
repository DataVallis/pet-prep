<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-T12 — adapted mode: wheelchair pushes instead of steps (APP_REFINEMENT_SPEC §6,
 * David 2026-10-20).
 *
 *  - `users.adapted_mode`: a parent-only accessibility switch on a CHILD profile
 *    (»Prilagojen način«). Only the switch is stored — never a reason, condition or
 *    diagnosis (minimum data about a minor). Default false; parents never use it.
 *  - `pet_daily_steps.unit`: what the child's phone counted that family-local day —
 *    `steps` or `pushes` (wheelchair pushes, 1 push = 1 step). Only for the parent
 *    report wording; goals, energy, routines and the Care Score read `steps` as before.
 *
 * Additive, nothing backfilled (existing rows are `steps`, every child is off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('adapted_mode')->default(false);
        });

        Schema::table('pet_daily_steps', function (Blueprint $table) {
            $table->string('unit', 8)->default('steps');
        });

        DB::statement("ALTER TABLE pet_daily_steps ADD CONSTRAINT pet_daily_steps_unit_check CHECK (unit IN ('steps', 'pushes'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pet_daily_steps DROP CONSTRAINT IF EXISTS pet_daily_steps_unit_check');

        Schema::table('pet_daily_steps', function (Blueprint $table) {
            $table->dropColumn('unit');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('adapted_mode');
        });
    }
};
