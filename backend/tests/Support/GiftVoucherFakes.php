<?php

/*
| M6-S01 test support: a stateful Lemon Squeezy fake (GvLs) and helpers shared by
| GiftVoucherTest and GiftVoucherQaFixesTest. Loaded with require_once.
*/

use App\Models\AppSetting;
use App\Models\Family;
use App\Models\Pet;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\FamilyService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

const GV_NOW = '2026-11-11 10:00:00';
const GV_API = 'https://ls.test';
const GV_SECRET = 'gv-ls-signing-secret';
const GV_STORE = 4101;
const GV_PRODUCT = 5202;
const GV_VARIANT = 6303;
const GV_CODE = '38b1460a-5104-4067-a91d-77b872934d51';
const GV_DISCORD = 'https://discord.com/api/webhooks/987/gv-secret';

/**
 * A stateful fake of the Lemon Squeezy License + Admin API for one license key.
 */
class GvLs
{
    public int $keyId = 7001;

    public ?int $orderId = 8001;

    public string $key = GV_CODE;

    public bool $known = true;

    public string $status = 'inactive';

    public ?int $limit = 1;

    public int $usage = 0;

    public ?string $createdAt = '2026-11-01T09:00:00.000000Z';

    public bool $testMode = false;

    public int $store = GV_STORE;

    public int $product = GV_PRODUCT;

    public int $variant = GV_VARIANT;

    /** @var list<array{identifier: string, name: string}> */
    public array $instances = [];

    /** Failure of the next validate / activate / instances calls: 'connection' | HTTP status */
    public string|int|null $validateFails = null;

    public string|int|null $activateFails = null;

    public string|int|null $instancesFails = null;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    /** HTTP status Expo answers with. */
    public int $expoStatus = 200;

    /** Runs just before an activation is answered (simulates a concurrent webhook). */
    public ?Closure $beforeActivate = null;

