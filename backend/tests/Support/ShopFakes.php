<?php

/*
| M6-S09 test support: a stateful Stripe fake (ShStripe), signed webhooks, a paid gift
| order and the website gift-redeem helpers shared by ShopTest, GiftVoucherTest,
| OutboundEmailTest and WebParentSessionTest. Loaded with require_once.
*/

use App\Models\AppSetting;
use App\Models\Family;
use App\Models\GiftVoucher;
use App\Models\Pet;
use App\Models\ShopOrder;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FamilyService;
use App\Services\Shop\ShopFulfillment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

const SH_NOW = '2026-11-17 10:00:00';
const SH_API = 'https://stripe.test';
const SH_WHSEC = 'whsec_test_shop';
const SH_PRICE_GIFT = 'price_gift_test';
const SH_PRICE_KIT = 'price_kit_test';
const SH_DISCORD = 'https://discord.com/api/webhooks/987/sh-secret';

/**
 * A stateful fake of the Stripe API: Checkout Sessions (create / retrieve) and prices.
 */
class ShStripe
{
    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var list<array<string, mixed>> bodies of POST /v1/checkout/sessions */
    public array $created = [];

    public int $retrieves = 0;

    public bool $down = false;

    public function install(): self
    {
        Http::fake([SH_API.'/*' => function (Request $request) {
            if ($this->down) {
                return Http::response(['error' => ['type' => 'api_error']], 500);
            }
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'POST' && $path === '/v1/checkout/sessions') {
                $this->created[] = $request->data();
                $id = 'cs_test_'.Str::random(24);
                $this->sessions[$id] = shSession($id, (string) data_get($request->data(), 'metadata.petprep_product'), status: 'open', paymentStatus: 'unpaid',
                    price: (string) data_get($request->data(), 'line_items.0.price'), locale: (string) data_get($request->data(), 'metadata.locale', 'en'));

                return Http::response(['id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/'.$id]);
            }
            if ($request->method() === 'GET' && preg_match('#^/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)$#', $path, $m)) {
                $this->retrieves++;

                return isset($this->sessions[$m[1]]) ? Http::response($this->sessions[$m[1]]) : Http::response(['error' => ['type' => 'invalid_request_error']], 404);
            }
            if ($request->method() === 'GET' && preg_match('#^/v1/prices/(price_[A-Za-z0-9_]+)$#', $path, $m)) {
                return Http::response([
                    'id' => $m[1], 'unit_amount' => $m[1] === SH_PRICE_GIFT ? 4999 : 690, 'currency' => 'eur', 'tax_behavior' => 'inclusive',
                    'active' => true, 'livemode' => false,
                    'product' => ['id' => 'prod_'.$m[1], 'name' => $m[1] === SH_PRICE_GIFT ? 'PetPrep Gift Voucher' : 'PetPrep Promise Kit', 'active' => true],
                ]);
            }

            return Http::response(['error' => ['type' => 'invalid_request_error']], 404);
        }]);

        return $this;
    }

    /** Mark a session paid (as Stripe would after the buyer paid). */
    public function pay(string $id, array $overrides = []): void
    {
        $this->sessions[$id] = array_replace_recursive($this->sessions[$id], [
            'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_'.substr($id, 8, 16),
            'customer_details' => ['email' => 'buyer@example.com', 'name' => 'Buyer Person', 'address' => ['country' => 'SI']],
        ], $overrides);
    }
}

/**
 * @return array<string, mixed>
 */
function shSession(string $id, string $product = 'gift_voucher', string $status = 'complete', string $paymentStatus = 'paid', bool $livemode = true, ?string $price = null, string $locale = 'sl', ?string $email = 'buyer@example.com'): array
{
    $price ??= $product === 'promise_kit' ? SH_PRICE_KIT : SH_PRICE_GIFT;

    return [
        'id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => $status, 'payment_status' => $paymentStatus,
        'livemode' => $livemode, 'currency' => 'eur', 'amount_total' => $product === 'promise_kit' ? 690 : 4999,
        'total_details' => ['amount_tax' => $product === 'promise_kit' ? 124 : 901],
        'payment_intent' => $paymentStatus === 'paid' ? 'pi_'.substr($id, 8, 16) : null,
        'metadata' => ['petprep_product' => $product, 'locale' => $locale],
        'customer_details' => $paymentStatus === 'paid' ? ['email' => $email, 'name' => 'Buyer Person', 'address' => ['country' => 'SI']] : null,
        'line_items' => ['object' => 'list', 'data' => [['price' => ['id' => $price], 'quantity' => 1]]],
    ];
}

function shConfig(): void
{
    config([
        'services.stripe.secret_key' => 'rk_test_shop',
        'services.stripe.webhook_secret' => SH_WHSEC,
        'services.stripe.api_base' => SH_API,
        'services.stripe.accept_test_mode' => false,
        'shop.products.gift_voucher.price_id' => SH_PRICE_GIFT,
        'shop.products.promise_kit.price_id' => SH_PRICE_KIT,
        'shop.files_disk' => 'shop_test',
        'filesystems.disks.shop_test' => ['driver' => 'local', 'root' => storage_path('framework/testing/shop')],
    ]);
}

