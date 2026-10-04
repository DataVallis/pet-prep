<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contract before birth (M1-07b, David 2026-10-04, PRODUCT_SPEC §3:
 * "PIN → pogodba → pes se rodi").
 *
 * `pets.born_at` becomes nullable and loses its `now()` default:
 *  - born_at NULL  = "unborn": the pet exists since pairing (DNA, reference
 *    image) but waits for the child's contract — no decay, no hygiene events,
 *    no daily-walk close, no escalation, every child action except the
 *    contract → 423 `contract_required`. Signing sets born_at (server time).
 *  - born_at set   = born, the game loop runs.
 *
 * Grandfathering: every pet that exists before this migration was created
 * with born_at set (NOT NULL + default), so it stays born and unlocked even
 * without a pet_contracts row. Nothing is rewritten; the defensive backfill
 * below only covers a NULL that cannot exist under the old constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pets')->whereNull('born_at')->update(['born_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        DB::statement('ALTER TABLE pets ALTER COLUMN born_at DROP DEFAULT');
        DB::statement('ALTER TABLE pets ALTER COLUMN born_at DROP NOT NULL');
    }

    public function down(): void
    {
        // Unborn pets become born at their creation (the old behaviour).
        DB::table('pets')->whereNull('born_at')->update(['born_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);

        DB::statement('ALTER TABLE pets ALTER COLUMN born_at SET DEFAULT CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE pets ALTER COLUMN born_at SET NOT NULL');
    }
};
