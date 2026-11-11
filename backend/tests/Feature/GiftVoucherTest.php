<?php

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\FamilyRole;
use App\Enums\PetPlan;
use App\Filament\Pages\FeatureSwitches;
use App\Filament\Resources\GiftVoucherResource;
use App\Http\Middleware\SetAdminLocale;
use App\Jobs\SendGiftRedeemedPush;
use App\Jobs\SendGiftVoucherAlert;
use App\Jobs\SendPushNotification;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\ChallengeCredit;
use App\Models\Family;
use App\Models\GiftVoucher;
use App\Models\LemonSqueezyEvent;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Services\Alerts\DiscordWebhook;
use App\Services\AppSettingsService;
use App\Services\ChallengeCreditService;
use App\Services\FamilyService;
use App\Services\Funnel\FunnelService;
use App\Services\Gift\GiftRedeemResult;
use App\Services\Gift\GiftVoucherRedeemService;
use App\Services\Gift\LemonSqueezyWebhookService;
use App\Services\Media\MediaEntitlementService;
use App\Services\Push\ExpoPushClient;
use App\Support\WebsiteLocale;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| M6-S01 — website gift voucher (WEBSITE_SALES_SPEC §3.4, CONTRACT §9.1)
|--------------------------------------------------------------------------
| The voucher code = a Lemon Squeezy license key. Lemon Squeezy is faked by
| GvLs (Http::fake on services.lemonsqueezy.api_base); Discord and Expo are
| faked too. Every test runs with the »Gift vouchers« switch ON unless it
| says otherwise. The redeem limiter is live (like register).
*/

require_once __DIR__.'/../Support/GiftVoucherFakes.php';

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

/* ───────────────────────────── Redeem ───────────────────────────── */

