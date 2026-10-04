<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4-07 spend ledger + caps, M4-02 AI Lab (admin only), M4-08 media error reason.
 */
return new class extends Migration
{
    private const FAILURES = "'budget_daily', 'budget_monthly', 'budget_run', 'fal_balance', 'http_error', 'invalid_response', 'disabled', 'generation_failed'";

    public function up(): void
    {
        Schema::create('media_lab_runs', function (Blueprint $table) {
            $table->id();
            // Superadmin who started the run (admin users only — no child data in the lab).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');                       // image | video
            $table->string('breed')->nullable();          // BreedType value (image runs)
            $table->jsonb('fixed_traits')->nullable();
            $table->unsignedSmallInteger('samples')->default(1);
            $table->jsonb('profiles');                    // list of profile keys
            $table->string('pet_state')->nullable();      // PetStateEnum value (video runs)
            $table->unsignedBigInteger('source_result_id')->nullable(); // image result animated by a video run
            $table->decimal('estimated_cost_usd', 10, 4)->default(0);
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('media_lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_lab_run_id')->constrained('media_lab_runs')->cascadeOnDelete();
            $table->string('kind');                       // image | video
            $table->string('profile');
            $table->string('endpoint');
            $table->unsignedSmallInteger('sample_index')->default(0);
            $table->unsignedBigInteger('seed')->nullable();
            $table->jsonb('traits')->nullable();
            $table->text('prompt');
            $table->text('negative_prompt')->nullable();
            $table->jsonb('params')->nullable();
            $table->text('source_image_url')->nullable();
            $table->string('request_id')->nullable()->unique(); // fal queue request (videos)
            $table->text('status_url')->nullable();
            $table->text('response_url')->nullable();
            $table->string('status')->default('queued');
            $table->string('error_reason')->nullable();
            $table->text('error')->nullable();
            $table->text('result_url')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_cost_usd', 10, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['media_lab_run_id', 'status']);
        });

        Schema::table('media_lab_runs', function (Blueprint $table) {
            $table->foreign('source_result_id')->references('id')->on('media_lab_results')->nullOnDelete();
        });

        Schema::create('ai_spend_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('purpose');                    // lab | reference_image | state_video
            $table->string('profile');
            $table->string('endpoint');
            $table->string('unit');                       // image | megapixel | second
            $table->decimal('units', 10, 3);
            $table->decimal('cost_usd', 10, 4);           // ESTIMATE from config/media.php
            $table->string('status')->default('reserved'); // reserved | committed | void
            $table->string('error_reason')->nullable();
            $table->string('request_id')->nullable();
            $table->foreignId('pet_id')->nullable()->constrained('pets')->nullOnDelete();
            $table->foreignId('media_lab_result_id')->nullable()->constrained('media_lab_results')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::table('pets', function (Blueprint $table) {
            // Why the reference image is missing (budget cap / fal balance / …); null = no error.
            $table->string('media_error')->nullable();
        });

        DB::statement("ALTER TABLE media_lab_runs ADD CONSTRAINT media_lab_runs_kind_check CHECK (kind IN ('image', 'video'))");
        DB::statement("ALTER TABLE media_lab_results ADD CONSTRAINT media_lab_results_kind_check CHECK (kind IN ('image', 'video'))");
        DB::statement("ALTER TABLE media_lab_results ADD CONSTRAINT media_lab_results_status_check CHECK (status IN ('queued', 'running', 'completed', 'failed'))");
        DB::statement('ALTER TABLE media_lab_results ADD CONSTRAINT media_lab_results_error_reason_check CHECK (error_reason IS NULL OR error_reason IN ('.self::FAILURES.'))');
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_purpose_check CHECK (purpose IN ('lab', 'reference_image', 'state_video'))");
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_status_check CHECK (status IN ('reserved', 'committed', 'void'))");
        DB::statement("ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_unit_check CHECK (unit IN ('image', 'megapixel', 'second'))");
        DB::statement('ALTER TABLE ai_spend_ledger ADD CONSTRAINT ai_spend_ledger_error_reason_check CHECK (error_reason IS NULL OR error_reason IN ('.self::FAILURES.'))');
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_media_error_check CHECK (media_error IS NULL OR media_error IN ('.self::FAILURES.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_media_error_check');

        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('media_error');
        });

        Schema::dropIfExists('ai_spend_ledger');

        Schema::table('media_lab_runs', function (Blueprint $table) {
            $table->dropForeign(['source_result_id']);
        });

        Schema::dropIfExists('media_lab_results');
        Schema::dropIfExists('media_lab_runs');
    }
};
