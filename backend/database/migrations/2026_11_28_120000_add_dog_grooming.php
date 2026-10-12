<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R13 (David 2026-10-12, "kot pri mački"): dog grooming per breed — the
 * cat's weekly brushing routine (M5-R06-05) for dogs.
 *
 *  - pets.grooming_enabled: set once at creation for a profiled DOG when
 *    both the parent's generate-pin and the child's pin-login declared
 *    `features: ["dog_grooming"]` (ClientFeature) — an older app build has
 *    no "Počeši" button, so its dog never gets a routine it cannot do.
 *    Every existing pet: false. Cats do not read it (a brushed cat's
 *    routine follows its breed row, as before).
 *
 * Everything else already exists (M5-R06-05): breed_stage_params key
 * `grooming_sessions_per_week` (dog rows come from the insert-only seeder),
 * pet_care_sessions kind `grooming`, activity `groomed_pet` /
 * `pet_coat_matted`, routine type `grooming`, pets.coat_matted_at /
 * grooming_weeks_checked.
 *
 * Additive (expand-only); down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->boolean('grooming_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('grooming_enabled');
        });
    }
};
