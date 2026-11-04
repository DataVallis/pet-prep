<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI check of still images before a video is made from them (David 2026-11-04: a bought bed's
 * key image had an extra paw and two ghost paws on the floor; the clip from it had them too and
 * »Regenerate« returned the same picture — same seed, prompt and inputs).
 *
 *  - `pet_media.seed_override` / `item_media.seed_override`: the image seed of the CURRENT request
 *    when it is not the deterministic base seed (DNA / look / design seed). Written with a fresh
 *    random value by every regeneration (admin Regenerate / Generate missing, the still check's
 *    automatic retry) — a regeneration never resubmits identical inputs. Null = the base seed
 *    (every first generation, so existing requests stay byte-identical).
 *  - `pet_media.still_retries` / `item_media.quality_retries`: automatic regenerations of the
 *    current chain after a failed still check (item clips: a failed technical check), capped by
 *    `media.quality_check.max_still_retries` (2); a new chain (admin / new picture) starts at 0.
 *  - `item_media.candidate_*` / `quality` / `quality_reason`: like pet_media since 2026-10-23 — a
 *    downloaded item file is a CANDIDATE until its check passes (the old file stays servable).
 *  - `item_media_quality_checks`: one row per (item_media, generation, trigger) — the claim that
 *    never measures or pays twice (trigger `pipeline` = a new file, `command` = media:still-check).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->unsignedBigInteger('seed_override')->nullable();
            $table->unsignedSmallInteger('still_retries')->default(0);
        });

        Schema::table('item_media', function (Blueprint $table) {
            $table->unsignedBigInteger('seed_override')->nullable();
            $table->unsignedSmallInteger('quality_retries')->default(0);
            $table->string('candidate_path')->nullable();
            $table->unsignedBigInteger('candidate_bytes')->nullable();
            $table->string('candidate_mime')->nullable();
            $table->string('quality')->nullable();
            $table->text('quality_reason')->nullable();
        });

        DB::statement("ALTER TABLE item_media ADD CONSTRAINT item_media_quality_check CHECK (quality IS NULL OR quality IN ('passed', 'passed_technical', 'unchecked', 'flagged', 'shadow_failed', 'approved'))");

        Schema::create('item_media_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_media_id')->constrained('item_media')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            // pipeline (a new file) | command (media:still-check on a stored file)
            $table->string('trigger');
            // running (claimed) | decided
            $table->string('status')->default('running');
            $table->json('technical')->nullable();
            $table->boolean('technical_ok')->nullable();
            // passed | failed | skipped_budget | disabled | error | not_run | calling
            $table->string('ai_status')->default('not_run');
            $table->json('ai_verdict')->nullable();
            $table->decimal('ai_cost_usd', 10, 4)->default(0);
            $table->decimal('ai_reported_cost_usd', 10, 6)->nullable();
            $table->string('verdict')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['item_media_id', 'generation', 'trigger']);
        });

        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_trigger_check CHECK (trigger IN ('pipeline', 'command'))");
        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_status_check CHECK (status IN ('running', 'decided'))");
        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_ai_status_check CHECK (ai_status IN ('passed', 'failed', 'skipped_budget', 'disabled', 'error', 'not_run', 'calling'))");
        DB::statement("ALTER TABLE item_media_quality_checks ADD CONSTRAINT item_media_quality_checks_verdict_check CHECK (verdict IS NULL OR verdict IN ('pass', 'fail'))");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::dropIfExists('item_media_quality_checks');
        DB::statement('ALTER TABLE item_media DROP CONSTRAINT IF EXISTS item_media_quality_check');

        Schema::table('item_media', function (Blueprint $table) {
            $table->dropColumn(['seed_override', 'quality_retries', 'candidate_path', 'candidate_bytes', 'candidate_mime', 'quality', 'quality_reason']);
        });

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['seed_override', 'still_retries']);
        });
    }
};