describe('redeem', function () {
    it('S1: redeems a valid code for the parent: one unassigned web_gift credit, LS activated once, push after commit', function () {
        Queue::fake([SendGiftRedeemedPush::class]);
        $ls = gvLs();
        [$parent, , $family] = gvParent();

        gvRedeem()->assertOk()->assertExactJson(['status' => 'redeemed', 'already' => false]);

        $credit = ChallengeCredit::sole();
        $voucher = GiftVoucher::sole();
        expect($credit)
            ->family_id->toBe($family->id)
            ->source->toBe(ChallengeCredit::SOURCE_WEB_GIFT)
            ->gift_voucher_id->toBe($voucher->id)
            ->purchase_event_id->toBeNull()
            ->store->toBe('LEMON_SQUEEZY')
            ->product_id->toBe('lemonsqueezy:'.GV_PRODUCT)
            ->environment->toBe('PRODUCTION')
            ->pet_id->toBeNull()
            ->assigned_at->toBeNull()
            ->and($credit->purchased_at->equalTo(now()->startOfSecond()))->toBeTrue()
            ->and($voucher)
            ->status->toBe(GiftVoucher::STATUS_REDEEMED)
            ->family_id->toBe($family->id)
            ->ls_license_key_id->toBe(7001)
            ->ls_order_id->toBe(8001)
            ->key_last4->toBe('4d51')
            ->claim_family_id->toBeNull()
            ->ls_instance_id->not->toBeNull()
            ->and($voucher->expires_at->equalTo(Carbon::parse('2027-11-01 09:00:00', 'UTC')))->toBeTrue()
            ->and($ls->count('activate'))->toBe(1)
            ->and(collect($ls->calls)->firstWhere(0, 'activate')[1]['instance_name'])->toBe('family:'.$family->id);
        Queue::assertPushed(SendGiftRedeemedPush::class, fn (SendGiftRedeemedPush $j) => $j->voucherId === $voucher->id);
        expect(gvBilling($parent)['credits_available'])->toBe(1);
    });

    it('S2: a replay by the same family answers already:true — no second credit, no second activation', function () {
        $ls = gvLs();
        gvParent();
        gvRedeem()->assertOk()->assertJsonPath('already', false);
        gvRedeem()->assertOk()->assertExactJson(['status' => 'redeemed', 'already' => true]);
        gvRedeem(strtoupper(GV_CODE), 'PARENT@example.com')->assertOk()->assertJsonPath('already', true);

        expect(ChallengeCredit::count())->toBe(1)->and($ls->count('activate'))->toBe(1);
    });

    it('S3: the same code from another family is 409 already_redeemed', function () {
        gvLs();
        gvParent();
        gvParent('other@example.com');
        gvRedeem()->assertOk();

        gvRedeem(as: 'other@example.com')->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        // M6-S01b: without a web token nothing is looked up at all.
        gvRedeem(as: null)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        expect(ChallengeCredit::count())->toBe(1);
    });

    it('S4 (M6-S01b GR1): no token / an expired web token / app tokens are 401 unauthenticated — nothing touched; a web token then works', function () {
        $ls = gvLs();
        [$parent, $child] = gvParent();

        gvRedeem(as: null)->assertStatus(401)->assertExactJson(['error' => 'unauthenticated', 'message' => __('gift.unauthenticated', [], 'en')]);
        gvRedeem(token: gvWebToken($parent, now()->subSecond()))->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        $expiring = gvWebToken($parent);
        $this->travel(16)->minutes();
        gvRedeem(token: $expiring)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        $this->travelBack();
        Carbon::setTestNow(Carbon::parse(GV_NOW, 'UTC'));
        // W3: app tokens never redeem (no app API for the voucher).
        gvRedeem(token: $parent->createToken('iPhone', ['parent'])->plainTextToken)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        gvRedeem(token: $child->createToken('kid', ['child'])->plainTextToken)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        gvRedeem(token: $parent->createToken('legacy', ['*'])->plainTextToken)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        // A web token without an expiry is not one WebParentSessionService issues → 401 too.
        gvRedeem(token: $parent->createToken('web:gift-redeem', ['gift:redeem'])->plainTextToken)->assertStatus(401);

        expect($ls->calls)->toBe([])->and(GiftVoucher::count())->toBe(0)->and(ChallengeCredit::count())->toBe(0);

        gvRedeem(as: $parent)->assertOk()->assertJsonPath('already', false);
    });

    it('S5 (M6-S01b GR2): a child, a parent without a family or a deleted parent cannot redeem', function () {
        $ls = gvLs();
        [$parent, $child] = gvParent();
        gvRedeem(as: $child)->assertStatus(403)->assertJsonPath('error', 'not_parent');

        $loner = User::factory()->parent()->create(['email' => 'loner@example.com']);
        $lonerToken = gvWebToken($loner);
        DB::table('family_user')->where('user_id', $loner->id)->delete();
        gvRedeem(token: $lonerToken)->assertStatus(403)->assertJsonPath('error', 'not_parent');

        $gone = User::factory()->parent()->create(['email' => 'gone@example.com']);
        $goneToken = gvWebToken($gone);
        DB::table('family_user')->where('user_id', $gone->id)->delete();
        DB::table('users')->where('id', $gone->id)->delete();
        gvRedeem(token: $goneToken)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');

        expect($ls->calls)->toBe([]);
    });

    it('S6: a password-less (Google-created) parent redeems for their family with a web token', function () {
        gvLs();
        [$parent, , $family] = gvParent('Parent.Google@Example.com');
        $parent->forceFill(['password' => null])->save();
        UserSocialAccount::create(['user_id' => $parent->id, 'provider' => 'google', 'subject' => 'g-sub-1', 'email' => 'other-provider-mail@example.com']);

        gvRedeem(as: $parent)->assertOk();
        expect(ChallengeCredit::sole()->family_id)->toBe($family->id);
    });

    it('S7: an unknown key is invalid_code — the same answer for every family, nothing stored', function () {
        $ls = gvLs();
        $ls->known = false;
        gvParent();
        gvParent('other@example.com');

        $one = gvRedeem()->assertStatus(422)->json();
        $other = gvRedeem(as: 'other@example.com')->assertStatus(422)->json();
        expect($one)->toBe($other)->and($one['error'])->toBe('invalid_code')
            ->and(GiftVoucher::count())->toBe(0)->and($ls->count('activate'))->toBe(0);
    });

    it('S8: a key of another store / product / variant is invalid_code and never activated', function (string $field) {
        $ls = gvLs();
        $ls->{$field} = 999;
        gvParent();

        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'invalid_code');
        expect($ls->count('activate'))->toBe(0)->and(GiftVoucher::count())->toBe(0);
    })->with(['store', 'product', 'variant']);

    it('S9: a test-mode key is invalid_code unless test mode is accepted — then SANDBOX', function () {
        $ls = gvLs();
        $ls->testMode = true;
        gvParent();
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'invalid_code');

        config(['services.lemonsqueezy.accept_test_mode' => true]);
        gvRedeem()->assertOk();
        expect(GiftVoucher::sole()->environment)->toBe('SANDBOX')->and(ChallengeCredit::sole()->environment)->toBe('SANDBOX');
    });

    it('S10: older than 12 months is expired (lazy path and local row); LS status expired is expired', function () {
        $ls = gvLs();
        gvParent();
        $ls->createdAt = Carbon::parse(GV_NOW)->subMonthsNoOverflow(12)->subDay()->toIso8601String();
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'expired');
        expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_EXPIRED);
        // Local row now: no further LS call needed.
        $before = $ls->count('validate');
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'expired');
        expect($ls->count('validate'))->toBe($before);

        // A local `purchased` row past its validity.
        GiftVoucher::query()->delete();
        $ls->createdAt = '2026-11-01T09:00:00.000000Z';
        gvWebhook(gvLicenseKeyEvent('license_key_created'))->assertJsonPath('outcome', 'voucher_created');
        Carbon::setTestNow(Carbon::parse('2027-11-02 10:00:00', 'UTC'));
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'expired');
        expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_EXPIRED);

        // LS says expired.
        Carbon::setTestNow(Carbon::parse(GV_NOW, 'UTC'));
        GiftVoucher::query()->delete();
        $ls->status = 'expired';
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'expired');
        expect(ChallengeCredit::count())->toBe(0);
    });

    it('S11: a refund before the redeem answers refunded — also when it arrived before any key row', function () {
        gvLs();
        gvParent();
        // Refund first, no key row yet.
        gvRefund()->assertOk()->assertJsonPath('outcome', 'refunded');
        expect(GiftVoucher::count())->toBe(0);
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'refunded');
        expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_REFUNDED)->and(ChallengeCredit::count())->toBe(0);

        // Key row first (webhook), then the refund marks it.
        GiftVoucher::query()->delete();
        LemonSqueezyEvent::query()->delete();
        gvWebhook(gvLicenseKeyEvent('license_key_created'))->assertJsonPath('outcome', 'voucher_created');
        gvRefund()->assertJsonPath('outcome', 'refunded');
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'refunded');
    });

    it('S12: an LS-disabled key answers refunded', function () {
        $ls = gvLs();
        $ls->status = 'disabled';
        gvParent();
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'refunded');
        expect(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_DISABLED)->and($ls->count('activate'))->toBe(0);
    });

    it('S13: switch off or no store / product configured → 503 unavailable, no LS call', function () {
        $ls = gvLs();
        gvParent();
        gvSwitch(false);
        gvRedeem()->assertStatus(503)->assertJsonPath('error', 'unavailable');

        gvSwitch(true);
        config(['services.lemonsqueezy.store_id' => '']);
        gvRedeem()->assertStatus(503)->assertJsonPath('error', 'unavailable');
        config(['services.lemonsqueezy.store_id' => (string) GV_STORE, 'services.lemonsqueezy.gift_product_id' => null]);
        gvRedeem()->assertStatus(503)->assertJsonPath('error', 'unavailable');
        expect($ls->calls)->toBe([]);
    });

    it('S13: no switch row at all = off', function () {
        AppSetting::query()->whereKey(AppSettingsService::GIFT_VOUCHERS_KEY)->delete();
        cache()->flush();
        gvLs();
        gvParent();
        expect(app(AppSettingsService::class)->giftVouchers(fresh: true))->toBeFalse();
        gvRedeem()->assertStatus(503)->assertJsonPath('error', 'unavailable');
    });

    it('S14: LS timeout / 500 / 429 on validate → 502 upstream_error, nothing written', function (string|int $mode) {
        $ls = gvLs();
        $ls->validateFails = $mode;
        gvParent();
        gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
        expect(GiftVoucher::count())->toBe(0)->and(ChallengeCredit::count())->toBe(0);
    })->with(['connection', 500, 429]);

    it('S15: LS fails on activate after the claim → 502; a retry from the same family succeeds', function () {
        $ls = gvLs();
        [, , $family] = gvParent();
        $ls->activateFails = 500;
        gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
        expect(GiftVoucher::sole())->status->toBe(GiftVoucher::STATUS_PURCHASED)->claim_family_id->toBe($family->id)
            ->and(ChallengeCredit::count())->toBe(0);

        $ls->activateFails = null;
        gvRedeem()->assertOk()->assertJsonPath('already', false);
        expect(ChallengeCredit::count())->toBe(1);
    });

    it('S15b: an activation refused for another reason (not used up) → 502 and no credit', function () {
        $ls = gvLs();
        gvParent();
        $ls->activateFails = 422;
        gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
        expect(ChallengeCredit::count())->toBe(0);
    });

    it('S16: crash recovery without an API key: usage 1/1 + our claim for this family → completes', function () {
        Queue::fake([SendGiftRedeemedPush::class, SendGiftVoucherAlert::class]);
        $ls = gvLs();
        [, , $family] = gvParent();
        // Simulate: claim written, LS activated, crash before the commit.
        $ls->activateFails = 'connection';
        gvRedeem()->assertStatus(502);
        $ls->activateFails = null;
        $ls->usage = 1;
        $ls->status = 'active';

        gvRedeem()->assertOk()->assertJsonPath('already', false);
        expect(GiftVoucher::sole())->status->toBe(GiftVoucher::STATUS_REDEEMED)->family_id->toBe($family->id)->ls_instance_id->toBeNull()
            ->and(ChallengeCredit::count())->toBe(1);
        Queue::assertNotPushed(SendGiftVoucherAlert::class);
    });

    it('S16: crash recovery with an API key: instance family:<id> → validate with instance_id → completes; another family\'s instance → 409 + alert', function () {
        Queue::fake([SendGiftRedeemedPush::class, SendGiftVoucherAlert::class]);
        config(['services.lemonsqueezy.api_key' => 'ls-admin-key']);
        $ls = gvLs();
        [, , $family] = gvParent();
        $ls->usage = 1;
        $ls->status = 'active';
        $ls->instances = [['identifier' => 'inst-ours', 'name' => 'family:'.$family->id]];

        gvRedeem()->assertOk()->assertJsonPath('already', false);
        expect(GiftVoucher::sole()->ls_instance_id)->toBe('inst-ours')
            ->and(collect($ls->calls)->filter(fn ($c) => $c[0] === 'validate' && ($c[1]['instance_id'] ?? null) === 'inst-ours'))->toHaveCount(1)
            ->and($ls->count('activate'))->toBe(0);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/license-key-instances') && $r->hasHeader('Authorization', 'Bearer ls-admin-key'));

        // Another key, activated for another family.
        ChallengeCredit::query()->delete();
        GiftVoucher::query()->delete();
        $ls->keyId = 7002;
        $ls->instances = [['identifier' => 'inst-x', 'name' => 'family:999999']];
        gvRedeem()->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        Queue::assertPushed(SendGiftVoucherAlert::class, fn (SendGiftVoucherAlert $j) => $j->reason === SendGiftVoucherAlert::REASON_INSTANCE_MISMATCH);

        // Admin API down → 502.
        $ls->instancesFails = 500;
        gvRedeem()->assertStatus(502)->assertJsonPath('error', 'upstream_error');
    });

    it('S17: activated outside PetPrep (usage 1/1, no claim) → 409 + alert', function () {
        Queue::fake([SendGiftVoucherAlert::class]);
        $ls = gvLs();
        gvParent();
        $ls->usage = 1;
        $ls->status = 'active';

        gvRedeem()->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        Queue::assertPushed(SendGiftVoucherAlert::class, fn (SendGiftVoucherAlert $j) => $j->reason === SendGiftVoucherAlert::REASON_ACTIVATED_ELSEWHERE
            && $j->voucherId === GiftVoucher::sole()->id && $j->lsOrderId === 8001);
        expect(ChallengeCredit::count())->toBe(0);
    });

    it('S18: a fresh claim by another family blocks (409); a stale one (> 5 min, LS usage 0) does not', function () {
        $ls = gvLs();
        gvParent();
        [, , $other] = gvParent('other@example.com');
        gvWebhook(gvLicenseKeyEvent('license_key_created'));
        GiftVoucher::query()->update(['claim_family_id' => $other->id, 'claimed_at' => now()->subMinutes(2)]);

        gvRedeem()->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        expect($ls->count('activate'))->toBe(0);

        GiftVoucher::query()->update(['claimed_at' => now()->subMinutes(6)]);
        gvRedeem()->assertOk()->assertJsonPath('already', false);
    });

    it('S19: a refund webhook between activate and commit → refunded, no credit', function () {
        $ls = gvLs();
        gvParent();
        // The refund lands while LS is activating (the webhook runs in another request).
        $ls->beforeActivate = fn () => GiftVoucher::query()->update(['status' => 'refunded', 'refunded_at' => now()]);
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'refunded');
        expect(ChallengeCredit::count())->toBe(0)->and(GiftVoucher::sole()->status)->toBe('refunded');
    });

    it('S20: the plaintext key is stored nowhere and the ledger keeps no key / buyer fields', function () {
        gvLs();
        gvParent();
        gvWebhook(gvLicenseKeyEvent('license_key_created'))->assertOk();
        gvWebhook(gvOrderEvent('order_created'))->assertOk();
        gvRedeem()->assertOk();

        $variants = [GV_CODE, strtoupper(GV_CODE), str_replace('-', '', GV_CODE), 'buyer@example.com', 'Buyer Person'];
        foreach (['gift_vouchers', 'lemonsqueezy_events', 'challenge_credits'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            foreach ($variants as $v) {
                expect(str_contains(strtolower($dump), strtolower($v)))->toBeFalse("{$table} contains {$v}");
            }
        }
        foreach (LemonSqueezyEvent::all() as $event) {
            expect($event->payload)->not->toHaveKeys(['key'])
                ->and(array_intersect(array_keys($event->payload), ['key', 'key_short', 'user_email', 'user_name', 'customer_email', 'custom_data', 'urls']))->toBe([]);
        }
        expect(GiftVoucher::sole()->key_hash)->toBe(hash('sha256', GV_CODE));
    });

    it('S21: the 11th request per minute from one IP is 429 rate_limited with Retry-After', function () {
        gvLs();
        gvParent();
        gvSwitch(false); // cheap answers; the limiter runs first
        for ($i = 0; $i < 10; $i++) {
            gvRedeem('code-'.$i.'-abcdefgh', ip: '198.51.100.7')->assertStatus(503);
        }
        gvRedeem('code-x-abcdefgh', ip: '198.51.100.7')->assertStatus(429)
            ->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
        gvRedeem('code-y-abcdefgh', ip: '198.51.100.8')->assertStatus(503);
    });

    it('S21: the 11th request per hour on one code from varying IPs is 429', function () {
        gvLs();
        gvParent();
        gvSwitch(false);
        for ($i = 0; $i < 10; $i++) {
            gvRedeem(GV_CODE, ip: '192.0.2.'.($i + 1))->assertStatus(503);
        }
        // Same code, other spacing / case → the same canonical code.
        gvRedeem(strtoupper(GV_CODE).' ', ip: '192.0.2.200')->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        gvRedeem('another-code-1234', ip: '192.0.2.201')->assertStatus(503);
    });

    it('S22: malformed input (with a valid web token) is 422 invalid_request without echoing fields', function (array $body) {
        gvLs();
        [$parent] = gvParent();
        app('auth')->forgetGuards();
        $res = test()->withHeaders(['Authorization' => 'Bearer '.gvWebToken($parent)])
            ->postJson('/api/gift/redeem', $body)->assertStatus(422)->assertJsonPath('error', 'invalid_request');
        expect(array_keys($res->json()))->toBe(['error', 'message']);
    })->with([
        'no code' => [[]],
        'short code' => [['code' => 'abc']],
        'bad chars' => [['code' => 'abcd<script>efgh']],
        'unknown locale' => [['code' => GV_CODE, 'locale' => 'xx']],
        'array code' => [['code' => ['x']]],
    ]);

    it('answers `message` in Slovenian when locale = sl; the other website languages are accepted and answered in English', function () {
        gvLs();
        gvParent();
        gvSwitch(false);
        gvRedeem(extra: ['locale' => 'sl'])->assertStatus(503)->assertJsonPath('message', 'Unovčitev bonov trenutno ni na voljo.');
        foreach (WebsiteLocale::ALL as $locale) {
            if ($locale !== 'sl') {
                gvRedeem('locale-code-'.$locale, extra: ['locale' => $locale], ip: '203.0.113.'.(50 + array_search($locale, WebsiteLocale::ALL, true)))
                    ->assertStatus(503)->assertJsonPath('message', 'Voucher redemption is not available right now.');
            }
        }
    });

    it('GR6: a body with an e-mail and no token is 401 — the e-mail is never validated or logged', function () {
        gvLs();
        gvParent();
        Log::spy();
        gvRedeem(as: null, extra: ['email' => 'parent@example.com'])->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        // With a token the e-mail field is ignored (even a malformed one).
        gvRedeem(extra: ['email' => 'not-an-email'])->assertOk();
        Log::shouldNotHaveReceived('info', fn (string $m, array $c = []) => str_contains(json_encode($c) ?: '', 'parent@example.com') || str_contains(json_encode($c) ?: '', 'not-an-email'));
        Log::shouldNotHaveReceived('warning', fn (string $m, array $c = []) => str_contains(json_encode($c) ?: '', '@example.com'));
    });

    it('GR7: the no_account code is gone (result map and lang files)', function () {
        expect(GiftRedeemResult::HTTP)->not->toHaveKey('no_account')
            ->and(GiftRedeemResult::HTTP['unauthenticated'])->toBe(401)
            ->and(GiftRedeemResult::HTTP['not_parent'])->toBe(403);
        foreach (['en', 'sl'] as $lang) {
            expect(require lang_path("{$lang}/gift.php"))->not->toHaveKey('no_account')->toHaveKeys(['unauthenticated', 'not_parent']);
        }
    });

    it('GR3: the family deleted between claim and commit → 401 unauthenticated, no credit', function () {
        $ls = gvLs();
        [$parent, $child, $family] = gvParent();
        $ls->beforeActivate = function () use ($family) {
            // Remove the family entirely (members first — RESTRICT FKs).
            DB::table('family_user')->where('family_id', $family->id)->delete();
            DB::table('quiet_hours')->where('family_id', $family->id)->delete();
            DB::table('gift_vouchers')->where('claim_family_id', $family->id)->update(['claim_family_id' => null]);
            DB::table('families')->where('id', $family->id)->delete();
        };

        gvRedeem(as: $parent)->assertStatus(401)->assertJsonPath('error', 'unauthenticated');
        expect(ChallengeCredit::count())->toBe(0)->and(GiftVoucher::sole()->status)->toBe(GiftVoucher::STATUS_PURCHASED);
    });

    it('GR4: idempotency follows the token\'s family — same family already:true, another family\'s token 409', function () {
        gvLs();
        [$parent, , $family] = gvParent();
        $second = User::factory()->parent()->create();
        DB::table('family_user')->where('user_id', $second->id)->delete();
        app(FamilyService::class)->addMember($family, $second, FamilyRole::Parent);
        [$stranger] = gvParent('stranger@example.com');

        gvRedeem(as: $parent)->assertOk()->assertJsonPath('already', false);
        gvRedeem(as: $second)->assertOk()->assertExactJson(['status' => 'redeemed', 'already' => true]);
        gvRedeem(as: $stranger)->assertStatus(409)->assertJsonPath('error', 'already_redeemed');
        expect(ChallengeCredit::count())->toBe(1);
    });

    it('GR5: the 21st redeem in an hour for one family (different codes and IPs) is 429', function () {
        gvLs();
        [$parent] = gvParent();
        gvSwitch(false);
        for ($i = 0; $i < 20; $i++) {
            gvRedeem('family-code-'.$i.'-abc', as: $parent, ip: '198.18.0.'.($i + 1))->assertStatus(503);
        }
        gvRedeem('family-code-x-abc', as: $parent, ip: '198.18.1.1')->assertStatus(429)->assertJsonPath('error', 'rate_limited');
        [$other] = gvParent('other@example.com');
        gvRedeem('family-code-y-abc', as: $other, ip: '198.18.1.2')->assertStatus(503);
    });

    it('never calls Lemon Squeezy inside a database transaction', function () {
        gvLs();
        [, , $family] = gvParent();
        expect(fn () => DB::transaction(fn () => app(GiftVoucherRedeemService::class)->redeem(GV_CODE, $family)))
            ->toThrow(LogicException::class, 'must not be called inside a database transaction');
    });
});

