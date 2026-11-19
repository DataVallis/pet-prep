<?php

use App\Filament\Resources\OutboundEmailResource\Pages\ListOutboundEmails;
use App\Filament\Resources\ShopOrderResource\Pages\ListShopOrders;
use App\Filament\Resources\ShopOrderResource\Pages\ViewShopOrder;
use App\Filament\Resources\ShopProductResource\Pages\ListShopProducts;
use App\Models\EmailContact;
use App\Models\EmailSignup;
use App\Models\OutboundEmail;
use App\Models\ShopAuditLog;
use App\Models\ShopOrder;
use App\Models\ShopProduct;
use App\Models\User;
use App\Services\Shop\ShopAdminService;
use App\Services\Shop\ShopFiles;
use App\Support\WebsiteLocale;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-S09 (WEBSITE_SALES_SPEC §3.2, §3.4, §3.7, §4.2): the website shop — Stripe
| checkout, the thank-you page's order lookup, file downloads, the free sample,
| the admin shop and the e-mail texts in all 15 website languages.
|--------------------------------------------------------------------------
*/

require_once __DIR__.'/../Support/ShopFakes.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(SH_NOW, 'UTC'));
    shConfig();
    File::deleteDirectory(storage_path('framework/testing/shop'));
    gvSwitch(true);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/shop'));
    Carbon::setTestNow();
});

function shPdf(string $product, string $locale): void
{
    ShopFiles::disk()->put(ShopFiles::path($product, $locale), "%PDF-1.7\n".$product.' '.$locale);
}

function shCheckout(array $body = []): TestResponse
{
    return postJson('/api/shop/checkout', array_merge([
        'product' => 'gift_voucher', 'locale' => 'sl', 'success_path' => '/sl/darilni-bon/hvala', 'cancel_path' => '/sl/darilni-bon',
    ], $body));
}

describe('checkout', function () {
    it('C1: creates a Managed Payments Checkout Session for one product with PetPrep branding and our return URLs', function () {
        $stripe = shStripe();

        $url = shCheckout()->assertOk()->json('url');
        expect($url)->toStartWith('https://checkout.stripe.com/');
        $body = $stripe->created[0];
        expect($body['mode'])->toBe('payment')
            ->and($body['managed_payments']['enabled'])->toBe('true')
            ->and($body['line_items'][0])->toBe(['price' => SH_PRICE_GIFT, 'quantity' => 1])
            ->and($body['success_url'])->toBe('https://petprep.si/sl/darilni-bon/hvala?session_id={CHECKOUT_SESSION_ID}')
            ->and($body['cancel_url'])->toBe('https://petprep.si/sl/darilni-bon')
            ->and($body['locale'])->toBe('sl')
            ->and($body['metadata'])->toBe(['petprep_product' => 'gift_voucher', 'locale' => 'sl'])
            ->and($body['branding_settings']['display_name'])->toBe('PetPrep')
            ->and($body['branding_settings']['logo']['type'])->toBe('url');
    });

    it('C2: product switch off / Stripe not configured → 503; Stripe down → 502', function () {
        $stripe = shStripe();
        shCheckout(['product' => 'promise_kit'])->assertStatus(503)->assertJsonPath('error', 'unavailable');
        shKitSwitch(true);
        shCheckout(['product' => 'promise_kit'])->assertOk();

        gvSwitch(false);
        shCheckout()->assertStatus(503);
        gvSwitch(true);

        $stripe->down = true;
        shCheckout()->assertStatus(502)->assertJsonPath('error', 'upstream_error');

        config(['services.stripe.secret_key' => '']);
        shCheckout()->assertStatus(503);
    });

    it('C3: only a petprep.si path is accepted (no open redirect), and only known products and languages', function () {
        shStripe();
        foreach (['https://evil.example/x', '//evil.example', '/sl/darilni-bon?x=1', 'sl/hvala', '/sl/../x', '/SL'] as $bad) {
            shCheckout(['success_path' => $bad])->assertStatus(422)->assertExactJson(['error' => 'invalid_request']);
        }
        shCheckout(['product' => 'puppy'])->assertStatus(422);
        shCheckout(['locale' => 'ru'])->assertStatus(422);
    });
});

