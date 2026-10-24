<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M5-05 (David 2026-10-19: System health instead of Sentry): crash / render
 * error reports from the mobile app, POST /api/app-errors.
 *
 *  - app_error_fingerprints: one row per distinct error (fingerprint = sha256
 *    of error name + normalised message + normalised first stack frame) —
 *    also the Discord claim (`last_alerted_at`, at most once per day).
 *  - app_error_reports: one row per (fingerprint, app version, platform) —
 *    counts per role and ONE sample payload (the latest), upserted, so the
 *    table never grows per occurrence.
 *  - app_error_hours: occurrences per fingerprint per hour (24 h / 7 d views,
 *    spike alert).
 *
 * No user id, family, pet, name or e-mail: only the role of the token that
 * sent the report (parent | child | none). Pruned after 30 days
 * (`system-health:prune`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_error_fingerprints', function (Blueprint $table) {
            $table->string('fingerprint', 64)->primary();
            $table->string('scope', 32);
            $table->string('error_name', 100);
            $table->string('message', 500);
            $table->string('first_frame', 300)->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('last_alerted_at')->nullable();
        });

        DB::statement("ALTER TABLE app_error_fingerprints ADD CONSTRAINT app_error_fingerprints_scope_check CHECK (scope IN ('app', 'child-hud', 'locked-overlay', 'global', 'unhandled-rejection'))");
        // QA #375 m7: keep the CHECK and its two mirrors in step.
        DB::statement("COMMENT ON CONSTRAINT app_error_fingerprints_scope_check ON app_error_fingerprints IS 'Mirrors App\\Enums\\AppErrorScope and the app''s AppErrorScope (mobile/src/modules/errorReporting/errorReport.ts) — a new scope needs all three + a migration.'");

        Schema::create('app_error_reports', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64);
            $table->foreign('fingerprint')->references('fingerprint')->on('app_error_fingerprints')->cascadeOnDelete();
            $table->string('app_version', 32);
            $table->string('platform', 16);
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->unsignedBigInteger('parent_count')->default(0);
            $table->unsignedBigInteger('child_count')->default(0);
            $table->unsignedBigInteger('anonymous_count')->default(0);
            $table->jsonb('sample');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unique(['fingerprint', 'app_version', 'platform']);
        });

        DB::statement("ALTER TABLE app_error_reports ADD CONSTRAINT app_error_reports_platform_check CHECK (platform IN ('ios', 'android'))");

        Schema::create('app_error_hours', function (Blueprint $table) {
            $table->string('fingerprint', 64);
            $table->foreign('fingerprint')->references('fingerprint')->on('app_error_fingerprints')->cascadeOnDelete();
            $table->timestamp('hour');
            $table->unsignedInteger('occurrences')->default(0);
            $table->primary(['fingerprint', 'hour']);
            $table->index('hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_error_hours');
        Schema::dropIfExists('app_error_reports');
        Schema::dropIfExists('app_error_fingerprints');
    }
};
