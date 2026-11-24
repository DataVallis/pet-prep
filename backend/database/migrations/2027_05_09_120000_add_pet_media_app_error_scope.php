<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 2026-11-24 (David »ja naredi«): app error scope `pet-media` — a pet video
 * player failed on the device. Only the CHECK constraint changes (it mirrors
 * App\Enums\AppErrorScope); no data is touched.
 */
return new class extends Migration
{
    private const COMMENT = "COMMENT ON CONSTRAINT app_error_fingerprints_scope_check ON app_error_fingerprints IS 'Mirrors App\\Enums\\AppErrorScope and the app''s AppErrorScope (mobile/src/modules/errorReporting/errorReport.ts) — a new scope needs all three + a migration.'";

    public function up(): void
    {
        DB::statement('ALTER TABLE app_error_fingerprints DROP CONSTRAINT IF EXISTS app_error_fingerprints_scope_check');
        DB::statement("ALTER TABLE app_error_fingerprints ADD CONSTRAINT app_error_fingerprints_scope_check CHECK (scope IN ('app', 'child-hud', 'locked-overlay', 'global', 'unhandled-rejection', 'pet-media'))");
        DB::statement(self::COMMENT);
    }

    public function down(): void
    {
        // No data is deleted: rows that already carry `pet-media` stay (NOT VALID skips
        // existing rows); they are pruned after 30 days like every report.
        DB::statement('ALTER TABLE app_error_fingerprints DROP CONSTRAINT IF EXISTS app_error_fingerprints_scope_check');
        DB::statement("ALTER TABLE app_error_fingerprints ADD CONSTRAINT app_error_fingerprints_scope_check CHECK (scope IN ('app', 'child-hud', 'locked-overlay', 'global', 'unhandled-rejection')) NOT VALID");
        DB::statement(self::COMMENT);
    }
};