/* ───────────────────────────── Credit behaviour ───────────────────────────── */

describe('a gift credit behaves like a store credit', function () {
    it('S23: activate assigns it to a payment-required pet — purchase, full media, deletion warning', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        expect($pet->fresh()->challenge_paid_at)->toBeNull(); // never auto-assigned at redeem (D9)

        gvActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'activated');
        $pet->refresh();
        expect($pet->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and(app(MediaEntitlementService::class)->tierFor($pet))->toBe('full')
            ->and(ChallengeCredit::sole()->pet_id)->toBe($pet->id)
            ->and(gvBilling($parent)['pets'][0]['deletion_loses_purchase'])->toBeTrue();

        app('auth')->forgetGuards();
        actingAsRole($parent);
        test()->deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true])
            ->assertStatus(422)->assertJsonPath('reason', 'paid_challenge_ack_required');
    });

    it('S24: activate upgrades a free mutt with a gift credit (M6-U01 path)', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvFreeMutt($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk()->assertJsonPath('status', 'activated');
        expect($pet->fresh())->plan->toBe(PetPlan::Challenge)->challenge_started_at->not->toBeNull()
            ->challenge_paid_source->toBe(ChallengePaidSource::Purchase);
    });

    it('S25: a held gift credit pays the only unborn challenge pet at birth', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = Pet::factory()->borderCollie()->trial()->unborn()->create(['user_id' => $child->id]);
        gvRedeem()->assertOk();
        expect(app(ChallengeCreditService::class)->assignAvailableBeforeBirth($pet))->toBeTrue();
        expect($pet->fresh()->challenge_paid_source)->toBe(ChallengePaidSource::Purchase)
            ->and(ChallengeCredit::sole()->assigned_via)->toBe(ChallengeCredit::VIA_BIRTH);
    });

    it('S26: billing counts the gift credit and keeps exactly the current keys', function () {
        gvLs();
        [$parent, $child] = gvParent();
        gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        $billing = gvBilling($parent);
        expect($billing['credits_available'])->toBe(1)
            ->and(array_keys($billing))->toBe(['credits_available', 'payments_enforced', 'pets'])
            ->and(array_keys($billing['pets'][0]))->toBe(['pet_id', 'plan', 'status', 'trial_ends_at', 'paid_at', 'trial_available', 'deletion_loses_purchase', 'challenge_started_at', 'upgrade']);
    });
});

