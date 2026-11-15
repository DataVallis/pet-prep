<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-S05 (THREEJS_OPPORTUNITIES T9): parents who leave an e-mail on the
 * petprep.si page »Koliko dela je pes« with explicit consent to PetPrep news.
 * One row per e-mail (stored lower-case, unique) — a second visit updates the
 * row instead of adding one. No IP, no name, no child or family data. Deleted
 * 24 months after the last visit by `summary-leads:prune`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summary_leads', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('consent_version', 16);
            $table->timestamp('consented_at');
            $table->string('source', 32);
            $table->string('locale', 2)->nullable();
            $table->string('breed', 64)->nullable();
            $table->string('stage', 8)->nullable();
            $table->unsignedInteger('visits')->default(1);
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('last_seen_at');
            $table->index('breed');
        });

        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_source_check CHECK (source IN ('real_cost'))");
        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_stage_check CHECK (stage IS NULL OR stage IN ('adult', 'puppy'))");
        DB::statement('ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_email_lower_check CHECK (email = lower(email))');
        DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_locale_check CHECK (locale IS NULL OR locale ~ '^[a-z]{2}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_leads');
    }
};