function shStripe(): ShStripe
{
    return (new ShStripe)->install();
}

function gvSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::GIFT_VOUCHERS_KEY], ['value' => ['enabled' => $on]]);
    cache()->flush();
}

function shKitSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::PROMISE_KIT_KEY], ['value' => ['enabled' => $on]]);
    cache()->flush();
}

/**
 * A paid session at the fake + the order fulfilled through ShopFulfillment.
 *
 * @param  array<string, mixed>  $session
 * @return array{0: ShopOrder, 1: string|null} [order, voucher code]
 */
function shPaidOrder(ShStripe $stripe, string $product = 'gift_voucher', array $session = []): array
{
    $id = 'cs_test_'.Str::random(24);
    $stripe->sessions[$id] = array_replace_recursive(shSession($id, $product), $session);
    $order = app(ShopFulfillment::class)->fulfill($id);
    $voucher = $order?->voucher()->first();

    return [$order, $voucher?->code()];
}

/**
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function shEvent(string $type, array $object, bool $livemode = true, ?string $id = null): array
{
    return ['id' => $id ?? 'evt_'.Str::random(20), 'object' => 'event', 'type' => $type, 'livemode' => $livemode, 'data' => ['object' => $object]];
}

/**
 * @param  array<string, mixed>|string  $body
 */
function shWebhook(array|string $body, ?string $header = null, ?int $t = null): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body);
    $t ??= now()->getTimestamp();
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($header !== '') {
        $server['HTTP_STRIPE_SIGNATURE'] = $header ?? 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, SH_WHSEC);
    }
    app('auth')->forgetGuards();

    return test()->call('POST', '/api/webhooks/stripe', [], [], [], $server, $raw);
}

/**
 * A parent (+ child) with a family.
 *
 * @return array{0: User, 1: User, 2: Family}
 */
function gvParent(string $email = 'parent@example.com'): array
{
    $parent = User::factory()->parent()->create(['email' => $email, 'timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return [$parent, $child, app(FamilyService::class)->familyOf($parent)];
}

function gvPaymentRequiredPet(User $child): Pet
{
    return disableHygieneEvents(Pet::factory()->borderCollie()->trial()->create(['user_id' => $child->id]));
}

function gvFreeMutt(User $child): Pet
{
    return disableHygieneEvents(Pet::factory()->freePlan()->create([
        'user_id' => $child->id, 'breed_type' => 'mutt', 'born_at' => Carbon::parse('2026-07-01 08:00:00', 'UTC'),
        'arrival_age_months' => 2,
    ]));
}

/** M6-S01b: a website sign-in token (`gift:redeem`, 15 min) as WebParentSessionService issues it. */
function gvWebToken(User $user, ?Carbon $expiresAt = null): string
{
    return $user->createToken('web:gift-redeem', ['gift:redeem'], $expiresAt ?? now()->addMinutes(15))->plainTextToken;
}

/**
 * POST /api/gift/redeem as $as (a User or an account e-mail → a fresh web token; null → no token).
 *
 * @param  array<string, mixed>  $extra
 */
function gvRedeem(string $code, User|string|null $as = 'parent@example.com', string $ip = '203.0.113.10', array $extra = [], ?string $token = null): TestResponse
{
    $user = is_string($as) ? User::findByEmail($as) : $as;
    $token ??= $user !== null ? gvWebToken($user) : null;
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['Authorization' => $token !== null ? 'Bearer '.$token : ''])
        ->postJson('/api/gift/redeem', array_merge(['code' => $code, 'locale' => 'en'], $extra));
}

function gvActivate(User $parent, Pet $pet): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson("/api/parent/pets/{$pet->id}/challenge/activate");
}

/** @return array<string, mixed> */
function gvBilling(User $parent): array
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return getJson('/api/parent/billing')->assertOk()->json();
}

/**
 * A voucher row straight in the DB (credit / reset regressions).
 *
 * @param  array<string, mixed>  $attributes
 */
function shVoucherRow(array $attributes = []): GiftVoucher
{
    $order = ShopOrder::create([
        'product_key' => 'gift_voucher', 'stripe_checkout_session_id' => 'cs_row_'.Str::random(16), 'livemode' => true,
        'status' => 'paid', 'locale' => 'en', 'paid_at' => now(), 'fulfilled_at' => now(),
    ]);
    $code = GiftVoucher::newCode();

    return GiftVoucher::create(array_merge([
        'shop_order_id' => $order->id, 'key_hash' => GiftVoucher::hashOf(GiftVoucher::canonical($code)),
        'key_last4' => substr(GiftVoucher::canonical($code), -4), 'code_encrypted' => Crypt::encryptString($code),
        'environment' => 'PRODUCTION', 'status' => 'purchased', 'purchased_at' => now(), 'expires_at' => now()->addYear(),
    ], $attributes));
}