/* ───────────────────────────── Refund / disabled webhooks ───────────────────────────── */

describe('refund and disabled webhooks', function () {
    it('S27a: an unredeemed voucher becomes refunded', function () {
        gvLs();
        gvWebhook(gvLicenseKeyEvent('license_key_created'));
        gvRefund()->assertJsonPath('outcome', 'refunded');
        expect(GiftVoucher::sole())->status->toBe('refunded')->refunded_at->not->toBeNull();
        // Repeat with another updated_at: terminal → recorded.
        gvRefund(updatedAt: '2026-11-13T09:00:00.000000Z')->assertJsonPath('outcome', 'recorded');
    });

    it('S27b: redeemed + unassigned → credit revoked, credits_available drops', function () {
        gvLs();
        [$parent] = gvParent();
        gvRedeem()->assertOk();
        expect(gvBilling($parent)['credits_available'])->toBe(1);

        gvRefund()->assertJsonPath('outcome', 'revoked');
        $credit = ChallengeCredit::sole();
        expect($credit->revoked_at)->not->toBeNull()->and($credit->revoke_reason->value)->toBe('refund')
            ->and($credit->revoke_event_id)->toBe(LemonSqueezyEvent::where('event_name', 'order_refunded')->sole()->idempotency_key)
            ->and(GiftVoucher::sole()->status)->toBe('refunded')
            ->and(gvBilling($parent)['credits_available'])->toBe(0);
        // A later redeem answers refunded.
        gvRedeem()->assertStatus(422)->assertJsonPath('error', 'refunded');
    });

    it('S27c: assigned premium pet, unfinished → locked (payment_required) + lock push', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendPushNotification::class, SendGiftRedeemedPush::class]);
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk();

        gvRefund()->assertJsonPath('outcome', 'revoked');
        $pet->refresh();
        expect($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            ->and($pet->isPaymentLocked())->toBeTrue()
            ->and(PushNotification::where('type', 'payment_required')->count())->toBe(1);
    });

    it('S27d: an upgraded free mutt goes back to the free plan', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvFreeMutt($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk();

        gvRefund()->assertJsonPath('outcome', 'revoked');
        expect($pet->fresh())->plan->toBe(PetPlan::Free)->challenge_paid_at->toBeNull()->challenge_started_at->toBeNull();
    });

    it('S27e: a finished challenge keeps the pet; only the credit is revoked', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk();

        Carbon::setTestNow(Carbon::parse(GV_NOW, 'UTC')->addWeeks(13));
        gvRefund()->assertJsonPath('outcome', 'revoked');
        expect($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::Paid)
            ->and(ChallengeCredit::sole()->revoked_at)->not->toBeNull();
    });

    it('S27f: the pet was deleted → the credit is revoked', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk();
        app('auth')->forgetGuards();
        actingAsRole($parent);
        test()->deleteJson("/api/parent/children/{$child->id}", ['password' => 'password', 'confirm' => true, 'acknowledge_paid_challenge' => true])->assertOk();
        expect(ChallengeCredit::sole()->pet_id)->toBeNull();

        gvRefund()->assertJsonPath('outcome', 'revoked');
        expect(ChallengeCredit::sole()->revoked_at)->not->toBeNull();
    });

    it('S27g: a partial refund revokes nothing and alerts an admin', function () {
        Queue::fake([SendGiftVoucherAlert::class, SendGiftRedeemedPush::class]);
        gvLs();
        gvParent();
        gvRedeem()->assertOk();

        gvRefund(full: false)->assertJsonPath('outcome', 'partial_refund');
        expect(ChallengeCredit::sole()->revoked_at)->toBeNull()->and(GiftVoucher::sole()->status)->toBe('redeemed');
        Queue::assertPushed(SendGiftVoucherAlert::class, fn (SendGiftVoucherAlert $j) => $j->reason === 'partial_refund' && $j->lsOrderId === 8001);
    });

    it('S27h: license_key_updated disabled → like a refund, status disabled', function () {
        gvLs();
        [$parent, $child] = gvParent();
        $pet = gvPaymentRequiredPet($child);
        gvRedeem()->assertOk();
        gvActivate($parent, $pet)->assertOk();

        gvWebhook(gvLicenseKeyEvent('license_key_updated', ['status' => 'disabled', 'disabled' => true, 'updated_at' => '2026-11-12T09:00:00.000000Z']))
            ->assertJsonPath('outcome', 'disabled');
        expect(GiftVoucher::sole())->status->toBe('disabled')->disabled_at->not->toBeNull()
            ->and(ChallengeCredit::sole()->revoked_at)->not->toBeNull()
            ->and($pet->fresh()->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);

        // Unredeemed + disabled; a re-enabled key does not revive it.
        GiftVoucher::query()->update(['ls_license_key_id' => 1]); // move the first one aside
        gvWebhook(gvLicenseKeyEvent('license_key_created', ['key' => 'aaaabbbb-cccc', 'updated_at' => '2026-11-12T10:00:00Z']));
        gvWebhook(gvLicenseKeyEvent('license_key_updated', ['key' => 'aaaabbbb-cccc', 'disabled' => true, 'updated_at' => '2026-11-12T11:00:00Z']))->assertJsonPath('outcome', 'disabled');
        gvWebhook(gvLicenseKeyEvent('license_key_updated', ['key' => 'aaaabbbb-cccc', 'status' => 'active', 'disabled' => false, 'updated_at' => '2026-11-12T12:00:00Z']))->assertJsonPath('outcome', 'voucher_updated');
        expect(GiftVoucher::where('ls_license_key_id', 7001)->sole()->status)->toBe('disabled');
    });

    it('license_key_updated expired moves a purchased voucher to expired', function () {
        gvLs();
        gvWebhook(gvLicenseKeyEvent('license_key_created'));
        gvWebhook(gvLicenseKeyEvent('license_key_updated', ['status' => 'expired', 'updated_at' => '2026-11-12T09:00:00Z']))->assertJsonPath('outcome', 'expired');
        expect(GiftVoucher::sole()->status)->toBe('expired');
    });
});

