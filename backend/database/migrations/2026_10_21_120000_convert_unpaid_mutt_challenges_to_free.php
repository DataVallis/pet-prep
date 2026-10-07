<?php

use App\Services\ChallengeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M5-F02 / M5-F03 (PAYMENTS_SPEC P4, David 2026-10-07: the mutt is free
 * forever, the challenge only with a paid breed).
 *
 *  - pets.converted_to_free_at: when an unpaid mutt challenge became the
 *    free plan. A converted pet may have past `payment_lock` periods, which
 *    must stay out of its program clock (Pet::paymentLockSpans reads them
 *    for a free pet only when this is set).
 *  - Data: every UNPAID mutt challenge (trial, payment required or not born
 *    yet — `challenge_paid_at` null) → plan free via
 *    ChallengeService::convertUnpaidMuttToFree (row lock, non-quiet save:
 *    an open payment lock is lifted like a payment — period closed, neglect
 *    clocks thawed, decay clock restarted; no media change, no push).
 *    Paid challenges (purchase / grandfathered / admin) are untouched —
 *    `plan.display_type` shows a grandfathered mutt as free.
 *
 * Idempotent (only unpaid mutt challenges are selected). down() drops the
 * column only: converted pets stay free (a free pet must never be turned
 * back into a lockable challenge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('converted_to_free_at')->nullable();
        });

        $ids = DB::table('pets')
            ->where('breed_type', 'mutt')
            ->where('plan', 'challenge')
            ->whereNull('challenge_paid_at')
            ->orderBy('id')
            ->pluck('id');

        $challenges = app(ChallengeService::class);
        $now = now();
        foreach ($ids as $id) {
            $challenges->convertUnpaidMuttToFree((int) $id, $now);
        }
    }

    public function down(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('converted_to_free_at');
        });
    }
};