describe('thank-you page', function () {
    it('O1: a paid session is fulfilled on the page when the webhook is late, and answers the code + masked e-mail', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('p', 24);
        $stripe->sessions[$id] = shSession($id);

        $res = getJson('/api/shop/orders/'.$id)->assertOk();
        expect($res->json('status'))->toBe('paid')
            ->and($res->json('product'))->toBe('gift_voucher')
            ->and($res->json('voucher.code'))->toMatch('/^PP-/')
            ->and($res->json('voucher.expires_at'))->toStartWith('2027-11-17')
            ->and($res->json('email_masked'))->toBe('bu***@example.com')
            ->and($res->json('order_number'))->toStartWith('PP-2026-');

        // Again later: the same code, no second voucher.
        expect(getJson('/api/shop/orders/'.$id)->json('voucher.code'))->toBe($res->json('voucher.code'));
    });

    it('O2: unpaid → 202 pending; unknown session → 404; after 30 days or a refund no code any more', function () {
        $stripe = shStripe();
        $id = 'cs_test_'.str_repeat('q', 24);
        $stripe->sessions[$id] = shSession($id, paymentStatus: 'unpaid');
        getJson('/api/shop/orders/'.$id)->assertStatus(202)->assertExactJson(['status' => 'pending']);
        getJson('/api/shop/orders/cs_test_doesnotexist000')->assertStatus(404);

        $stripe->pay($id);
        expect(getJson('/api/shop/orders/'.$id)->json('voucher.code'))->not->toBeNull();

        Carbon::setTestNow(Carbon::parse(SH_NOW, 'UTC')->addDays(31));
        $late = getJson('/api/shop/orders/'.$id)->assertOk();
        expect($late->json('voucher'))->toBeNull()->and($late->json('status'))->toBe('paid');
    });

    it('O3: a promise kit order lists signed links of the uploaded PDFs, the buyer\'s language first; the link downloads; a refund stops it', function () {
        $stripe = shStripe();
        shPdf('promise_kit', 'en');
        shPdf('promise_kit', 'de');
        $id = 'cs_test_'.str_repeat('k', 24);
        $stripe->sessions[$id] = shSession($id, product: 'promise_kit', locale: 'de');

        $links = getJson('/api/shop/orders/'.$id)->assertOk()->json('downloads');
        expect(array_column($links, 'locale'))->toBe(['de', 'en']);
        $this->get($links[0]['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=x', $links[0]['url']))->assertStatus(403);

        $order = ShopOrder::sole();
        shWebhook(shEvent('charge.refunded', ['id' => 'ch_k', 'payment_intent' => $order->stripe_payment_intent_id, 'amount' => 690, 'amount_refunded' => 690, 'refunded' => true]))->assertOk();
        $this->get($links[0]['url'])->assertStatus(404);
    });

    it('O4: the gift order also offers the promise kit PDFs', function () {
        $stripe = shStripe();
        shPdf('promise_kit', 'sl');
        [$order] = shPaidOrder($stripe);
        $links = getJson('/api/shop/orders/'.$order->stripe_checkout_session_id)->json('downloads');
        expect(array_column($links, 'locale'))->toBe(['sl']);
    });
});

describe('free sample', function () {
    it('S1: summary-leads with source promise_kit_sample answers a signed sample link (English fallback, null without a file)', function () {
        $lead = fn (string $locale) => postJson('/api/summary-leads', [
            'email' => 'parent@example.com', 'consent' => true, 'consent_version' => EmailSignup::LEGACY_CONSENT_VERSIONS[0], 'source' => 'promise_kit_sample', 'locale' => $locale,
        ]);
        expect($lead('fr')->assertCreated()->json('sample_url'))->toBeNull();

        shPdf(ShopFiles::SAMPLE, 'en');
        $url = $lead('fr')->assertCreated()->json('sample_url');
        expect($url)->toContain('/api/shop/sample/en');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // M6-S09 unified e-mail store: one contact, one `newsletter` signup per request, source kept.
        $contact = EmailContact::sole();
        expect($contact->email)->toBe('parent@example.com')->and($contact->signups_count)->toBe(2)
            ->and($contact->signups->pluck('source')->unique()->all())->toBe(['promise_kit_sample'])
            ->and($contact->signups->pluck('purpose')->unique()->all())->toBe(['newsletter']);
    });
});

