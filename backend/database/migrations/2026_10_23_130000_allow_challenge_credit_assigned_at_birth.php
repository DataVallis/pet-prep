<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M3-13 (no free trial, David 2026-10-08): a challenge credit the family
 * already holds may be assigned at the pet's birth (contract) —
 * `challenge_credits.assigned_via = 'birth'` (ChallengeCreditService::
 * assignAvailableBeforeBirth). Expand-only: the CHECK gains one value.
 * down() restores the old list only when no row uses the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_assigned_check');
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent', 'webhook', 'birth')))");
    }

    public function down(): void
    {
        if (DB::table('challenge_credits')->where('assigned_via', 'birth')->exists()) {
            return; // never rewrite purchase history; the wider CHECK stays
        }
        DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_assigned_check');
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent', 'webhook')))");
    }
};
