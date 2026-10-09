<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R08 (David 2026-10-09): an optional pet name set by a parent of the
 * pet's family (PATCH /api/parent/pets/{pet}/name). Only a label in the apps
 * (child HUD title, parent cards) — never used in push texts, server
 * sentences or AI prompts. At most 20 characters (validated in
 * UpdatePetNameRequest; the column is wider on purpose so a later limit
 * change needs no migration). Additive: every existing pet stays unnamed
 * (null); the name is deleted with the pet row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Never queue behind a long-running transaction on pets (QA n4, M4-10).
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('pets', function (Blueprint $table) {
            $table->string('name', 40)->nullable()->after('species');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
