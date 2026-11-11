<?php

use App\Jobs\SendGiftVoucherAlert;
use App\Models\ChallengeCredit;
use App\Models\GiftVoucher;
use App\Services\Gift\GiftVoucherRedeemService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/GiftVoucherFakes.php';

/*
|--------------------------------------------------------------------------
| M6-S01 — QA fixes (QA_M6S01_SERVER.md: MA1, m1–m5, m7)
|--------------------------------------------------------------------------
| Helpers: tests/Support/GiftVoucherFakes.php (GvLs, gvParent, gvRedeem, gvWebhook …).
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(GV_NOW, 'UTC'));
    seedBreedConfigs();
    config([
        'services.lemonsqueezy.webhook_secret' => GV_SECRET,
        'services.lemonsqueezy.store_id' => (string) GV_STORE,
        'services.lemonsqueezy.gift_product_id' => (string) GV_PRODUCT,
        'services.lemonsqueezy.gift_variant_id' => (string) GV_VARIANT,
        'services.lemonsqueezy.api_base' => GV_API,
        'services.lemonsqueezy.api_key' => null,
        'services.lemonsqueezy.accept_test_mode' => false,
        'services.discord.alerts_webhook_url' => GV_DISCORD,
        'payments.enforced' => true,
    ]);
    gvSwitch(true);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('MA1: an LS answer without meta.order_id is refused and nothing is stored (never ls_order_id 0)', function () {
    $ls = gvLs();
    $ls->orderId = null;
    gvParent();

    gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
    expect(GiftVoucher::count())->toBe(0)->and(ChallengeCredit::count())->toBe(0)->and($ls->count('activate'))->toBe(0);
});

it('m1: a concurrent redeem by the same family (no API key) answers already:true — no 409, no alert', function () {
    Queue::fake();
    $ls = gvLs();
    [, , $family] = gvParent();
    $inner = null;
    $ls->beforeActivate = function () use (&$inner, $family) {
        if ($inner !== null) {
            return;
        }
        $inner = false;
        // The family's other tab finishes the whole redemption while this request waits on LS.
        $inner = app(GiftVoucherRedeemService::class)->redeem(GV_CODE, $family);
    };

    $outer = app(GiftVoucherRedeemService::class)->redeem(GV_CODE, $family);

    expect($inner->error)->toBeNull()->and($inner->already)->toBeFalse()
        ->and($outer->error)->toBeNull()->and($outer->already)->toBeTrue()
        ->and(ChallengeCredit::count())->toBe(1);
    Queue::assertNotPushed(SendGiftVoucherAlert::class);
});

it('m2: the code goes to LS exactly as entered (trimmed, case kept); our hash is case-insensitive', function () {
    $ls = gvLs();
    $ls->key = strtoupper(GV_CODE); // a mixed / upper-case key at LS
    gvParent();

    gvRedeem('  '.strtoupper(GV_CODE).' ')->assertOk()->assertJsonPath('already', false);
    expect(collect($ls->calls)->pluck('1.license_key')->unique()->values()->all())->toBe([strtoupper(GV_CODE)])
        ->and(GiftVoucher::sole()->key_hash)->toBe(hash('sha256', GV_CODE));

    // A lower-case retype finds our own row (no LS call needed for a replay).
    gvRedeem(GV_CODE)->assertOk()->assertJsonPath('already', true);
});

it('m3: a refund recorded before license_key_created makes the new row refunded, never purchased', function () {
    gvLs();
    gvRefund()->assertJsonPath('outcome', 'refunded');
    gvWebhook(gvLicenseKeyEvent('license_key_created'))->assertOk();
    expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_REFUNDED);

    // A later update never downgrades it.
    gvWebhook(gvLicenseKeyEvent('license_key_updated', ['status' => 'active', 'updated_at' => '2026-11-14T00:00:00Z']))->assertOk();
    expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_REFUNDED);
});

it('m3: the webhook fills the purchase date of a row created by the redeem path', function () {
    $ls = gvLs();
    gvParent();
    gvWebhook(gvOrderEvent('order_created', ['created_at' => '2026-10-20T08:00:00Z', 'updated_at' => '2026-10-20T08:00:00Z']));
    $ls->createdAt = null; // LS validate without created_at → the order's date is used (m4)
    $ls->activateFails = 500; // M6-S01b: lazy row + claim, then LS refuses → nothing redeemed
    gvRedeem()->assertStatus(502);
    expect(GiftVoucher::sole()->purchased_at->toIso8601String())->toBe('2026-10-20T08:00:00+00:00');

    gvWebhook(gvLicenseKeyEvent('license_key_created', ['created_at' => '2026-10-20T08:00:05Z', 'updated_at' => '2026-10-20T08:00:05Z']));
    $v = GiftVoucher::sole();
    expect($v->purchased_at->toIso8601String())->toBe('2026-10-20T08:00:05+00:00')
        ->and($v->expires_at->toIso8601String())->toBe('2027-10-20T08:00:05+00:00');
});

it('m4: no created_at from LS and no order in the ledger → upstream_error, nothing stored', function () {
    $ls = gvLs();
    $ls->createdAt = null;
    gvParent();
    gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
    expect(GiftVoucher::count())->toBe(0)->and($ls->count('activate'))->toBe(0);
});

it('m4: no created_at from LS → the order date from the webhook ledger starts the 12 months', function () {
    $ls = gvLs();
    $ls->createdAt = null;
    gvParent();
    gvWebhook(gvOrderEvent('order_created', ['created_at' => '2025-11-01T09:00:00Z', 'updated_at' => '2025-11-01T09:00:00Z']));

    // Bought 12 months + 10 days ago → expired, not »valid from today«.
    gvRedeem()->assertStatus(422)->assertJsonPath('error', 'expired');
});

it('m5: accepting a test-mode key in production logs an error (System health)', function () {
    Log::spy();
    $ls = gvLs();
    $ls->testMode = true;
    config(['services.lemonsqueezy.accept_test_mode' => true]);
    app()->detectEnvironment(fn () => 'production');
    gvParent();

    gvRedeem()->assertOk();
    Log::shouldHaveReceived('error')->withArgs(fn (string $msg) => str_contains($msg, 'test-mode'))->once();
    app()->detectEnvironment(fn () => 'testing');
});

it('m7: a refund of an order whose first item is another product still revokes the gift voucher of that order', function () {
    gvLs();
    gvParent();
    gvRedeem()->assertOk();

    gvWebhook(gvOrderEvent('order_refunded', [
        'status' => 'refunded', 'refunded' => true, 'refunded_amount' => 5689, 'updated_at' => '2026-11-12T09:00:00Z',
        'first_order_item' => ['order_id' => 8001, 'product_id' => 999, 'variant_id' => 1],
    ]))->assertJsonPath('outcome', 'revoked');
    expect(ChallengeCredit::sole()->revoked_at)->not->toBeNull()->and(GiftVoucher::sole()->status)->toBe('refunded');
});

it('m7: an order listing the gift among its items counts as a gift order', function () {
    gvLs();
    gvWebhook(gvOrderEvent('order_created', [
        'first_order_item' => ['order_id' => 8001, 'product_id' => 999, 'variant_id' => 1],
        'order_items' => [['product_id' => 999, 'variant_id' => 1], ['product_id' => GV_PRODUCT, 'variant_id' => GV_VARIANT]],
    ]))->assertJsonPath('outcome', 'recorded');
    // Another store's order with no voucher row stays unknown_product.
    gvWebhook(gvOrderEvent('order_created', [
        'first_order_item' => ['order_id' => 8002, 'product_id' => 999, 'variant_id' => 1], 'updated_at' => '2026-11-02T00:00:00Z',
    ], 8002))->assertJsonPath('outcome', 'unknown_product');
});
