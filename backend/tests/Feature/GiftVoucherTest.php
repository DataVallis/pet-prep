<?php

use App\Jobs\SendGiftRedeemedPush;
use App\Jobs\SendShopAlert;
use App\Models\ChallengeCredit;
use App\Models\GiftVoucher;
use App\Models\OutboundEmail;
use App\Models\Pet;
use App\Models\ShopOrder;
use App\Models\StripeEvent;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| M6-S09 (WEBSITE_SALES_SPEC §3.3–§3.4): website gift vouchers on Stripe — the code is
| ours, created once per paid order; redemption is local; refunds and disputes come
| from the Stripe webhook. Checkout / thank-you page / downloads: ShopTest.
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../Support/ShopFakes.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(SH_NOW, 'UTC'));
    seedBreedConfigs();
    shConfig();
    gvSwitch(true);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('webhook → order + voucher', function () {
    it('G1: checkout.session.completed creates one paid order, one voucher with a PP code and one queued e-mail', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('a', 24);
        $stripe->sessions[$id] = shSession($id);

        shWebhook(shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'fulfilled');

        $order = ShopOrder::sole();
        $voucher = GiftVoucher::sole();
        $code = $voucher->code();
        expect($order->status)->toBe('paid')
            ->and($order->number)->toBe(sprintf('PP-2026-%06d', $order->id))
            ->and($order->customer_email)->toBe('buyer@example.com')
            ->and($order->customer_country)->toBe('SI')
            ->and($order->amount_total)->toBe(4999)
            ->and($order->amount_tax)->toBe(901)
            ->and($order->locale)->toBe('sl')
            ->and($order->fulfilled_at)->not->toBeNull()
            ->and($code)->toMatch('/^PP-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/')
            ->and($voucher->shop_order_id)->toBe($order->id)
            ->and($voucher->environment)->toBe('PRODUCTION')
            ->and($voucher->expires_at->toDateString())->toBe('2027-11-17');

        $email = OutboundEmail::sole();
        expect($email->to_address)->toBe('buyer@example.com')
            ->and($email->status)->toBe('pending')
            ->and($email->locale)->toBe('sl')
            ->and($email->subject)->toBe('Vaš darilni bon PetPrep')
            ->and($email->html)->toContain($code)->toContain('petprep.si/sl/unovci')
            ->and($email->attachments)->toBe([['type' => 'voucher_pdf', 'voucher_id' => $voucher->id]]);
    });

    it('G2: the code is stored only hashed + encrypted (never in plain text) and never logged in the ledger', function () {
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        $row = (array) DB::table('gift_vouchers')->first();
        $flat = json_encode($row);
        expect($flat)->not->toContain($code)
            ->and($flat)->not->toContain(GiftVoucher::canonical($code))
            ->and($row['key_hash'])->toBe(hash('sha256', GiftVoucher::canonical($code)));
    });

    it('G3: the same event twice and the thank-you page at once never make a second voucher or e-mail', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('b', 24);
        $stripe->sessions[$id] = shSession($id);
        $event = shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']], id: 'evt_same');

        test()->getJson('/api/shop/orders/'.$id)->assertOk();          // the page first
        shWebhook($event)->assertOk()->assertJsonPath('outcome', 'fulfilled');
        shWebhook($event)->assertOk()->assertJsonPath('duplicate', true);
        shWebhook(shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']]))->assertOk();

        expect(ShopOrder::count())->toBe(1)->and(GiftVoucher::count())->toBe(1)->and(OutboundEmail::count())->toBe(1)
            ->and(StripeEvent::count())->toBe(2);
    });

    it('G4: an unpaid (delayed) session stays pending; async_payment_succeeded fulfils it, async_payment_failed fails it', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('c', 24);
        $stripe->sessions[$id] = shSession($id, paymentStatus: 'unpaid');
        shWebhook(shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'pending');
        expect(ShopOrder::sole()->status)->toBe('pending')->and(GiftVoucher::count())->toBe(0);

        $stripe->pay($id);
        shWebhook(shEvent('checkout.session.async_payment_succeeded', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'fulfilled');
        expect(GiftVoucher::count())->toBe(1);

        $other = 'cs_test_'.str_repeat('d', 24);
        $stripe->sessions[$other] = shSession($other, paymentStatus: 'unpaid');
        shWebhook(shEvent('checkout.session.completed', ['id' => $other, 'metadata' => ['petprep_product' => 'gift_voucher']]))->assertOk();
        shWebhook(shEvent('checkout.session.async_payment_failed', ['id' => $other, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'failed');
        expect(ShopOrder::where('stripe_checkout_session_id', $other)->sole()->status)->toBe('failed');
    });

    it('G5: not ours — another sale of the account, a foreign price, a quantity of 2 — creates nothing', function () {
        $stripe = shStripe();
        shWebhook(shEvent('checkout.session.completed', ['id' => 'cs_test_other_site', 'metadata' => []]))
            ->assertOk()->assertJsonPath('outcome', 'not_ours');

        $id = 'cs_test_'.str_repeat('e', 24);
        $stripe->sessions[$id] = shSession($id, price: 'price_someone_else');
        shWebhook(shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'not_ours');

        $two = 'cs_test_'.str_repeat('f', 24);
        $stripe->sessions[$two] = array_replace_recursive(shSession($two), ['line_items' => ['data' => [['price' => ['id' => SH_PRICE_GIFT], 'quantity' => 2]]]]);
        shWebhook(shEvent('checkout.session.completed', ['id' => $two, 'metadata' => ['petprep_product' => 'gift_voucher']]))
            ->assertOk()->assertJsonPath('outcome', 'not_ours');

        expect(ShopOrder::count())->toBe(0)->and(GiftVoucher::count())->toBe(0);
    });

    it('G6: test-mode events and sessions are ignored unless STRIPE_ACCEPT_TEST_MODE; then the voucher is SANDBOX', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('g', 24);
        $stripe->sessions[$id] = shSession($id, livemode: false);
        $body = shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']], livemode: false);
        shWebhook($body)->assertOk()->assertJsonPath('outcome', 'test_mode_ignored');
        expect(ShopOrder::count())->toBe(0);

        config(['services.stripe.accept_test_mode' => true]);
        shWebhook(shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']], livemode: false))
            ->assertOk()->assertJsonPath('outcome', 'fulfilled');
        expect(GiftVoucher::sole()->environment)->toBe('SANDBOX');
    });

    it('G7: signature — no secret 503, missing / wrong / stale signature 401', function () {
        shStripe();
        $body = shEvent('charge.refunded', ['id' => 'ch_1', 'payment_intent' => 'pi_x']);
        shWebhook($body, header: '')->assertStatus(401);
        shWebhook($body, header: 't='.now()->getTimestamp().',v1=deadbeef')->assertStatus(401);
        shWebhook($body, t: now()->getTimestamp() - 600)->assertStatus(401);
        shWebhook($body)->assertOk();

        config(['services.stripe.webhook_secret' => '']);
        shWebhook($body)->assertStatus(503);
    });

    it('G8: Stripe unreachable while fulfilling → 503 and the event is not swallowed (the retry fulfils)', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('h', 24);
        $stripe->sessions[$id] = shSession($id);
        $event = shEvent('checkout.session.completed', ['id' => $id, 'metadata' => ['petprep_product' => 'gift_voucher']], id: 'evt_retry');
        $stripe->down = true;
        shWebhook($event)->assertStatus(503);
        expect(StripeEvent::count())->toBe(0);

        $stripe->down = false;
        shWebhook($event)->assertOk()->assertJsonPath('outcome', 'fulfilled');
        expect(GiftVoucher::count())->toBe(1);
    });
});