/* ───────────────────────────── Webhook ───────────────────────────── */

describe('webhook', function () {
    it('S28: signature — valid 200, wrong / missing 401, re-encoded JSON 401, no secret / store / product 503', function () {
        gvLs();
        $body = gvOrderEvent('order_created');
        $raw = json_encode($body);
        gvWebhook($raw)->assertOk()->assertJson(['received' => true, 'duplicate' => false, 'outcome' => 'recorded']);
        gvWebhook($raw, 'deadbeef')->assertStatus(401);
        gvWebhook($raw, sign: false)->assertStatus(401);
        gvWebhook($raw, strtoupper(hash_hmac('sha256', $raw, GV_SECRET)))->assertOk(); // uppercase hex accepted
        // Signature of the same JSON with other whitespace → 401.
        $pretty = json_encode($body, JSON_PRETTY_PRINT);
        gvWebhook($pretty, hash_hmac('sha256', $raw, GV_SECRET))->assertStatus(401);

        config(['services.lemonsqueezy.webhook_secret' => '']);
        gvWebhook($raw, 'x')->assertStatus(503)->assertExactJson(['message' => 'Webhook not configured.']);
        config(['services.lemonsqueezy.webhook_secret' => GV_SECRET, 'services.lemonsqueezy.store_id' => null]);
        gvWebhook($raw)->assertStatus(503);
        config(['services.lemonsqueezy.store_id' => (string) GV_STORE, 'services.lemonsqueezy.gift_product_id' => '']);
        gvWebhook($raw)->assertStatus(503);
    });

    it('S29: idempotent — the same body twice → one row, duplicate:true; a concurrent duplicate is caught', function () {
        gvLs();
        $body = gvLicenseKeyEvent('license_key_created');
        gvWebhook($body)->assertJsonPath('outcome', 'voucher_created')->assertJsonPath('duplicate', false);
        gvWebhook($body)->assertExactJson(['received' => true, 'duplicate' => true, 'outcome' => null]);
        expect(LemonSqueezyEvent::count())->toBe(1)->and(GiftVoucher::count())->toBe(1);

        // Concurrent delivery: another request commits the same key between our exists-check
        // and our insert. Simulated on one connection: the conflicting row makes our insert hit
        // the unique index for real; after our rollback the other request's row is (re)placed.
        $order = gvOrderEvent('order_created');
        $raw = json_encode($order);
        $key = LemonSqueezyWebhookService::idempotencyKey('order_created', $order['data'], $order['data']['attributes'], $raw);
        $other = fn () => DB::table('lemonsqueezy_events')->insert(['idempotency_key' => $key, 'event_name' => 'order_created', 'payload' => '{}', 'test_mode' => false, 'created_at' => now(), 'updated_at' => now()]);
        $armed = true;
        LemonSqueezyEvent::creating(function (LemonSqueezyEvent $e) use ($key, $other, &$armed) {
            if ($armed && $e->idempotency_key === $key) {
                $other();
            }
        });
        Event::listen(TransactionRolledBack::class, function () use ($other, &$armed) {
            if ($armed) {
                $armed = false;
                $other();
            }
        });
        gvWebhook($raw)->assertOk()->assertJsonPath('duplicate', true);
        expect(LemonSqueezyEvent::where('idempotency_key', $key)->count())->toBe(1);
    });

    it('S29: foreign store, other product / variant, unknown event, test mode, unparseable', function () {
        gvLs();
        gvWebhook(gvOrderEvent('order_created', ['store_id' => 1]))->assertJsonPath('outcome', 'foreign_store');
        gvWebhook(gvOrderEvent('order_created', ['first_order_item' => ['product_id' => 1, 'variant_id' => GV_VARIANT], 'updated_at' => '2026-11-02T00:00:00Z']))->assertJsonPath('outcome', 'unknown_product');
        gvWebhook(gvOrderEvent('order_created', ['first_order_item' => ['product_id' => GV_PRODUCT, 'variant_id' => 1], 'updated_at' => '2026-11-03T00:00:00Z']))->assertJsonPath('outcome', 'unknown_product');
        gvWebhook(gvLicenseKeyEvent('license_key_created', ['product_id' => 1]))->assertJsonPath('outcome', 'unknown_product');
        gvWebhook(gvOrderEvent('subscription_created', ['updated_at' => '2026-11-04T00:00:00Z']))->assertJsonPath('outcome', 'ignored');
        gvWebhook(gvLicenseKeyEvent('license_key_created', ['test_mode' => true, 'updated_at' => '2026-11-05T00:00:00Z'], ['test_mode' => true]))->assertJsonPath('outcome', 'test_mode_ignored');
        expect(GiftVoucher::count())->toBe(0);

        $before = LemonSqueezyEvent::count();
        gvWebhook('not json')->assertOk()->assertExactJson(['received' => true, 'duplicate' => false, 'outcome' => 'ignored']);
        gvWebhook(['meta' => []])->assertOk()->assertJsonPath('outcome', 'ignored');
        expect(LemonSqueezyEvent::count())->toBe($before);
    });

    it('S29: processes refunds with the »Gift vouchers« switch OFF', function () {
        gvLs();
        gvParent();
        gvRedeem()->assertOk();
        gvSwitch(false);
        gvRefund()->assertJsonPath('outcome', 'revoked');
        expect(ChallengeCredit::sole()->revoked_at)->not->toBeNull();
    });

    it('uses the raw body hash as the key when updated_at is missing', function () {
        gvLs();
        $body = gvOrderEvent('order_created');
        unset($body['data']['attributes']['updated_at']);
        gvWebhook($body)->assertJsonPath('outcome', 'recorded');
        expect(LemonSqueezyEvent::sole()->idempotency_key)->toBe(hash('sha256', json_encode($body)));
    });
});

