<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3-08 / M3-11: RevenueCat purchase ledger + challenge credits.
 *
 *  - purchase_events: every RevenueCat webhook event, once (unique
 *    `event_id` = RevenueCat `event.id`, idempotency). Raw payload as jsonb
 *    WITHOUT `subscriber_attributes` (may carry e-mail / names). `family_id`
 *    = the family the event was mapped to (null: unknown user, TEST,
 *    TRANSFER to an unknown user, family deleted later — the ledger outlives
 *    the family as a financial record). `outcome` = what the event did.
 *  - challenge_credits (M3-11, David 2026-10-07 P1, PAYMENTS_SPEC): one row
 *    per successful purchase of the consumable 12-week challenge
 *    (`purchase_event_id` unique). A credit belongs to the buyer's family
 *    and is assigned to exactly one pet (`pet_id` + `assigned_at`); at most
 *    one unrevoked credit per pet (partial unique index). `assigned_at` set
 *    with `pet_id` null = the pet was deleted later; the credit stays used.
 *    A refund revokes it (`revoked_at`, `revoke_reason`). TRANSFER moves an
 *    unassigned credit to another family (`transferred_from_family_id`).
 *    Deleted with the family; purchase_events keep the record.
 *
 * Branch-only history: the first M3-08 draft created `family_entitlements`;
 * David chose a per-pet consumable before it was ever deployed, so this
 * migration was edited instead of adding a drop migration.
 *
 * Additive; down() drops both tables.
 */
return new class extends Migration
{
    private const OUTCOMES = "'granted', 'revoked', 'transferred', 'recorded', 'ignored', 'unknown_user', 'sandbox_ignored', 'unknown_product'";

    private const ENVIRONMENTS = "'SANDBOX', 'PRODUCTION'";

    public function up(): void
    {
        Schema::create('purchase_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 128)->unique();
            $table->string('type', 64);
            $table->string('app_user_id', 255)->nullable();
            $table->string('original_app_user_id', 255)->nullable();
            $table->jsonb('aliases')->nullable();
            $table->string('product_id', 255)->nullable();
            $table->jsonb('entitlement_ids')->nullable();
            $table->string('store', 32)->nullable();
            $table->string('environment', 16)->nullable();
            $table->string('transaction_id', 255)->nullable();
            $table->string('original_transaction_id', 255)->nullable();
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('expiration_at')->nullable();
            $table->timestamp('event_at')->nullable();
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->jsonb('payload');
            $table->string('outcome', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'created_at']);
            $table->index('app_user_id');
        });
        DB::statement('ALTER TABLE purchase_events ADD CONSTRAINT purchase_events_outcome_check CHECK (outcome IS NULL OR outcome IN ('.self::OUTCOMES.'))');
        DB::statement('ALTER TABLE purchase_events ADD CONSTRAINT purchase_events_environment_check CHECK (environment IS NULL OR environment IN ('.self::ENVIRONMENTS.'))');

        Schema::create('challenge_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->foreignId('purchase_event_id')->unique()->constrained('purchase_events');
            $table->string('product_id', 255);
            $table->string('store', 32)->nullable();
            $table->string('environment', 16)->nullable();
            $table->string('transaction_id', 255)->nullable();
            $table->timestamp('purchased_at');
            $table->foreignId('pet_id')->nullable()->constrained('pets')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->string('assigned_via', 16)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 32)->nullable();
            $table->string('revoke_event_id', 128)->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->unsignedBigInteger('transferred_from_family_id')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'assigned_at']);
            $table->index('transaction_id');
        });
        DB::statement('CREATE UNIQUE INDEX challenge_credits_one_per_pet ON challenge_credits (pet_id) WHERE revoked_at IS NULL AND pet_id IS NOT NULL');
        DB::statement('ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_environment_check CHECK (environment IS NULL OR environment IN ('.self::ENVIRONMENTS.'))');
        // pet_id may become null later (pet deleted → nullOnDelete): the credit stays used.
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_assigned_check CHECK ((assigned_at IS NULL AND assigned_via IS NULL AND pet_id IS NULL) OR (assigned_at IS NOT NULL AND assigned_via IN ('parent', 'webhook')))");
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_revoke_check CHECK ((revoked_at IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoke_reason IN ('refund')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_credits');
        Schema::dropIfExists('purchase_events');
    }
};
