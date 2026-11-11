<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-U01 (FREE_PET_UPGRADE_SPEC §5, David 2026-11-10): upgrade a born free pet
 * (mutt / domestic cat) to the 12-week challenge with the same pet.
 *
 *  - pets.challenge_started_at: the purchase that upgraded the pet — the
 *    challenge clock (child week / score / certificate and the pet's
 *    »simulation end«) counts from here; the age / life stage stay on the
 *    birth. Nullable, NOT backfilled (null = every existing pet behaves as
 *    before). Only on a challenge pet (CHECK) — a refund back to free clears it.
 *  - challenge_purchase_intents (§5.3, David »da« 2026-11-10): the new app says
 *    which pet a store purchase is for before the store sheet opens; the
 *    webhook assigns the credit to that pet. Per parent (= the RevenueCat
 *    app_user_id), single use, 30 min.
 *  - challenge_credits.assigned_via gains 'intent' (webhook + intent); expand-only.
 *  - challenge_credits.unassigned_alerted_at: the safety-net Discord alert of a
 *    credit left unassigned (once per credit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pets', function (Blueprint $table) {
            $table->timestamp('challenge_started_at')->nullable();
        });
        DB::statement("ALTER TABLE pets ADD CONSTRAINT pets_challenge_started_check CHECK (challenge_started_at IS NULL OR plan = 'challenge')");

        Schema::create('challenge_purchase_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            // The parent who opens the store sheet = the RevenueCat app_user_id of the purchase.
            $table->foreignId('parent_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // webhook | activate | superseded
            $table->string('consumed_by', 16)->nullable();
            $table->foreignId('challenge_credit_id')->nullable()->constrained('challenge_credits')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_user_id', 'consumed_at']);
            $table->index(['family_id', 'created_at']);
        });
        DB::statement("ALTER TABLE challenge_purchase_intents ADD CONSTRAINT challenge_purchase_intents_consumed_check CHECK ((consumed_at IS NULL AND consumed_by IS NULL) OR (consumed_at IS NOT NULL AND consumed_by IN ('webhook', 'activate', 'superseded')))");

        Schema::table('challenge_credits', function (Blueprint $table) {
            $table->timestamp('unassigned_alerted_at')->nullable();
        });
        DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_assigned_check');
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent', 'webhook', 'birth', 'intent')))");
    }

    public function down(): void
    {
        // QA #978 d: dropping the column would silently move upgraded pets back to the birth
        // clock (a mutt born months ago would be "finished" at once). Refuse while any exists.
        if (DB::table('pets')->whereNotNull('challenge_started_at')->exists()) {
            throw new RuntimeException('Refusing to roll back M6-U01: pets upgraded to the challenge exist (pets.challenge_started_at). Decide with David first.');
        }
        // Never rewrite purchase history: the wider CHECK stays while a row uses 'intent'.
        if (! DB::table('challenge_credits')->where('assigned_via', 'intent')->exists()) {
            DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_assigned_check');
            DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent', 'webhook', 'birth')))");
        }
        Schema::table('challenge_credits', function (Blueprint $table) {
            $table->dropColumn('unassigned_alerted_at');
        });
        Schema::dropIfExists('challenge_purchase_intents');
        DB::statement('ALTER TABLE pets DROP CONSTRAINT IF EXISTS pets_challenge_started_check');
        Schema::table('pets', function (Blueprint $table) {
            $table->dropColumn('challenge_started_at');
        });
    }
};
