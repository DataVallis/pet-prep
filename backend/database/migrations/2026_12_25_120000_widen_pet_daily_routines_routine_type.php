<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hotfix (David, 2026-10-19): pet_daily_routines.routine_type was created as
 * varchar(8) in M2 (feed / water / clean / walk). The cat routines added in
 * M5-R06-05 — `litter_scoop` (12) and `litter_change` (13) — do not fit, so
 * RoutineLedgerService::closePet() failed for every cat with a litter row
 * ("value too long for type character varying(8)"), its whole insert rolled
 * back and the pet was retried every 30 min without ever closing a day.
 *
 * Widen to varchar(32) (the check constraint still limits the values) and
 * status to varchar(16) for headroom. Widening a varchar is metadata-only in
 * PostgreSQL (no table rewrite, the unique index stays valid).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pet_daily_routines ALTER COLUMN routine_type TYPE varchar(32)');
        DB::statement('ALTER TABLE pet_daily_routines ALTER COLUMN status TYPE varchar(16)');
    }

    public function down(): void
    {
        // Rows longer than 8 characters cannot go back into varchar(8).
        DB::table('pet_daily_routines')->whereIn('routine_type', ['litter_scoop', 'litter_change'])->delete();
        DB::statement('ALTER TABLE pet_daily_routines ALTER COLUMN status TYPE varchar(8)');
        DB::statement('ALTER TABLE pet_daily_routines ALTER COLUMN routine_type TYPE varchar(8)');
    }
};
