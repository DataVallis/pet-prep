<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M6-S09 (WEBSITE_SALES_SPEC §3.4–§3.7, David 2026-11-17): the website shop moves from
 * Lemon Squeezy to Stripe Managed Payments, with our own codes, an e-mail queue and an
 * admin shop.
 *
 *  - shop_products: admin copy of the Stripe products / prices (Stripe is the source of truth).
 *  - shop_orders: one row per Stripe Checkout Session (buyer e-mail kept — needed to send the code).
 *  - stripe_events: the Stripe webhook ledger, one row per `event.id` (no personal data).
 *  - outbound_emails: every PetPrep e-mail, full HTML stored, sent by `emails:send` with retries.
 *  - shop_audit_logs: who revealed / re-sent a code and when.
 *  - gift_vouchers: Lemon Squeezy columns replaced — the code is ours now (hash + encrypted code).
 *  - lemonsqueezy_events: dropped (Lemon Squeezy never sold anything for PetPrep).
 *
 * Deletes the Lemon Squeezy test (SANDBOX) vouchers and their unassigned credits; refuses to run when a
 * real voucher exists or a test credit is used by a pet — a real voucher is never rewritten silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gift_vouchers')) {
            // Test (SANDBOX) vouchers of the Lemon Squeezy era go, with their credit when no pet uses it
            // (David 2026-11-17: production held one — voucher #1 / credit #8 of a redeem test).
            $sandbox = DB::table('gift_vouchers')->where('environment', 'SANDBOX')->pluck('id');
            $usedCredit = DB::table('challenge_credits')->whereIn('gift_voucher_id', $sandbox)->whereNotNull('pet_id')->exists();
            if ($usedCredit || DB::table('gift_vouchers')->where('environment', '!=', 'SANDBOX')->exists()) {
                // A real voucher, or a test credit a pet uses, is never rewritten silently.
                throw new RuntimeException('Refusing M6-S09: gift_vouchers has a real Lemon Squeezy row or a test credit in use. Decide with David first.');
            }
            DB::table('challenge_credits')->whereIn('gift_voucher_id', $sandbox)->delete();
            DB::table('gift_vouchers')->whereIn('id', $sandbox)->delete();
        }

        Schema::create('shop_products', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('stripe_product_id', 64)->nullable();
            $table->string('stripe_price_id', 64)->nullable();
            $table->unsignedInteger('unit_amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('tax_inclusive')->default(true);
            $table->boolean('active')->default(true);
            $table->boolean('livemode')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->nullable()->unique();
            $table->string('product_key', 32)->index();
            $table->string('stripe_checkout_session_id', 255)->unique();
            $table->string('stripe_payment_intent_id', 64)->nullable()->index();
            $table->boolean('livemode')->default(false);
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('amount_total')->nullable();
            $table->unsignedInteger('amount_tax')->nullable();
            $table->unsignedInteger('amount_refunded')->default(0);
            $table->char('currency', 3)->nullable();
            $table->string('locale', 8);
            $table->string('customer_email', 254)->nullable()->index();
            $table->string('customer_name', 160)->nullable();
            $table->char('customer_country', 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('disputed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE shop_orders ADD CONSTRAINT shop_orders_status_check CHECK (status IN ('pending', 'paid', 'refunded', 'partially_refunded', 'disputed', 'failed'))");

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('type', 64);
            $table->string('object_id', 255)->nullable();
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->nullOnDelete();
            $table->boolean('livemode')->default(false);
            $table->string('outcome', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at']);
        });

        Schema::create('outbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->nullOnDelete();
            $table->string('to_address', 254);
            $table->string('to_name', 160)->nullable();
            $table->string('subject', 255);
            $table->longText('html');
            $table->longText('text')->nullable();
            // [{type: voucher_pdf|shop_file, ...}] — files are built at send time, never stored here.
            $table->jsonb('attachments')->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE outbound_emails ADD CONSTRAINT outbound_emails_status_check CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'cancelled'))");

        Schema::create('shop_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::dropIfExists('lemonsqueezy_events');

        // The free promise kit sample is handed out for an e-mail (spec §4.2). summary_leads is gone
        // once 2027_04_24_120000_create_email_contacts_and_signups ran (its source is free text there);
        // the guard only matters when a test re-runs this migration on the newer schema.
        if (Schema::hasTable('summary_leads')) {
            DB::statement('ALTER TABLE summary_leads DROP CONSTRAINT summary_leads_source_check');
            DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_source_check CHECK (source IN ('real_cost', 'breed_quiz', 'promise_kit_sample'))");
        }

        Schema::table('gift_vouchers', function (Blueprint $table) {
            $table->dropUnique(['ls_license_key_id']);
            $table->dropIndex(['ls_order_id']);
            $table->dropColumn(['ls_license_key_id', 'ls_order_id', 'claim_family_id', 'claimed_at', 'ls_instance_id']);
        });
        Schema::table('gift_vouchers', function (Blueprint $table) {
            // One voucher per order (quantity 1). RESTRICT: an order with a voucher is never deleted.
            $table->foreignId('shop_order_id')->unique()->constrained('shop_orders');
            $table->text('code_encrypted');
        });
    }

    public function down(): void
    {
        if (DB::table('gift_vouchers')->exists()) {
            throw new RuntimeException('Refusing to roll back M6-S09: Stripe gift vouchers exist. Decide with David first.');
        }

        if (Schema::hasTable('summary_leads')) { // gone after 2027_04_24_120000 (see up())
            DB::table('summary_leads')->where('source', 'promise_kit_sample')->update(['source' => 'real_cost']);
            DB::statement('ALTER TABLE summary_leads DROP CONSTRAINT summary_leads_source_check');
            DB::statement("ALTER TABLE summary_leads ADD CONSTRAINT summary_leads_source_check CHECK (source IN ('real_cost', 'breed_quiz'))");
        }

        Schema::table('gift_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_order_id');
            $table->dropColumn('code_encrypted');
        });
        Schema::table('gift_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('ls_license_key_id')->unique();
            $table->unsignedBigInteger('ls_order_id')->index();
            $table->unsignedBigInteger('claim_family_id')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('ls_instance_id', 64)->nullable();
        });
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
        });
        Schema::dropIfExists('shop_audit_logs');
        Schema::dropIfExists('outbound_emails');
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('shop_products');
    }
};
