<?php

use App\Enums\AcquisitionSource;
use App\Enums\DashboardOpenTrigger;
use App\Enums\Phase2PriceChoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validation signals (VALIDATION_PLAN §4.1 / §4.4, M5-08, contract docs/engineering/VALIDATION_API.md,
 * David 2026-11-21). Only nullable columns and new tables — no existing row is changed.
 *
 *  - users.acquisition_source / acquisition_detail / acquisition_recorded_at: »Kje ste izvedeli za
 *    PetPrep?« (parents only; null = not answered).
 *  - parent_dashboard_opens: at most one row per parent per 30 minutes (H6); pruned after 400 days.
 *  - phase2_interests: one row per parent for the Phase-2 fake door (H7). `email_contact_id` links the
 *    »Obvestite me« e-mail in the unified e-mail store (email_contacts / email_signups, source
 *    `app_phase2`) so the export can show it and an account deletion can remove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('acquisition_source', 24)->nullable();
            $table->string('acquisition_detail', 60)->nullable();
            $table->timestamp('acquisition_recorded_at')->nullable();
        });

        Schema::create('parent_dashboard_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->string('trigger', 16)->nullable();

            $table->index(['user_id', 'opened_at']);
            $table->index(['family_id', 'opened_at']);
            $table->index('opened_at');
        });

        Schema::create('phase2_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->string('price_choice', 16)->nullable();
            $table->boolean('notify')->default(false);
            $table->timestamp('submitted_at')->nullable();
            // No FK on purpose: email_contacts belongs to the website store (an admin delete of the
            // contact leaves a dangling id that every reader treats as "no address"; the e-mail
            // store's own migration can still be rolled back on its own).
            $table->unsignedBigInteger('email_contact_id')->nullable();
            $table->timestamps();

            $table->index('family_id');
            $table->index('email_contact_id');
        });

        $sources = "'".implode("', '", AcquisitionSource::values())."'";
        $triggers = "'".implode("', '", DashboardOpenTrigger::values())."'";
        $prices = "'".implode("', '", Phase2PriceChoice::values())."'";

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_acquisition_source_check CHECK (acquisition_source IS NULL OR acquisition_source IN ({$sources}))");
        DB::statement("ALTER TABLE parent_dashboard_opens ADD CONSTRAINT parent_dashboard_opens_trigger_check CHECK (\"trigger\" IS NULL OR \"trigger\" IN ({$triggers}))");
        DB::statement("ALTER TABLE phase2_interests ADD CONSTRAINT phase2_interests_price_choice_check CHECK (price_choice IS NULL OR price_choice IN ({$prices}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('phase2_interests');
        Schema::dropIfExists('parent_dashboard_opens');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_acquisition_source_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['acquisition_source', 'acquisition_detail', 'acquisition_recorded_at']);
        });
    }
};
