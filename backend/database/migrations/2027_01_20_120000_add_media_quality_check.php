<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic quality check of AI clips (David 2026-10-23): a downloaded clip is a
 * CANDIDATE (`pet_media.candidate_path`) until it passes a technical (ffmpeg) + AI vision
 * check; the servable `storage_path` stays the old file until then. One automatic
 * regeneration per generation chain (`quality_retry_generation` = the generation that WAS
 * the automatic retry, which never retries again); a second failure → »Needs review«
 * (slot failed, `error_reason` = `quality_failed`, the candidate kept for »Approve anyway«). Results per (pet_media, generation, trigger) in
 * `pet_media_quality_checks` (unique — the claim against a double check / double spend;
 * trigger `pipeline` = a new clip, `command` = media:quality-check on a stored clip).
 * `quality` = verdict of the STORED file (like stored_recipe — changes only when a file is
 * stored / linked / checked): passed | passed_technical (not AI-checked) | unchecked
 * (ffmpeg missing) | flagged (a stored clip failed media:quality-check — not shown) |
 * approved (superadmin). »Needs review« = `error_reason` quality_failed + a kept candidate.
 *
 * `stored_duration_seconds` = length of the STORED file (like stored_recipe; the app plays
 * 5 s school performing clips and older 3 s ones side by side). Existing files: copied
 * from `duration_seconds`.
 *
 * Constraints: ledger purpose + `quality_check`, ledger unit + `request` (vision LLM call),
 * AiCallFailure + `quality_failed` on every table that mirrors it.
 */
return new class extends Migration
{
    private const FAILURES_OLD = "'budget_daily', 'budget_monthly', 'budget_run', 'budget_lab', 'timed_out', 'fal_balance', 'http_error', 'invalid_response', 'disabled', 'generation_failed'";

    private const FAILURES_NEW = "'budget_daily', 'budget_monthly', 'budget_run', 'budget_lab', 'timed_out', 'fal_balance', 'http_error', 'invalid_response', 'disabled', 'generation_failed', 'quality_failed'";

    /** @var array<string, array{0: string, 1: string}> constraint => [table, column] */
    private const FAILURE_CHECKS = [
        'ai_spend_ledger_error_reason_check' => ['ai_spend_ledger', 'error_reason'],
        'media_lab_results_error_reason_check' => ['media_lab_results', 'error_reason'],
        'pets_media_error_check' => ['pets', 'media_error'],
        'pet_media_error_reason_check' => ['pet_media', 'error_reason'],
    ];

    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        Schema::table('pet_media', function (Blueprint $table) {
            $table->string('candidate_path')->nullable()->after('storage_path');
            $table->unsignedBigInteger('candidate_bytes')->nullable()->after('candidate_path');
            $table->string('candidate_mime')->nullable()->after('candidate_bytes');
            $table->string('quality')->nullable()->after('candidate_mime');
            $table->text('quality_reason')->nullable()->after('quality');
            $table->unsignedInteger('quality_retry_generation')->nullable()->after('quality_reason');
            $table->decimal('stored_duration_seconds', 6, 2)->nullable()->after('duration_seconds');
        });

        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_quality_check CHECK (quality IS NULL OR quality IN ('passed', 'passed_technical', 'unchecked', 'flagged', 'approved'))");
        DB::statement('CREATE INDEX pet_media_quality_index ON pet_media (quality) WHERE quality IS NOT NULL');

        DB::table('pet_media')->whereNotNull('storage_path')->whereNotNull('duration_seconds')
            ->update(['stored_duration_seconds' => DB::raw('duration_seconds')]);

        Schema::create('pet_media_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_media_id')->constrained('pet_media')->cascadeOnDelete();
            $table->unsignedInteger('generation');
            // pipeline (a new clip) | command (media:quality-check on a stored clip)
            $table->string('trigger');
            // running (claimed) | decided
            $table->string('status')->default('running');
            $table->json('technical')->nullable();
            $table->boolean('technical_ok')->nullable();
            // passed | failed | skipped_budget | disabled | error | not_run
            $table->string('ai_status')->default('not_run');
            $table->json('ai_verdict')->nullable();
            $table->decimal('ai_cost_usd', 10, 4)->default(0);          // ESTIMATE reserved in ai_spend_ledger
            $table->decimal('ai_reported_cost_usd', 10, 6)->nullable(); // usage.cost fal reported
            $table->string('verdict')->nullable();                       // pass | fail
            $table->text('reason')->nullable();
            $table->string('sheet_path')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['pet_media_id', 'generation', 'trigger']);
        });

        DB::statement("ALTER TABLE pet_media_quality_checks ADD CONSTRAINT pet_media_quality_checks_trigger_check CHECK (trigger IN ('pipeline', 'command'))");
        DB::statement("ALTER TABLE pet_media_quality_checks ADD CONSTRAINT pet_media_quality_checks_status_check CHECK (status IN ('running', 'decided'))");
        DB::statement("ALTER TABLE pet_media_quality_checks ADD CONSTRAINT pet_media_quality_checks_ai_status_check CHECK (ai_status IN ('passed', 'failed', 'skipped_budget', 'disabled', 'error', 'not_run'))");
        DB::statement("ALTER TABLE pet_media_quality_checks ADD CONSTRAINT pet_media_quality_checks_verdict_check CHECK (verdict IS NULL OR verdict IN ('pass', 'fail'))");

        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_purpose_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_purpose_check CHECK (purpose IN ('lab', 'reference_image', 'state_video', 'quality_check'))");
        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_unit_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_unit_check CHECK (unit IN ('image', 'megapixel', 'second', 'request'))");

        foreach (self::FAILURE_CHECKS as $name => [$table, $column]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IS NULL OR {$column} IN (".self::FAILURES_NEW.'))');
        }
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");

        foreach (self::FAILURE_CHECKS as $name => [$table, $column]) {
            DB::table($table)->where($column, 'quality_failed')->update([$column => 'generation_failed']);
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IS NULL OR {$column} IN (".self::FAILURES_OLD.'))');
        }

        DB::table('ai_spend_ledger')->where('purpose', 'quality_check')->delete();
        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_purpose_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_purpose_check CHECK (purpose IN ('lab', 'reference_image', 'state_video'))");
        DB::statement('ALTER TABLE ai_spend_ledger DROP CONSTRAINT IF EXISTS ai_spend_ledger_unit_check');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_unit_check CHECK (unit IN ('image', 'megapixel', 'second'))");

        Schema::dropIfExists('pet_media_quality_checks');

        DB::statement('DROP INDEX IF EXISTS pet_media_quality_index');
        DB::statement('ALTER TABLE pet_media DROP CONSTRAINT IF EXISTS pet_media_quality_check');

        Schema::table('pet_media', function (Blueprint $table) {
            $table->dropColumn(['candidate_path', 'candidate_bytes', 'candidate_mime', 'quality', 'quality_reason', 'quality_retry_generation', 'stored_duration_seconds']);
        });
    }
};
