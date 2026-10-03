<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1-04 / M1-05 bookkeeping on pets:
 *  - last_step_sync_at: device time of the last accepted step sync (anti-cheat
 *    reference: at most 200 steps per minute since then);
 *  - hygiene_scheduled_through: last family-local date whose hygiene events
 *    (pet_hygiene_events) have been generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('last_step_sync_at')->nullable()->after('last_step_reset_at');
            $table->date('hygiene_scheduled_through')->nullable()->after('frozen_at');
        });
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn(['last_step_sync_at', 'hygiene_scheduled_through']);
        });
    }
};
