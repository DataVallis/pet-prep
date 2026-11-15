<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-U03 (FREE_PET_UPGRADE_SPEC U7, David 2026-11-15): when a parent upgrades a
 * free pet to the 12-week challenge, the parent chooses whether the Care Score
 * so far is kept or starts again.
 *
 *  - pets.challenge_score_kept: true = the score (routines + illnesses, the
 *    child's, the pet's and the certificate's) counts from each child's own
 *    start, not from the purchase. The 12 weeks always run from the purchase
 *    (`challenge_started_at`). False (default, NOT backfilled) = the M6-U01
 *    behaviour: the score counts from the purchase. Only on an upgraded pet
 *    (CHECK) — a refund back to free clears it.
 *  - challenge_purchase_intents.keep_score: the parent's choice, recorded before
 *    the store sheet opens, so a webhook that upgrades the pet applies it. Null =
 *    not asked (an older app, a pet that is not free) → the score starts again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->boolean('challenge_score_kept')->default(false);
        });
        DB::statement('ALTER TABLE pets ADD CONSTRAINT pets_challenge_score_kept_check CHECK (challenge_score_kept = false OR challenge_started_at IS NOT NULL)');

        Schema::table('challenge_purchase_intents', function (Blueprint $table) {
            $table->boolean('keep_score')->nullable();
        });
    }

    public function down(): void
    {
        // Dropping the column would silently restart the score of every pet whose parent chose to keep it.
        if (DB::table('pets')->where('challenge_score_kept', true)->exists()) {
            throw new RuntimeException('Refusing to roll back M6-U03: pets upgraded with a kept Care Score exist (pets.challenge_score_kept). Decide with David first.');
        }
        Schema::table('challenge_purchase_intents', function (Blueprint $table) {
            $table->dropColumn('keep_score');
        });
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_challenge_score_kept_check');
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('challenge_score_kept');
        });
    }
};
