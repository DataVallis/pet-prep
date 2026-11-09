<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time zone per pet (David 2026-11-09): family members in different places
 * (a parent in Lisbon, the children in Ljubljana) each read their own pet's
 * wall clock. `pets.timezone` null = the pet follows `families.timezone`
 * (Pet::effectiveTimezone()); `pets.timezone_changed_at` = the last change
 * of the pet's own zone (PetTimezoneService, at most once per 24 h per pet).
 * Additive and nullable: no backfill — every existing pet keeps following
 * its family.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
            $table->timestamp('timezone_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'timezone_changed_at']);
        });
    }
};
