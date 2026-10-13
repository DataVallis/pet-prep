<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-R09b — "Ali si vedel?" / breed facts, server side (DID_YOU_KNOW_SPEC §7,
 * David's decisions §0, 2026-10-09).
 *
 *  - `breed_facts`: sourced facts per breed. Content lives in git
 *    (docs/research/breed-facts/<breed>.json) and is upserted by key with
 *    `php artisan breeds:import-facts`; the status workflow
 *    draft → verified → approved runs in the admin (only approved rows reach
 *    the apps; only a superadmin approves). `content_hash` = sha256 of the
 *    content fields: an approved row whose content changes in git falls back
 *    to the file's status and must be approved again. `breed` is a BreedType
 *    value WITHOUT a CHECK (a new breed must not need a migration here; the
 *    importer validates it, mutt / domestic_cat are rejected). Family and
 *    health facts are parent-only (CHECK). approved_by → users SET NULL (a
 *    kept reference table, like breed_stage_params.updated_by).
 *  - `breed_fact_reads`: which user read which fact (child: their own
 *    collection »8 od 40«; parent: own reads). One row per pair.
 *  - `breed_fact_shows`: the child's automatic sheet (max 2 per program week,
 *    ≥ 2 family-local days apart) across devices — one row per (child, pet,
 *    family-local date). Both cascade with the user / pet (account deletion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breed_facts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('breed', 50);
            $table->string('category', 20);
            $table->string('audience', 10);
            $table->string('title_en', 120);
            $table->string('title_sl', 120);
            $table->string('why_en', 140)->nullable();
            $table->string('why_sl', 140)->nullable();
            $table->string('source_id', 50);
            $table->string('source_name', 200);
            $table->string('source_url', 500);
            $table->text('quote');
            $table->string('game_rule_key', 50)->nullable();
            $table->integer('sort')->default(0);
            $table->string('status', 10)->default('draft');
            $table->string('verified_by', 100)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->index(['breed', 'status', 'audience', 'sort']);
        });

        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_category_check CHECK (category IN ('origin', 'body', 'temperament', 'exercise', 'diet', 'grooming', 'training', 'family', 'health'))");
        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_audience_check CHECK (audience IN ('child', 'parent'))");
        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_parent_only_check CHECK (category NOT IN ('family', 'health') OR audience = 'parent')");
        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_status_check CHECK (status IN ('draft', 'verified', 'approved'))");
        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_approved_check CHECK ((status = 'approved') = (approved_at IS NOT NULL))");
        DB::statement('ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_why_check CHECK ((why_en IS NULL) = (why_sl IS NULL))');
        DB::statement("ALTER TABLE breed_facts ADD CONSTRAINT breed_facts_breed_check CHECK (breed NOT IN ('mutt', 'domestic_cat'))");

        Schema::create('breed_fact_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('breed_fact_id')->constrained('breed_facts')->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['user_id', 'breed_fact_id']);
            $table->index('breed_fact_id');
        });

        Schema::create('breed_fact_shows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            // The fact shown (null once a fact row is gone).
            $table->foreignId('breed_fact_id')->nullable()->constrained('breed_facts')->nullOnDelete();
            // 1 = the program week from birth (payment-lock time excluded).
            $table->unsignedSmallInteger('program_week');
            $table->date('local_date');
            $table->timestamp('shown_at');

            $table->unique(['user_id', 'pet_id', 'local_date']);
            $table->index(['pet_id', 'user_id', 'program_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breed_fact_shows');
        Schema::dropIfExists('breed_fact_reads');
        Schema::dropIfExists('breed_facts');
    }
};