describe('admin', function () {
    it('A1: revealing a code and re-sending the e-mail are logged; changing the address cancels open e-mails and queues a new one', function () {
        $stripe = shStripe();
        [$order, $code] = shPaidOrder($stripe);
        $admin = User::factory()->parent()->create(['is_superadmin' => true]);
        $service = app(ShopAdminService::class);

        expect($service->revealCode($admin, $order->voucher))->toBe($code);
        $service->resend($admin, $order);
        expect(OutboundEmail::where('shop_order_id', $order->id)->count())->toBe(2);

        $service->changeEmailAndResend($admin, $order->fresh(), 'New@Example.com');
        expect($order->fresh()->customer_email)->toBe('new@example.com')
            ->and(OutboundEmail::where('status', 'cancelled')->count())->toBe(2)
            ->and(OutboundEmail::where('status', 'pending')->sole()->to_address)->toBe('new@example.com')
            ->and(ShopAuditLog::pluck('action')->all())->toBe(['reveal_code', 'resend_email', 'change_email']);
    });

    it('A2: the shop pages render for a superadmin and stay closed to a parent', function () {
        $stripe = shStripe();
        [$order] = shPaidOrder($stripe);
        $admin = User::factory()->parent()->create(['is_superadmin' => true]);
        $this->actingAs($admin);

        Livewire::test(ListShopOrders::class)->assertOk()->assertCanSeeTableRecords([$order]);
        Livewire::test(ViewShopOrder::class, ['record' => $order->id])->assertOk()->assertSee($order->number);
        Livewire::test(ListOutboundEmails::class)->assertOk();
        Livewire::test(ListShopProducts::class)->assertOk();

        $this->actingAs(User::factory()->parent()->create());
        $this->get('/admin/shop-orders')->assertForbidden();
    });

    it('A3: shop:sync-products copies the Stripe price and product into the admin', function () {
        shStripe();
        Artisan::call('shop:sync-products');
        $gift = ShopProduct::where('key', 'gift_voucher')->sole();
        expect($gift->unit_amount)->toBe(4999)->and($gift->tax_inclusive)->toBeTrue()->and($gift->stripe_price_id)->toBe(SH_PRICE_GIFT);
    });

    it('A4: shop:import-files stores the built PDFs (kit + sample) per language and skips non-PDFs', function () {
        $dir = storage_path('framework/testing/kit-build');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/petprep-promise-kit-sl.pdf', '%PDF-1.7 kit');
        File::put($dir.'/petprep-promise-sample-sl.pdf', '%PDF-1.7 sample');
        File::put($dir.'/petprep-promise-kit-en.pdf', 'not a pdf');

        Artisan::call('shop:import-files', ['dir' => $dir]);
        expect(ShopFiles::locales('promise_kit'))->toBe(['sl'])->and(ShopFiles::locales(ShopFiles::SAMPLE))->toBe(['sl']);
        File::deleteDirectory($dir);
    });
});

it('L1: the shop e-mail texts exist in all 15 website languages with the same keys and placeholders', function () {
    $en = require lang_path('en/shop.php');
    foreach (WebsiteLocale::ALL as $locale) {
        $t = require lang_path($locale.'/shop.php');
        expect(array_keys($t))->toBe(array_keys($en), $locale);
        foreach ($en as $key => $text) {
            preg_match_all('/:[a-z]+/', $text, $want);
            preg_match_all('/:[a-z]+/', $t[$key], $have);
            sort($want[0]);
            sort($have[0]);
            expect($have[0])->toBe($want[0], "{$locale}.{$key}");
        }
    }
});

it('L2: the redeem slugs match the website (all 15 languages)', function () {
    expect(array_keys((array) config('shop.redeem_slugs')))->toEqualCanonicalizing(WebsiteLocale::ALL);
});
