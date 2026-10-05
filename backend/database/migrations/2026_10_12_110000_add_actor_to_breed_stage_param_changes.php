<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R01b — label of a non-user writer on an audit row (e.g. the one-off
 * "system: David decision 2026-10-05" data migration). Filament shows it when
 * `user_id` is null. Kept apart from the data migration so rolling that one
 * back never drops audit data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('breed_stage_param_changes', function (Blueprint $table) {
            $table->string('actor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('breed_stage_param_changes', function (Blueprint $table) {
            $table->dropColumn('actor');
        });
    }
};
