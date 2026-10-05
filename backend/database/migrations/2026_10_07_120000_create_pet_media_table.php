<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4-03 / M4-05: one row per media slot of a pet — the reference image
 * (kind image, state null) and one state video per PetStateEnum value
 * (kind video). A slot is regenerated in place (generation + 1); the last
 * stored file stays playable until the new one is stored.
 *
 * Replaces pet_media_jobs (M4-04): its rows are copied (pending → running
 * with the same request_id, so a late webhook still matches; completed rows
 * were never downloaded → failed, picked up by media:backfill), then the
 * table is dropped. Nothing is generated or downloaded here — existing pets
 * get their media through `php artisan media:backfill` (manual, budgeted).
 */
return new class extends Migration
{
    private const FAILURES = "'budget_daily', 'budget_monthly', 'budget_run', 'budget_lab', 'timed_out', 'fal_balance', 'http_error', 'invalid_response', 'disabled', 'generation_failed'";

    private const STATES = "'idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'";

    public function up(): void
    {
        Schema::create('pet_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('kind');                              // image | video
            $table->string('state')->nullable();                 // PetStateEnum value (videos); null for the image
            $table->string('profile')->nullable();               // config/media.php profile key used for the current generation
            $table->string('status')->default('pending');        // pending | running | ready | failed
            $table->unsignedSmallInteger('generation')->default(1);
            $table->unsignedSmallInteger('source_generation')->nullable(); // videos: image generation they were made from
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('request_id')->nullable()->unique();  // fal queue request (videos) / sync request id (image)
            $table->text('source_url')->nullable();              // fal.media result URL (allowlisted), before download
            $table->string('storage_path')->nullable();          // path on the pet-media disk (last stored generation)
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('mime')->nullable();
            $table->decimal('duration_seconds', 6, 2)->nullable();
            $table->decimal('cost_usd', 10, 4)->default(0);      // ESTIMATED spend of all generations of this slot
            $table->string('error_reason')->nullable();          // AiCallFailure
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'error_reason']);
        });

        // One slot per (pet, kind, state); the image's state is NULL → NULLS NOT DISTINCT (PostgreSQL 15+).
        DB::statement('CREATE UNIQUE INDEX pet_media_slot_unique ON pet_media (pet_id, kind, state) NULLS NOT DISTINCT');
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_kind_check CHECK (kind IN ('image', 'video'))");
        DB::statement("ALTER TABLE pet_media ADD CONSTRAINT pet_media_status_check CHECK (status IN ('pending', 'running', 'ready', 'failed'))");
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_state_check CHECK ((kind = \'image\' AND state IS NULL) OR (kind = \'video\' AND state IN ('.self::STATES.')))');
        DB::statement('ALTER TABLE pet_media ADD CONSTRAINT pet_media_error_reason_check CHECK (error_reason IS NULL OR error_reason IN ('.self::FAILURES.'))');

        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->foreignId('pet_media_id')->nullable()->after('pet_id')->constrained('pet_media')->nullOnDelete();
        });

        if (Schema::hasTable('pet_media_jobs')) {
            DB::statement(<<<'SQL'
                INSERT INTO pet_media (pet_id, kind, state, profile, status, request_id, error, created_at, updated_at)
                SELECT DISTINCT ON (pet_id, pet_state)
                       pet_id, 'video', pet_state, NULL,
                       CASE status WHEN 'pending' THEN 'running' ELSE 'failed' END,
                       CASE status WHEN 'pending' THEN request_id ELSE NULL END,
                       CASE status WHEN 'completed' THEN 'migrated from pet_media_jobs (never stored)' ELSE error END,
                       created_at, updated_at
                FROM pet_media_jobs
                WHERE kind = 'video' AND pet_state IN ('idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing')
                ORDER BY pet_id, pet_state, created_at DESC
                SQL);

            Schema::drop('pet_media_jobs');
        }
    }

    public function down(): void
    {
        Schema::create('pet_media_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('request_id')->unique();
            $table->string('kind');
            $table->string('pet_state')->nullable();
            $table->string('status')->default('pending');
            $table->text('result_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'status']);
        });

        DB::statement("ALTER TABLE pet_media_jobs ADD CONSTRAINT pet_media_jobs_kind_check CHECK (kind IN ('video'))");
        DB::statement("ALTER TABLE pet_media_jobs ADD CONSTRAINT pet_media_jobs_status_check CHECK (status IN ('pending', 'completed', 'failed'))");

        DB::statement(<<<'SQL'
            INSERT INTO pet_media_jobs (pet_id, request_id, kind, pet_state, status, error, created_at, updated_at)
            SELECT pet_id, request_id, 'video', state, CASE status WHEN 'running' THEN 'pending' ELSE 'failed' END, error, created_at, updated_at
            FROM pet_media
            WHERE kind = 'video' AND request_id IS NOT NULL
            SQL);

        Schema::table('ai_spend_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pet_media_id');
        });

        Schema::dropIfExists('pet_media');
    }
};
