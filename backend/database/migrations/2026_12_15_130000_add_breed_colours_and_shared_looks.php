<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R21 — breed colours + shared AI media for paid breeds (David 2026-10-14).
 *
 *  - `pets.coat_colour`: the colour key (config/breed_colours.php) of a pet of
 *    a paid breed — chosen by the parent or sampled by weight (old app builds).
 *    Null for free breeds and pets from before M5-R21.
 *  - `pet_looks.colour_key`: a SHARED look of a paid breed — one per (breed,
 *    colour), reused by every new pet of that breed + colour while the admin
 *    switch "unique AI media per pet" is off. Null = a free breed's pool look
 *    (M4-10). The partial unique index makes two families buying the same
 *    breed + colour at once end up with ONE look (and one generation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->string('coat_colour', 40)->nullable();
        });

        Schema::table('pet_looks', function (Blueprint $table) {
            $table->string('colour_key', 40)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX pet_looks_breed_colour_unique ON pet_looks (breed_type, colour_key) WHERE colour_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pet_looks_breed_colour_unique');

        Schema::table('pet_looks', function (Blueprint $table) {
            $table->dropColumn('colour_key');
        });

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('coat_colour');
        });
    }
};
