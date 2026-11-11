<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-S01 (WEBSITE_SALES_SPEC §3.4, CONTRACT §2–§3): website gift vouchers.
 *
 *  - gift_vouchers: one row per Lemon Squeezy license key of the gift product.
 *    The key itself is NEVER stored — only sha256(canonical key) and its last 4
 *    characters (admin display). No buyer data (no e-mail, no name).
 *  - lemonsqueezy_events: the LS webhook ledger (like purchase_events). LS sends
 *    no event id, so the idempotency key is sha256(event, type, id, updated_at).
 *    The payload holds a whitelist of ids / status / amounts / dates only.
 *  - challenge_credits gains `source` (store | web_gift, default store → every
 *    existing row is a store credit), a nullable purchase_event_id (a gift credit
 *    has no RevenueCat event) and gift_voucher_id (UNIQUE: one credit per voucher).
 *    A CHECK keeps exactly one reference per source.
 *
 * Timestamps are `timestamp` (UTC), like every other table of this app.
 */
return new class extends Migration
{
    private const VOUCHER_STATUSES = "'purchased', 'redeemed', 'refunded', 'expired', 'disabled'";

    private const EVENT_OUTCOMES = "'recorded', 'voucher_created', 'voucher_updated', 'refunded', 'revoked', 'disabled', 'expired', "
        ."'partial_refund', 'ignored', 'foreign_store', 'unknown_product', 'test_mode_ignored'";

    public function up(): void
    {
        Schema::create('gift_vouchers', function (Blueprint $table) {
            $table->id();
            $table->char('key_hash', 64)->unique();
            $table->char('key_last4', 4);
            $table->unsignedBigInteger('ls_license_key_id')->unique();
            $table->unsignedBigInteger('ls_order_id')->index();
            $table->string('environment', 16);
            $table->string('status', 16)->default('purchased');
            $table->timestamp('purchased_at');
            $table->timestamp('expires_at');
            // Crash recovery only (set just before the LS activate call). No FK.
            $table->unsignedBigInteger('claim_family_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            // The voucher outlives the family as a financial record.
            $table->foreignId('family_id')->nullable()->constrained('families')->nullOnDelete();
            $table->string('ls_instance_id', 64)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE gift_vouchers ADD CONSTRAINT gift_vouchers_environment_check CHECK (environment IN ('SANDBOX', 'PRODUCTION'))");
        DB::statement('ALTER TABLE gift_vouchers ADD CONSTRAINT gift_vouchers_status_check CHECK (status IN ('.self::VOUCHER_STATUSES.'))');
        DB::statement("ALTER TABLE gift_vouchers ADD CONSTRAINT gift_vouchers_redeemed_check CHECK (
            (status IN ('purchased', 'expired') AND redeemed_at IS NULL AND family_id IS NULL)
            OR (status = 'redeemed' AND redeemed_at IS NOT NULL)
            OR (status IN ('refunded', 'disabled')))");

        Schema::create('lemonsqueezy_events', function (Blueprint $table) {
            $table->id();
            $table->char('idempotency_key', 64)->unique();
            $table->string('event_name', 64);
            $table->string('object_type', 32)->nullable();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->unsignedBigInteger('ls_order_id')->nullable()->index();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->boolean('test_mode')->default(false);
            $table->jsonb('payload');
            $table->string('outcome', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['event_name', 'ls_order_id']);
        });
        DB::statement('ALTER TABLE lemonsqueezy_events ADD CONSTRAINT lemonsqueezy_events_outcome_check CHECK (outcome IS NULL OR outcome IN ('.self::EVENT_OUTCOMES.'))');

        Schema::table('challenge_credits', function (Blueprint $table) {
            $table->string('source', 16)->default('store');
            // RESTRICT (default): a voucher with a credit is never deleted from under it.
            $table->foreignId('gift_voucher_id')->nullable()->unique()->constrained('gift_vouchers');
        });
        DB::statement('ALTER TABLE challenge_credits ALTER COLUMN source SET NOT NULL');
        // UNIQUE + FK stay; Postgres UNIQUE allows many NULLs.
        DB::statement('ALTER TABLE challenge_credits ALTER COLUMN purchase_event_id DROP NOT NULL');
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_source_check CHECK (source IN ('store', 'web_gift'))");
        DB::statement("ALTER TABLE challenge_credits ADD CONSTRAINT challenge_credits_source_ref_check CHECK (
            (source = 'store' AND purchase_event_id IS NOT NULL AND gift_voucher_id IS NULL)
            OR (source = 'web_gift' AND purchase_event_id IS NULL AND gift_voucher_id IS NOT NULL))");
    }

    public function down(): void
    {
        // Never rewrite purchase history: a redeemed gift credit cannot exist without its columns.
        if (DB::table('challenge_credits')->where('source', 'web_gift')->exists()) {
            throw new RuntimeException('Refusing to roll back M6-S01: website gift credits exist (challenge_credits.source = web_gift). Decide with David first.');
        }

        DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_source_ref_check');
        DB::statement('ALTER TABLE challenge_credits DROP CONSTRAINT IF EXISTS challenge_credits_source_check');
        Schema::table('challenge_credits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gift_voucher_id');
            $table->dropColumn('source');
        });
        DB::statement('ALTER TABLE challenge_credits ALTER COLUMN purchase_event_id SET NOT NULL');

        Schema::dropIfExists('lemonsqueezy_events');
        Schema::dropIfExists('gift_vouchers');
    }
};