    public function install(): self
    {
        Http::fake([
            GV_API.'/*' => fn (Request $r) => $this->handle($r),
            'discord.com/api/webhooks/*' => Http::response('', 204),
            'exp.host/*' => fn (Request $r) => $this->expoStatus !== 200 ? Http::response('', $this->expoStatus)
                : Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => (string) Str::uuid()], $r->data())], 200),
        ]);

        return $this;
    }

    public function count(string $op): int
    {
        return count(array_filter($this->calls, fn (array $c): bool => $c[0] === $op));
    }

    public function handle(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/v1/licenses/validate')) {
            $this->calls[] = ['validate', $r->data()];

            return $this->fail($this->validateFails) ?? $this->licenseAnswer($r->data());
        }
        if (str_ends_with($path, '/v1/licenses/activate')) {
            $this->calls[] = ['activate', $r->data()];
            if ($this->beforeActivate !== null) {
                ($this->beforeActivate)();
            }
            if (($fail = $this->fail($this->activateFails)) !== null) {
                return $fail;
            }
            if (! $this->known || $r->data()['license_key'] !== $this->key) {
                return Http::response(['activated' => false, 'error' => 'license_key not found.'], 404);
            }
            if ($this->usage >= max(1, $this->limit ?? 1)) {
                return Http::response(['activated' => false, 'error' => 'This license key has reached the activation limit.', 'license_key' => $this->licenseKey(), 'meta' => $this->meta()], 400);
            }
            $this->usage++;
            $this->status = 'active';
            $instance = ['id' => (string) Str::uuid(), 'name' => (string) $r->data()['instance_name'], 'created_at' => now()->toIso8601String()];
            $this->instances[] = ['identifier' => $instance['id'], 'name' => $instance['name']];

            return Http::response(['activated' => true, 'error' => null, 'license_key' => $this->licenseKey(), 'instance' => $instance, 'meta' => $this->meta()], 200);
        }
        if (str_ends_with($path, '/v1/license-key-instances')) {
            $this->calls[] = ['instances', []];

            return $this->fail($this->instancesFails) ?? Http::response(['data' => array_map(fn (array $i) => [
                'type' => 'license-key-instances', 'id' => '1', 'attributes' => $i + ['license_key_id' => $this->keyId],
            ], $this->instances)], 200);
        }

        return Http::response([], 404);
    }

    private function fail(string|int|null $mode): mixed
    {
        return match (true) {
            $mode === null => null,
            $mode === 'connection' => Http::failedConnection(),
            default => Http::response(['error' => 'upstream'], (int) $mode),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function licenseAnswer(array $data): mixed
    {
        if (! $this->known || ($data['license_key'] ?? null) !== $this->key) {
            return Http::response(['valid' => false, 'error' => 'license_key not found.', 'license_key' => null, 'instance' => null, 'meta' => null], 404);
        }
        $instance = null;
        if (isset($data['instance_id'])) {
            $match = collect($this->instances)->firstWhere('identifier', $data['instance_id']);
            if ($match === null) {
                return Http::response(['valid' => false, 'error' => 'license_key instance not found.', 'license_key' => $this->licenseKey(), 'instance' => null, 'meta' => $this->meta()], 404);
            }
            $instance = ['id' => $match['identifier'], 'name' => $match['name']];
        }

        return Http::response([
            'valid' => in_array($this->status, ['active', 'inactive'], true),
            'error' => null,
            'license_key' => $this->licenseKey(),
            'instance' => $instance,
            'meta' => $this->meta(),
        ], 200);
    }

    /** @return array<string, mixed> */
    private function licenseKey(): array
    {
        return [
            'id' => $this->keyId, 'status' => $this->status, 'key' => $this->key, 'activation_limit' => $this->limit,
            'activation_usage' => $this->usage, 'created_at' => $this->createdAt, 'expires_at' => null, 'test_mode' => $this->testMode,
        ];
    }

    /** @return array<string, mixed> */
    private function meta(): array
    {
        return [
            'store_id' => $this->store, 'order_id' => $this->orderId, 'order_item_id' => 9001, 'product_id' => $this->product,
            'product_name' => 'PetPrep darilni bon', 'variant_id' => $this->variant, 'variant_name' => 'Default',
            'customer_id' => 77, 'customer_name' => 'Buyer Person', 'customer_email' => 'buyer@example.com',
        ];
    }
}

function gvSwitch(bool $on): void
{
    AppSetting::updateOrCreate(['key' => AppSettingsService::GIFT_VOUCHERS_KEY], ['value' => ['enabled' => $on]]);
    cache()->flush();
}

function gvLs(): GvLs
{
    return (new GvLs)->install();
}

/**
 * A parent (+ child) with a family and, optionally, a pet.
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

/**
 * M6-S01b: a website sign-in token (`gift:redeem`, 15 min) as WebParentSessionService
 * issues it — minted directly so gift tests need no sign-in round trip (the endpoint
 * itself is WebParentSessionTest's).
 */
function gvWebToken(User $user, ?Carbon $expiresAt = null): string
{
    return $user->createToken('web:gift-redeem', ['gift:redeem'], $expiresAt ?? now()->addMinutes(15))->plainTextToken;
}

/**
 * POST /api/gift/redeem as $as: a User or an account e-mail (test-side lookup only —
 * the API never sees an e-mail) → a fresh web token; a raw token string via
 * `token:`; null / an unknown e-mail → no Authorization header.
 *
 * @param  array<string, mixed>  $extra
 */
function gvRedeem(string $code = GV_CODE, User|string|null $as = 'parent@example.com', string $ip = '203.0.113.10', array $extra = [], ?string $token = null): TestResponse
{
    $user = is_string($as) ? User::findByEmail($as) : $as;
    $token ??= $user !== null ? gvWebToken($user) : null;
    app('auth')->forgetGuards();

    $test = test()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['Authorization' => $token !== null ? 'Bearer '.$token : '']);

    return $test->postJson('/api/gift/redeem', array_merge(['code' => $code, 'locale' => 'en'], $extra));
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
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function gvLicenseKeyEvent(string $event, array $attributes = [], array $meta = []): array
{
    return [
        'meta' => array_merge(['event_name' => $event, 'test_mode' => false, 'webhook_id' => 'wh-1', 'custom_data' => ['note' => 'x']], $meta),
        'data' => [
            'type' => 'license-keys',
            'id' => 7001,
            'attributes' => array_merge([
                'store_id' => GV_STORE, 'order_id' => 8001, 'order_item_id' => 9001, 'product_id' => GV_PRODUCT,
                'status' => 'inactive', 'key' => GV_CODE, 'key_short' => 'XXXX-934d51', 'activation_limit' => 1,
                'instances_count' => 0, 'disabled' => false, 'expires_at' => null,
                'created_at' => '2026-11-01T09:00:00.000000Z', 'updated_at' => '2026-11-01T09:00:00.000000Z',
                'test_mode' => false, 'user_email' => 'buyer@example.com', 'user_name' => 'Buyer Person',
            ], $attributes),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function gvOrderEvent(string $event, array $attributes = [], int $orderId = 8001): array
{
    return [
        'meta' => ['event_name' => $event, 'test_mode' => false],
        'data' => [
            'type' => 'orders',
            'id' => $orderId,
            'attributes' => array_merge([
                'store_id' => GV_STORE, 'status' => 'paid', 'refunded' => false, 'refunded_at' => null, 'total' => 4999,
                'currency' => 'EUR', 'test_mode' => false,
                'first_order_item' => ['order_id' => $orderId, 'product_id' => GV_PRODUCT, 'variant_id' => GV_VARIANT],
                'user_email' => 'buyer@example.com', 'user_name' => 'Buyer Person',
                'urls' => ['receipt' => 'https://example.com/r'],
                'created_at' => '2026-11-01T09:00:00.000000Z', 'updated_at' => '2026-11-01T09:00:00.000000Z',
            ], $attributes),
        ],
    ];
}

/**
 * @param  array<string, mixed>|string  $body
 */
function gvWebhook(array|string $body, ?string $signature = null, bool $sign = true): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    if ($sign) {
        $server['HTTP_X_SIGNATURE'] = $signature ?? hash_hmac('sha256', $raw, GV_SECRET);
    }
    app('auth')->forgetGuards();

    return test()->call('POST', '/api/webhooks/lemonsqueezy', [], [], [], $server, $raw);
}

function gvRefund(bool $full = true, string $updatedAt = '2026-11-12T09:00:00.000000Z'): TestResponse
{
    return gvWebhook(gvOrderEvent('order_refunded', [
        'status' => $full ? 'refunded' : 'partial_refund', 'refunded' => $full, 'refunded_amount' => $full ? 4999 : 1000,
        'refunded_at' => $updatedAt, 'updated_at' => $updatedAt,
    ]));
}