describe('redeem', function () {
    it('R1: a valid code → one unassigned web_gift credit for the family, push after commit; replay → already', function () {
        Queue::fake([SendGiftRedeemedPush::class]);
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        [$parent, , $family] = gvParent();

        gvRedeem($code, $parent)->assertOk()->assertExactJson(['status' => 'redeemed', 'already' => false]);
        $credit = ChallengeCredit::where('source', 'web_gift')->sole();
        expect($credit->family_id)->toBe($family->id)
            ->and($credit->pet_id)->toBeNull()
            ->and($credit->store)->toBe('STRIPE')
            ->and($credit->product_id)->toBe('stripe:'.SH_PRICE_GIFT)
            ->and(GiftVoucher::sole()->status)->toBe('redeemed');
        Queue::assertPushed(SendGiftRedeemedPush::class, 1);

        gvRedeem($code, $parent)->assertOk()->assertJsonPath('already', true);
        expect(ChallengeCredit::count())->toBe(1);
        Queue::assertPushed(SendGiftRedeemedPush::class, 1);
    });

    it('R2: the code is forgiving — lower case, spaces, no dashes, without PP, O for 0 and I / L for 1', function () {
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        gvParent();
        $typed = strtolower(str_replace('-', ' ', substr($code, 3)));
        $typed = strtr($typed, ['0' => 'o', '1' => 'l']);

        gvRedeem('  '.$typed.' ')->assertOk()->assertJsonPath('status', 'redeemed');
    });

    it('R3: another family → 409 already_redeemed; unknown code → invalid_code; nothing changes', function () {
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        [$a] = gvParent('a@example.com');
        [$b] = gvParent('b@example.com');
        gvRedeem($code, $a)->assertOk();

        gvRedeem($code, $b)->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        gvRedeem('PP-0000-0000-0000', $b)->assertStatus(422)->assertJsonPath('error', 'invalid_code');
        gvRedeem('PP-00000', $b)->assertStatus(422)->assertJsonPath('error', 'invalid_code');
        expect(ChallengeCredit::count())->toBe(1);
    });

    it('R4: expired → expired, refunded → refunded, switch off → 503 unavailable', function () {
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        [, $refundedCode] = shPaidOrder($stripe);
        GiftVoucher::where('key_hash', GiftVoucher::hashOf(GiftVoucher::canonical($refundedCode)))->update(['status' => 'refunded', 'refunded_at' => now()]);
        [$parent] = gvParent();

        gvRedeem($refundedCode, $parent)->assertStatus(422)->assertJsonPath('error', 'refunded');
        gvSwitch(false);
        gvRedeem($code, $parent)->assertStatus(503)->assertJsonPath('error', 'unavailable');
        gvSwitch(true);

        Carbon::setTestNow(Carbon::parse(SH_NOW, 'UTC')->addYear()->addDay());
        gvRedeem($code, $parent)->assertStatus(422)->assertJsonPath('error', 'expired');
        expect(GiftVoucher::where('status', 'expired')->count())->toBe(1)->and(ChallengeCredit::count())->toBe(0);
    });

    it('R5: a SANDBOX voucher does not redeem in production mode (STRIPE_ACCEPT_TEST_MODE false)', function () {
        $stripe = shStripe();
        config(['services.stripe.accept_test_mode' => true]);
        [, $code] = shPaidOrder($stripe, session: ['livemode' => false]);
        config(['services.stripe.accept_test_mode' => false]);
        gvParent();

        gvRedeem($code)->assertStatus(422)->assertJsonPath('error', 'invalid_code');
    });

    it('R6: the gift credit is assigned like a bought one (activate a pet waiting for payment)', function () {
        $stripe = shStripe();
        [, $code] = shPaidOrder($stripe);
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem($code, $parent)->assertOk();

        gvActivate($parent, $pet)->assertOk();
        expect(ChallengeCredit::sole()->pet_id)->toBe($pet->id);
    });
});