/* ───────────────────────────── Infra ───────────────────────────── */

describe('infra', function () {
    it('S30: CORS preflight from petprep.si (Authorization header) on the redeem + web sign-in routes', function (string $origin, string $path, string $method) {
        $res = test()->call('OPTIONS', $path, [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ])->assertHeader('Access-Control-Allow-Origin')
            ->assertHeader('Access-Control-Allow-Methods');
        expect(strtolower((string) $res->headers->get('Access-Control-Allow-Headers')))->toContain('authorization')
            ->and($res->headers->get('Access-Control-Allow-Credentials'))->not->toBe('true');
    })->with(['https://petprep.si', 'https://www.petprep.si', 'http://localhost:3000'])
        ->with([['/api/gift/redeem', 'POST'], ['/api/web/parent-session', 'POST'], ['/api/web/parent-session', 'DELETE']]);

    it('S32: one Expo message per active parent token, no names, never says voucher; once only; children get nothing', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendGiftRedeemedPush::class]);
        gvLs();
        [$parent, $child, $family] = gvParent();
        $parent->forceFill(['name' => 'Ana Novak'])->save();
        $second = User::factory()->parent()->create();
        DB::table('family_user')->where('user_id', $second->id)->delete();
        app(FamilyService::class)->addMember($family, $second, FamilyRole::Parent);
        $token = fn (User $u, string $t, ?string $locale, bool $disabled = false) => DB::table('device_push_tokens')->insert([
            'user_id' => $u->id, 'expo_push_token' => $t, 'platform' => 'ios', 'locale' => $locale,
            'disabled_at' => $disabled ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token($parent, 'ExponentPushToken[p1]', 'sl');
        $token($second, 'ExponentPushToken[p2]', 'en');
        $token($second, 'ExponentPushToken[p3-off]', 'en', true);
        $token($child, 'ExponentPushToken[c1]', 'sl');

        gvRedeem()->assertOk();
        $voucherId = GiftVoucher::sole()->id;
        (new SendGiftRedeemedPush($voucherId))->handle(app(ExpoPushClient::class));
        (new SendGiftRedeemedPush($voucherId))->handle(app(ExpoPushClient::class)); // replay: nothing

        $sent = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'exp.host'))->values();
        expect($sent)->toHaveCount(1);
        $messages = $sent[0][0]->data();
        expect(array_column($messages, 'to'))->toBe(['ExponentPushToken[p1]', 'ExponentPushToken[p2]']);
        foreach ($messages as $m) {
            expect($m['data'])->toBe(['type' => 'challenge_credit_added'])->and($m['title'])->toBe('PetPrep');
            $text = mb_strtolower($m['title'].' '.$m['body']);
            foreach (['bon', 'voucher', 'gift', 'koda', 'code', 'splet', 'web', 'ana', 'novak'] as $word) {
                expect(str_contains($text, $word))->toBeFalse("push mentions {$word}");
            }
        }
        expect($messages[0]['body'])->toBe(SendGiftRedeemedPush::BODY['sl'])
            ->and($messages[1]['body'])->toBe(SendGiftRedeemedPush::BODY['en'])
            ->and(GiftVoucher::sole()->push_sent_at)->not->toBeNull()
            ->and(PushNotification::count())->toBe(0);
    });

    it('S32: a failed Expo send releases the claim so a retry can send', function () {
        config(['push.enabled' => true]);
        Queue::fake([SendGiftRedeemedPush::class]);
        $ls = gvLs();
        [$parent] = gvParent();
        DB::table('device_push_tokens')->insert(['user_id' => $parent->id, 'expo_push_token' => 'ExponentPushToken[p1]', 'platform' => 'ios', 'created_at' => now(), 'updated_at' => now()]);
        gvRedeem()->assertOk();
        $ls->expoStatus = 503;
        expect(fn () => (new SendGiftRedeemedPush(GiftVoucher::sole()->id))->handle(app(ExpoPushClient::class)))->toThrow(Exception::class);
        expect(GiftVoucher::sole()->push_sent_at)->toBeNull();
    });

    it('S33: GiftVoucherResource — superadmin list without e-mails, 403 for others, read only', function () {
        gvLs();
        gvParent();
        gvRedeem()->assertOk();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);

        app('auth')->forgetGuards();
        $this->actingAs($admin)->get('/admin/gift-vouchers')->assertOk()
            ->assertSee('…4d51')->assertSee('8001')->assertSee('redeemed')
            ->assertDontSee('parent@example.com')->assertDontSee('buyer@example.com')->assertDontSee(GV_CODE);
        $this->get('/admin/gift-vouchers/create')->assertNotFound();

        expect(GiftVoucherResource::canCreate())->toBeFalse()
            ->and(GiftVoucherResource::canEdit(GiftVoucher::sole()))->toBeFalse()
            ->and(GiftVoucherResource::canDelete(GiftVoucher::sole()))->toBeFalse();

        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->parent()->create())->get('/admin/gift-vouchers')->assertForbidden();
    });

    it('S33: the Feature switch toggles »Gift vouchers« and is audited (default off)', function () {
        AppSetting::query()->whereKey(AppSettingsService::GIFT_VOUCHERS_KEY)->delete();
        cache()->flush();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertSee('Gift vouchers (website redemption)')
            ->assertFormSet(['gift_vouchers' => false])
            ->fillForm(['gift_vouchers' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(AppSettingsService::class)->giftVouchers(fresh: true))->toBeTrue();
        $change = AppSettingChange::query()->where('key', AppSettingsService::GIFT_VOUCHERS_KEY)->sole();
        expect($change->user_id)->toBe($admin->id)->and($change->new)->toBe(['enabled' => true]);
    });

    it('S33: Funnel »Paid with voucher« counts a production gift-paid family, not a store-paid one', function () {
        gvLs();
        [$giftParent, $giftChild] = gvParent();
        disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $giftChild->id]));
        gvRedeem()->assertOk();

        [, $storeChild, $storeFamily] = gvParent('store@example.com');
        disableHygieneEvents(Pet::factory()->mutt()->create(['user_id' => $storeChild->id]));
        $eventId = DB::table('purchase_events')->insertGetId(['event_id' => (string) Str::uuid(), 'type' => 'NON_RENEWING_PURCHASE', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('challenge_credits')->insert(['family_id' => $storeFamily->id, 'purchase_event_id' => $eventId, 'product_id' => 'petprep_challenge_12w', 'environment' => 'PRODUCTION', 'purchased_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $total = app(FunnelService::class)->cohorts(4)['total'];
        expect($total['paid'])->toBe(2)->and($total['paid_voucher'])->toBe(1)->and($total['paid_test'])->toBe(0);

        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        app('auth')->forgetGuards();
        $this->actingAs($admin)->get('/admin/funnel')->assertOk()->assertSee('Paid with voucher');
        $this->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin/funnel')->assertOk()->assertSee('Plačali z bonom');
    });

    it('the alert job posts ids only to Discord', function () {
        Http::fake(['discord.com/api/webhooks/*' => Http::response('', 204)]);
        (new SendGiftVoucherAlert(SendGiftVoucherAlert::REASON_PARTIAL_REFUND, 12, 8001))->handle(app(DiscordWebhook::class));
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_SLASHES), '8001')
            && str_contains(json_encode($r->data(), JSON_UNESCAPED_SLASHES), '/admin/gift-vouchers'));
    });
});
