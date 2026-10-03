<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily walk rule follow-up: a planned walk illness that never happened
 * (pet frozen when it came due, or the scheduler was down for the whole
 * 12 h) is recorded as skipped on the walk row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pet_daily_walks', function (Blueprint $table) {
            $table->timestamp('illness_skipped_at')->nullable()->after('illness_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('pet_daily_walks', function (Blueprint $table) {
            $table->dropColumn('illness_skipped_at');
        });
    }
};
