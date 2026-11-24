<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quiet hours change log (David 2026-11-24, »ja naredi«): one row per parent
 * save that actually changed a schedule — the family's `quiet_hours` row or a
 * pet's own hours (`pets.own_quiet_hours`). Written only by
 * App\Services\QuietHoursChangeLog. Shown read-only in /admin (UserResource)
 * and part of the family export; deleted with the family.
 *
 * Why: a tester family's quiet hours were overwritten 16 s after sign-up by a
 * save with every time null; request logs were already gone, so nobody could
 * tell what sent it. No child data: the parent who saved, the app's
 * User-Agent and its `X-PetPrep-Features` header only.
 *
 * New table, nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiet_hours_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            // Set for a pet's own hours, null for the family's row.
            $table->foreignId('pet_id')->nullable()->constrained('pets')->cascadeOnDelete();
            // The parent who saved (null once that account is deleted).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 16);
            // Normalised schedules (QuietHours::normaliseValues). Family: before null = the
            // row did not exist. Pet: null = follows the family.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('client_features', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['family_id', 'created_at']);
        });

        DB::statement("ALTER TABLE quiet_hours_changes ADD CONSTRAINT quiet_hours_changes_scope_check CHECK (scope IN ('family', 'pet'))");
        // A pet row names its pet; a family row never does.
        DB::statement("ALTER TABLE quiet_hours_changes ADD CONSTRAINT quiet_hours_changes_scope_pet_check CHECK ((scope = 'pet') = (pet_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('quiet_hours_changes');
    }
};
