<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3-08: RevenueCat purchase ledger + family entitlements.
 *
 *  - purchase_events: every RevenueCat webhook event, once (unique
 *    `event_id` = RevenueCat `event.id`, idempotency). Raw payload as jsonb
 *    WITHOUT `subscriber_attributes` (may carry e-mail / names). `family_id`
 *    = the family the event was mapped to (null: unknown user, TEST,
 *    TRANSFER, family deleted later — the ledger outlives the family as a
 *    financial record). `outcome` = what the event did.
 *  - family_entitlements: what a family may use (ADR-012 family model, M3-08
 *    decision: the entitlement belongs to the family). At most one
 *    unrevoked row per (family, entitlement) — partial unique index. Active =
 *    revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()).
 *    Deleted with the family.
 *
 * Additive; down() drops both tables.
 */
return new class extends Migration
{
    private const OUTCOMES = "'granted', 'extended', 'revoked', 'transferred', 'recorded', 'ignored', 'unknown_user', 'sandbox_ignored', 'no_entitlement'";

    private const ENVIRONMENTS = "'SANDBOX', 'PRODUCTION'";

    private const REVOKE_REASONS = "'expired', 'refund', 'transferred'";

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

        Schema::create('family_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained('families')->cascadeOnDelete();
            $table->string('entitlement', 64);
            $table->string('source', 32)->default('revenuecat');
            $table->string('product_id', 255)->nullable();
            $table->string('store', 32)->nullable();
            $table->string('environment', 16)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 32)->nullable();
            $table->string('last_event_id', 128)->nullable();
            $table->timestamps();

            $table->index(['family_id', 'entitlement']);
        });
        DB::statement('CREATE UNIQUE INDEX family_entitlements_one_unrevoked ON family_entitlements (family_id, entitlement) WHERE revoked_at IS NULL');
        DB::statement("ALTER TABLE family_entitlements ADD CONSTRAINT family_entitlements_source_check CHECK (source IN ('revenuecat'))");
        DB::statement('ALTER TABLE family_entitlements ADD CONSTRAINT family_entitlements_environment_check CHECK (environment IS NULL OR environment IN ('.self::ENVIRONMENTS.'))');
        DB::statement('ALTER TABLE family_entitlements ADD CONSTRAINT family_entitlements_revoke_check CHECK ((revoked_at IS NULL AND revoke_reason IS NULL) OR (revoked_at IS NOT NULL AND revoke_reason IN ('.self::REVOKE_REASONS.')))');
    }

    public function down(): void
    {
        Schema::dropIfExists('family_entitlements');
        Schema::dropIfExists('purchase_events');
    }
};