describe('refunds and disputes', function () {
    it('F1: a full refund before redemption → order + voucher refunded, redeem says refunded', function () {
        $stripe = shStripe();
        [$order, $code] = shPaidOrder($stripe);
        gvParent();

        shWebhook(shEvent('charge.refunded', ['id' => 'ch_1', 'payment_intent' => $order->stripe_payment_intent_id, 'amount' => 4999, 'amount_refunded' => 4999, 'refunded' => true]))
            ->assertOk()->assertJsonPath('outcome', 'refunded');

        expect($order->fresh()->status)->toBe('refunded')->and(GiftVoucher::sole()->status)->toBe('refunded');
        gvRedeem($code)->assertStatus(422)->assertJsonPath('error', 'refunded');
    });

    it('F2: a full refund after redemption + assignment revokes the credit and locks the pet (existing refund rule)', function () {
        $stripe = shStripe();
        [$order, $code] = shPaidOrder($stripe);
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem($code, $parent)->assertOk();
        gvActivate($parent, $pet)->assertOk();

        shWebhook(shEvent('charge.refunded', ['id' => 'ch_2', 'payment_intent' => $order->stripe_payment_intent_id, 'amount' => 4999, 'amount_refunded' => 4999, 'refunded' => true]))
            ->assertOk();

        $credit = ChallengeCredit::sole();
        expect($credit->revoked_at)->not->toBeNull()
            ->and(GiftVoucher::sole()->status)->toBe('refunded')
            ->and(Pet::find($pet->id)->challenge_paid_at)->toBeNull()
            ->and(Pet::find($pet->id)->payment_locked_at)->not->toBeNull();
    });

    it('F3: a partial refund changes only the order and alerts the admin; the voucher stays valid', function () {
        Queue::fake([SendShopAlert::class]);
        $stripe = shStripe();
        [$order, $code] = shPaidOrder($stripe);
        gvParent();

        shWebhook(shEvent('charge.refunded', ['id' => 'ch_3', 'payment_intent' => $order->stripe_payment_intent_id, 'amount' => 4999, 'amount_refunded' => 1000, 'refunded' => false]))
            ->assertOk()->assertJsonPath('outcome', 'partial_refund');

        expect($order->fresh()->status)->toBe('partially_refunded')->and($order->fresh()->amount_refunded)->toBe(1000);
        Queue::assertPushed(SendShopAlert::class, fn (SendShopAlert $j) => $j->reason === 'partial_refund' && $j->orderId === $order->id);
        gvRedeem($code)->assertOk();
    });

    it('F4: a dispute opened → disputed + alert; won → paid again; lost → refunded and the voucher disabled', function () {
        Queue::fake([SendShopAlert::class]);
        $stripe = shStripe();
        [$order] = shPaidOrder($stripe);
        $pi = $order->stripe_payment_intent_id;

        shWebhook(shEvent('charge.dispute.created', ['id' => 'dp_1', 'payment_intent' => $pi, 'status' => 'needs_response']))->assertOk();
        expect($order->fresh()->status)->toBe('disputed');
        shWebhook(shEvent('charge.dispute.closed', ['id' => 'dp_1', 'payment_intent' => $pi, 'status' => 'won']))->assertOk();
        expect($order->fresh()->status)->toBe('paid');

        shWebhook(shEvent('charge.dispute.created', ['id' => 'dp_2', 'payment_intent' => $pi, 'status' => 'needs_response']))->assertOk();
        shWebhook(shEvent('charge.dispute.closed', ['id' => 'dp_2', 'payment_intent' => $pi, 'status' => 'lost']))
            ->assertOk()->assertJsonPath('outcome', 'dispute_lost');
        expect($order->fresh()->status)->toBe('refunded')->and(GiftVoucher::sole()->status)->toBe('disabled');
        Queue::assertPushed(SendShopAlert::class, fn (SendShopAlert $j) => $j->reason === 'dispute_lost');
    });

    it('F5: a refund of an unknown payment is recorded as unknown_order', function () {
        shStripe();
        shWebhook(shEvent('charge.refunded', ['id' => 'ch_x', 'payment_intent' => 'pi_unknown', 'amount' => 100, 'amount_refunded' => 100, 'refunded' => true]))
            ->assertOk()->assertJsonPath('outcome', 'unknown_order');
    });
});
