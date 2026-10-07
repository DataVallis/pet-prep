<?php

use App\Enums\BreedType;
use App\Enums\EntitlementRevokeReason;
use App\Events\PetUpdated;
use App\Filament\Resources\FamilyEntitlementResource;
use App\Filament\Resources\FamilyEntitlementResource\Pages\ListFamilyEntitlements;
use App\Filament\Resources\PurchaseEventResource;
use App\Filament\Resources\PurchaseEventResource\Pages\ListPurchaseEvents;
use App\Models\Family;
use App\Models\FamilyEntitlement;
use App\Models\Pet;
use App\Models\PurchaseEvent;
use App\Models\User;
use App\Services\ChildProfileService;
use App\Services\EntitlementService;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M3-08 — RevenueCat webhook → purchase_events ledger → family entitlements
|--------------------------------------------------------------------------
*/

const RC_SECRET = 'rc-test-webhook-secret';
const RC_PRODUCT = 'petprep_challenge_12w';
const RC_SUB = 'petprep_challenge_monthly';

beforeEach(function () {
    config([
        'services.revenuecat.webhook_secret' => RC_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.entitlements' => [RC_PRODUCT => 'challenge'],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/**
 * A RevenueCat event (one-time challenge purchase by default).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcEvent(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'type' => 'NON_RENEWING_PURCHASE',
        'app_user_id' => null,
        'original_app_user_id' => null,
        'aliases' => [],
        'product_id' => RC_PRODUCT,
        'entitlement_ids' => ['challenge'],
        'store' => 'APP_STORE',
        'environment' => 'PRODUCTION',
        'transaction_id' => '2000000123',
        'original_transaction_id' => '2000000123',
        'purchased_at_ms' => Carbon::now()->getTimestampMs(),
        'expiration_at_ms' => null,
        'event_timestamp_ms' => Carbon::now()->getTimestampMs(),
    ], $overrides);
}

/**
 * A subscription event of `$parent` expiring at `$expiresUtc`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcSubEvent(User $parent, string $type, string $expiresUtc, array $overrides = []): array
{
    return rcEvent(array_merge([
        'type' => $type,
        'app_user_id' => (string) $parent->id,
        'product_id' => RC_SUB,
        'expiration_at_ms' => Carbon::parse($expiresUtc, 'UTC')->getTimestampMs(),
        'period_type' => 'NORMAL',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $event
 */
function rcSend(array $event, ?string $authorization = 'Bearer '.RC_SECRET): TestResponse
{
    app('auth')->forgetGuards();
    $headers = $authorization === null ? [] : ['Authorization' => $authorization];

    return test()->withHeaders($headers)->postJson('/api/webhooks/revenuecat', ['api_version' => '1.0', 'event' => $event]);
}

function rcFamily(User $parent): Family
{
    return app(FamilyService::class)->familyOf($parent);
}

function rcHas(User $parent): bool
{
    return app(EntitlementService::class)->familyHas(rcFamily($parent), 'challenge');
}

/**
 * Parent with a child and an active pet.
 *
 * @return array{0: User, 1: Pet}
 */
function rcParentWithPet(): array
{
    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->create(['user_id' => $child->id]);

    return [$parent, $pet];
}

function rcSecondParent(User $parent): User
{
    $second = User::factory()->parent()->create();
    $invites = app(FamilyInviteService::class);
    $invites->joinFamily($second, $invites->createInvite($parent)['code']);

    return $second;
}

// ─────────────────────────────────────────────────────────────────────────
describe('authentication (fails closed)', function () {
    it('answers 503 and stores nothing when no secret is configured', function () {
        config(['services.revenuecat.webhook_secret' => null]);
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertStatus(503);
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), null)->assertStatus(503);

        config(['services.revenuecat.webhook_secret' => '']);
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Bearer ')->assertStatus(503);

        expect(PurchaseEvent::count())->toBe(0)
            ->and(FamilyEntitlement::count())->toBe(0);
    });

    it('answers 401 for a missing or wrong Authorization header, before validating the body', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), null)->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Bearer wrong')->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Bearer '.RC_SECRET.'x')->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Basic '.RC_SECRET)->assertUnauthorized();
        // Garbage body + wrong secret → 401, not a 422 that describes the schema.
        test()->withHeaders(['Authorization' => 'nope'])->postJson('/api/webhooks/revenuecat', ['x' => 1])->assertUnauthorized();

        expect(PurchaseEvent::count())->toBe(0)->and(rcHas($parent))->toBeFalse();
    });

    it('accepts "Bearer <secret>" and the bare secret', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk()->assertJsonPath('outcome', 'granted');
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), RC_SECRET)->assertOk()->assertJsonPath('outcome', 'extended');

        expect(PurchaseEvent::count())->toBe(2);
    });

    it('validates the body once authenticated', function () {
        rcSend(['type' => 'INITIAL_PURCHASE'])->assertUnprocessable()->assertJsonValidationErrors('event.id');
        rcSend(['id' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('event.type');
        test()->withHeaders(['Authorization' => 'Bearer '.RC_SECRET])->postJson('/api/webhooks/revenuecat', [])
            ->assertUnprocessable()->assertJsonValidationErrors('event');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('ledger + idempotency', function () {
    it('stores every field of the event once and answers a repeat 200 without reprocessing', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $pet] = rcParentWithPet();
        $event = rcEvent([
            'app_user_id' => (string) $parent->id,
            'original_app_user_id' => '$RCAnonymousID:abc',
            'aliases' => ['$RCAnonymousID:abc', (string) $parent->id],
            'purchased_at_ms' => 1791367200000, // 2026-10-07 10:00:00 UTC
            'subscriber_attributes' => ['$email' => ['value' => 'parent@example.com', 'updated_at_ms' => 1]],
        ]);

        rcSend($event)->assertOk()->assertJson(['received' => true, 'duplicate' => false, 'outcome' => 'granted']);
        rcSend($event)->assertOk()->assertJson(['received' => true, 'duplicate' => true, 'outcome' => null]);

        $row = PurchaseEvent::sole();
        expect($row->event_id)->toBe($event['id'])
            ->and($row->type)->toBe('NON_RENEWING_PURCHASE')
            ->and($row->app_user_id)->toBe((string) $parent->id)
            ->and($row->original_app_user_id)->toBe('$RCAnonymousID:abc')
            ->and($row->aliases)->toBe(['$RCAnonymousID:abc', (string) $parent->id])
            ->and($row->product_id)->toBe(RC_PRODUCT)
            ->and($row->entitlement_ids)->toBe(['challenge'])
            ->and($row->store)->toBe('APP_STORE')
            ->and($row->environment)->toBe('PRODUCTION')
            ->and($row->transaction_id)->toBe('2000000123')
            ->and($row->original_transaction_id)->toBe('2000000123')
            ->and($row->purchased_at->toIso8601String())->toBe('2026-10-07T10:00:00+00:00')
            ->and($row->expiration_at)->toBeNull()
            ->and($row->family_id)->toBe(rcFamily($parent)->id)
            ->and($row->outcome->value)->toBe('granted')
            ->and($row->processed_at)->not->toBeNull()
            // No customer e-mail / name in our copy of the payload.
            ->and($row->payload['event'])->not->toHaveKey('subscriber_attributes')
            ->and($row->payload['event']['id'])->toBe($event['id'])
            ->and(FamilyEntitlement::count())->toBe(1);

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'entitlements_updated');
    });

    it('keeps one unrevoked row per family and entitlement (DB constraint)', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk();

        expect(fn () => FamilyEntitlement::create([
            'family_id' => rcFamily($parent)->id, 'entitlement' => 'challenge', 'granted_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('event types', function () {
    it('NON_RENEWING_PURCHASE grants a lifetime challenge; the old Border Collie rewrite of a pet is gone', function () {
        seedBreedConfigs();
        [$parent, $pet] = rcParentWithPet();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk()->assertJsonPath('outcome', 'granted');

        $row = FamilyEntitlement::sole();
        expect($row->family_id)->toBe(rcFamily($parent)->id)
            ->and($row->entitlement)->toBe('challenge')
            ->and($row->source)->toBe('revenuecat')
            ->and($row->product_id)->toBe(RC_PRODUCT)
            ->and($row->store)->toBe('APP_STORE')
            ->and($row->environment)->toBe('PRODUCTION')
            ->and($row->expires_at)->toBeNull()
            ->and($row->last_event_id)->toBe(PurchaseEvent::sole()->event_id)
            ->and(rcHas($parent))->toBeTrue()
            ->and($pet->fresh()->breed_type)->toBe(BreedType::Mutt);
    });

    it('INITIAL_PURCHASE → RENEWAL extends; an out-of-order EXPIRATION is ignored; the matching one revokes', function () {
        Event::fake([PetUpdated::class]);
        [$parent] = rcParentWithPet();

        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00'))->assertJsonPath('outcome', 'granted');
        rcSend(rcSubEvent($parent, 'RENEWAL', '2026-12-07 10:00:00'))->assertJsonPath('outcome', 'extended');
        expect(FamilyEntitlement::sole()->expires_at->toIso8601String())->toBe('2026-12-07T10:00:00+00:00');

        // The first period's EXPIRATION arrives late: already renewed → stays.
        rcSend(rcSubEvent($parent, 'EXPIRATION', '2026-11-07 10:00:00'))->assertJsonPath('outcome', 'ignored');
        expect(rcHas($parent))->toBeTrue();

        // Past expires_at the entitlement is inactive even before EXPIRATION arrives.
        Carbon::setTestNow('2026-12-07 10:00:01');
        expect(rcHas($parent))->toBeFalse();

        rcSend(rcSubEvent($parent, 'EXPIRATION', '2026-12-07 10:00:00'))->assertJsonPath('outcome', 'revoked');
        $row = FamilyEntitlement::sole();
        expect($row->revoked_at)->not->toBeNull()
            ->and($row->revoke_reason)->toBe(EntitlementRevokeReason::Expired);

        // granted (active) once; EXPIRATION of an already lapsed row flips nothing visible.
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('a lapsed subscription bought again becomes active with a new granted_at', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00'));
        Carbon::setTestNow('2026-11-20 10:00:00');
        expect(rcHas($parent))->toBeFalse();

        rcSend(rcSubEvent($parent, 'RENEWAL', '2026-12-20 10:00:00', ['purchased_at_ms' => now()->getTimestampMs()]))->assertJsonPath('outcome', 'extended');

        $row = FamilyEntitlement::sole();
        expect(rcHas($parent))->toBeTrue()
            ->and($row->granted_at->toIso8601String())->toBe('2026-11-20T10:00:00+00:00');
    });

    it('CANCELLATION of a subscription keeps access until expiration; UNCANCELLATION is recorded as extended', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00'));

        rcSend(rcSubEvent($parent, 'CANCELLATION', '2026-11-07 10:00:00', ['cancel_reason' => 'UNSUBSCRIBE']))->assertJsonPath('outcome', 'recorded');
        expect(rcHas($parent))->toBeTrue();
        // Even a refunded subscription keeps its period; RevenueCat sends EXPIRATION.
        rcSend(rcSubEvent($parent, 'CANCELLATION', '2026-11-07 10:00:00', ['cancel_reason' => 'CUSTOMER_SUPPORT']))->assertJsonPath('outcome', 'recorded');
        expect(rcHas($parent))->toBeTrue();

        rcSend(rcSubEvent($parent, 'UNCANCELLATION', '2026-11-07 10:00:00'))->assertJsonPath('outcome', 'extended');
        expect(rcHas($parent))->toBeTrue()->and(FamilyEntitlement::count())->toBe(1);
    });

    it('a refund (CANCELLATION, CUSTOMER_SUPPORT) of the one-time purchase revokes at once and broadcasts', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $pet] = rcParentWithPet();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'cancel_reason' => 'CUSTOMER_SUPPORT']))
            ->assertOk()->assertJsonPath('outcome', 'revoked');

        $row = FamilyEntitlement::sole();
        expect(rcHas($parent))->toBeFalse()
            ->and($row->revoke_reason)->toBe(EntitlementRevokeReason::Refund);
        Event::assertDispatchedTimes(PetUpdated::class, 2);

        actingAsRole($parent);
        getJson('/api/parent/entitlements')->assertOk()
            ->assertJsonPath('entitlements.0.key', 'challenge')
            ->assertJsonPath('entitlements.0.active', false);
    });

    it('a one-time refund of another product leaves the family entitlement alone', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'product_id' => 'other_one_time', 'cancel_reason' => 'CUSTOMER_SUPPORT']))
            ->assertJsonPath('outcome', 'ignored');

        expect(rcHas($parent))->toBeTrue();
    });

    it('a subscription EXPIRATION never ends a lifetime purchase', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));
        rcSend(rcSubEvent($parent, 'RENEWAL', '2026-11-07 10:00:00'))->assertJsonPath('outcome', 'extended');
        expect(FamilyEntitlement::sole()->expires_at)->toBeNull()
            ->and(FamilyEntitlement::sole()->product_id)->toBe(RC_PRODUCT);

        rcSend(rcSubEvent($parent, 'EXPIRATION', '2026-11-07 10:00:00'))->assertJsonPath('outcome', 'ignored');
        expect(rcHas($parent))->toBeTrue();
    });

    it('records BILLING_ISSUE, PRODUCT_CHANGE, SUBSCRIBER_ALIAS and TEST without changing anything', function (string $type) {
        $parent = User::factory()->parent()->create();
        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00'));
        $before = FamilyEntitlement::sole()->toArray();

        rcSend(rcSubEvent($parent, $type, '2026-12-07 10:00:00', ['new_product_id' => 'x']))->assertOk()->assertJsonPath('outcome', 'recorded');

        expect(FamilyEntitlement::sole()->toArray())->toBe($before)
            ->and(PurchaseEvent::where('type', $type)->sole()->outcome->value)->toBe('recorded');
    })->with(['BILLING_ISSUE', 'PRODUCT_CHANGE', 'SUBSCRIBER_ALIAS', 'TEST']);

    it('answers TEST events from the dashboard (unknown user) with 200', function () {
        rcSend(rcEvent(['type' => 'TEST', 'app_user_id' => 'dashboard-test-user', 'entitlement_ids' => null]))
            ->assertOk()->assertJsonPath('outcome', 'recorded');

        expect(PurchaseEvent::sole()->family_id)->toBeNull();
    });

    it('stores event types we do not act on as ignored', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['type' => 'SUBSCRIPTION_PAUSED', 'app_user_id' => (string) $parent->id]))->assertOk()->assertJsonPath('outcome', 'ignored');

        expect(FamilyEntitlement::count())->toBe(0);
    });

    it('falls back to the product map; unknown entitlements and products grant nothing', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'entitlement_ids' => ['pro_plus'], 'product_id' => 'mystery']))
            ->assertOk()->assertJsonPath('outcome', 'no_entitlement');
        expect(rcHas($parent))->toBeFalse();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'entitlement_ids' => null]))
            ->assertOk()->assertJsonPath('outcome', 'granted');
        expect(rcHas($parent))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('TRANSFER', function () {
    it('moves the entitlement from the old family to the new one', function () {
        Event::fake([PetUpdated::class]);
        [$a, $petA] = rcParentWithPet();
        [$b, $petB] = rcParentWithPet();
        rcSend(rcEvent(['app_user_id' => (string) $a->id]));

        rcSend(rcEvent([
            'type' => 'TRANSFER', 'app_user_id' => null, 'product_id' => null, 'entitlement_ids' => null,
            'transferred_from' => [(string) $a->id, '$RCAnonymousID:old'],
            'transferred_to' => [(string) $b->id],
        ]))->assertOk()->assertJsonPath('outcome', 'transferred');

        $old = FamilyEntitlement::where('family_id', rcFamily($a)->id)->sole();
        $new = FamilyEntitlement::where('family_id', rcFamily($b)->id)->sole();
        expect(rcHas($a))->toBeFalse()
            ->and(rcHas($b))->toBeTrue()
            ->and($old->revoke_reason)->toBe(EntitlementRevokeReason::Transferred)
            ->and($new->product_id)->toBe(RC_PRODUCT)
            ->and($new->expires_at)->toBeNull()
            ->and(PurchaseEvent::where('type', 'TRANSFER')->sole()->family_id)->toBe(rcFamily($b)->id);

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $petA->id && $e->eventType === 'entitlements_updated');
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $petB->id && $e->eventType === 'entitlements_updated');
    });

    it('takes the entitlement away even when the receiver is not ours; unknown on both sides → unknown_user', function () {
        $a = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $a->id]));

        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => ['nobody'], 'transferred_to' => ['$RCAnonymousID:x']]))
            ->assertOk()->assertJsonPath('outcome', 'unknown_user');
        expect(rcHas($a))->toBeTrue();

        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => [(string) $a->id], 'transferred_to' => ['$RCAnonymousID:x']]))
            ->assertOk()->assertJsonPath('outcome', 'transferred');
        expect(rcHas($a))->toBeFalse();
    });

    it('a transfer between two parents of the same family changes nothing', function () {
        $a = User::factory()->parent()->create();
        $b = rcSecondParent($a);
        rcSend(rcEvent(['app_user_id' => (string) $a->id]));

        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => [(string) $a->id], 'transferred_to' => [(string) $b->id]]))
            ->assertOk()->assertJsonPath('outcome', 'ignored');

        expect(rcHas($a))->toBeTrue()->and(FamilyEntitlement::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('user mapping', function () {
    it('stores events of unknown users with 200 (RevenueCat would retry a 4xx forever)', function (string $appUserId) {
        rcSend(rcEvent(['app_user_id' => $appUserId]))->assertOk()->assertJsonPath('outcome', 'unknown_user');

        expect(PurchaseEvent::sole()->family_id)->toBeNull()
            ->and(FamilyEntitlement::count())->toBe(0);
    })->with(['999999', '$RCAnonymousID:8a7c', 'not-a-number', '12abc', '0', '99999999999999999999999']);

    it('never matches by e-mail and never a child', function () {
        $parent = User::factory()->parent()->create(['email' => 'mama@example.com']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        rcSend(rcEvent(['app_user_id' => 'mama@example.com']))->assertJsonPath('outcome', 'unknown_user');
        rcSend(rcEvent(['app_user_id' => (string) $child->id]))->assertJsonPath('outcome', 'unknown_user');

        expect(rcHas($parent))->toBeFalse();
    });

    it('falls back to original_app_user_id and aliases', function () {
        $p1 = User::factory()->parent()->create();
        $p2 = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => '$RCAnonymousID:1', 'original_app_user_id' => (string) $p1->id]))->assertJsonPath('outcome', 'granted');
        rcSend(rcEvent(['app_user_id' => '$RCAnonymousID:2', 'aliases' => ['$RCAnonymousID:2', (string) $p2->id]]))->assertJsonPath('outcome', 'granted');

        expect(rcHas($p1))->toBeTrue()->and(rcHas($p2))->toBeTrue();
    });

    it('shares the entitlement with every parent of the family, whoever bought it', function () {
        $first = User::factory()->parent()->create();
        $second = rcSecondParent($first);

        rcSend(rcEvent(['app_user_id' => (string) $second->id]))->assertJsonPath('outcome', 'granted');

        expect(rcHas($first))->toBeTrue()->and(rcHas($second))->toBeTrue();
        actingAsRole($first);
        getJson('/api/parent/entitlements')->assertOk()->assertJsonPath('entitlements.0.active', true);

        // The other parent buying again extends the same row.
        rcSend(rcEvent(['app_user_id' => (string) $first->id]))->assertJsonPath('outcome', 'extended');
        expect(FamilyEntitlement::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('sandbox', function () {
    it('stores SANDBOX events but grants only when accept_sandbox is on', function () {
        $parent = User::factory()->parent()->create();
        config(['services.revenuecat.accept_sandbox' => false]);

        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'environment' => 'SANDBOX']))->assertOk()->assertJsonPath('outcome', 'sandbox_ignored');
        expect(rcHas($parent))->toBeFalse()
            ->and(PurchaseEvent::sole()->environment)->toBe('SANDBOX')
            ->and(PurchaseEvent::sole()->family_id)->toBe(rcFamily($parent)->id);

        // Production events still work with the flag off.
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertJsonPath('outcome', 'granted');
        expect(rcHas($parent))->toBeTrue();

        config(['services.revenuecat.accept_sandbox' => true]);
        $other = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $other->id, 'environment' => 'SANDBOX']))->assertJsonPath('outcome', 'granted');
        expect(rcHas($other))->toBeTrue()
            ->and(FamilyEntitlement::where('family_id', rcFamily($other)->id)->sole()->environment)->toBe('SANDBOX');
    });

    it('defaults accept_sandbox to false in production and true elsewhere', function () {
        $config = fn (string $env) => (function () use ($env) {
            putenv("APP_ENV={$env}");
            $_ENV['APP_ENV'] = $env;
            $_SERVER['APP_ENV'] = $env;
            try {
                return (require config_path('services.php'))['revenuecat']['accept_sandbox'];
            } finally {
                putenv('APP_ENV=testing');
                $_ENV['APP_ENV'] = 'testing';
                $_SERVER['APP_ENV'] = 'testing';
            }
        })();

        expect($config('production'))->toBeFalse()
            ->and($config('local'))->toBeTrue()
            ->and($config('testing'))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('GET /api/parent/entitlements', function () {
    it('lists every known entitlement, inactive with nulls when the family has none', function () {
        $parent = User::factory()->parent()->create();
        actingAsRole($parent);

        getJson('/api/parent/entitlements')->assertOk()->assertExactJson([
            'entitlements' => [[
                'key' => 'challenge', 'active' => false, 'source' => null, 'store' => null, 'granted_at' => null, 'expires_at' => null,
            ]],
        ]);
    });

    it('shows the active entitlement with its store and dates', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00', ['store' => 'PLAY_STORE']));
        actingAsRole($parent);

        getJson('/api/parent/entitlements')->assertOk()->assertExactJson([
            'entitlements' => [[
                'key' => 'challenge', 'active' => true, 'source' => 'revenuecat', 'store' => 'PLAY_STORE',
                'granted_at' => '2026-10-07T10:00:00+00:00', 'expires_at' => '2026-11-07T10:00:00+00:00',
            ]],
        ]);
    });

    it('never shows another family\'s entitlement; children get 403, guests 401', function () {
        $buyer = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $buyer->id]));
        $stranger = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $buyer->id]);

        actingAsRole($stranger);
        getJson('/api/parent/entitlements')->assertOk()->assertJsonPath('entitlements.0.active', false)
            ->assertJsonPath('entitlements.0.source', null);

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/parent/entitlements')->assertForbidden();

        app('auth')->forgetGuards();
        $this->actingAsWithAbilities($child, ['*']);
        getJson('/api/parent/entitlements')->assertForbidden();

        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => ''])->getJson('/api/parent/entitlements')->assertUnauthorized();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('premium breed gate', function () {
    beforeEach(function () {
        seedLifeStageData();
    });

    /**
     * @param  array<string, mixed>  $body
     */
    function rcPin(User $parent, array $body): TestResponse
    {
        $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
        app('auth')->forgetGuards();
        actingAsRole($parent);

        return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
    }

    function rcPinLogin(string $pin): TestResponse
    {
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);

        return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet']);
    }

    it('refuses the Border Collie without the challenge entitlement', function () {
        $parent = User::factory()->parent()->create();

        rcPin($parent, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertStatus(422)->assertJsonPath('reason', 'breed_locked');
    });

    it('lets any parent of a family with the entitlement create a Border Collie', function () {
        $buyer = User::factory()->parent()->create();
        $second = rcSecondParent($buyer);
        rcSend(rcEvent(['app_user_id' => (string) $buyer->id]))->assertJsonPath('outcome', 'granted');

        $pin = rcPin($second, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])
            ->assertOk()->assertJsonPath('pet_profile.breed', 'border_collie')->json('pin');
        $login = rcPinLogin($pin)->assertSuccessful()->json();

        expect(Pet::findOrFail($login['pet']['id'])->breed_type)->toBe(BreedType::BorderCollie);
    });

    it('falls back to the mutt when the entitlement ends between the PIN and the pairing', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));
        $pin = rcPin($parent, ['breed' => 'border_collie', 'origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()->json('pin');

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'cancel_reason' => 'CUSTOMER_SUPPORT']))
            ->assertJsonPath('outcome', 'revoked');
        $login = rcPinLogin($pin)->assertSuccessful()->json();

        expect(Pet::findOrFail($login['pet']['id'])->breed_type)->toBe(BreedType::Mutt);
    });

    it('answers canUseBreed from the family entitlement; free breeds always pass', function () {
        $parent = User::factory()->parent()->create();
        $service = app(EntitlementService::class);

        expect($service->canUseBreed(rcFamily($parent), BreedType::Mutt))->toBeTrue()
            ->and($service->canUseBreed(rcFamily($parent), BreedType::BorderCollie))->toBeFalse()
            ->and($service->canUseBreed(null, BreedType::BorderCollie))->toBeFalse();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        expect($service->canUseBreed(rcFamily($parent), BreedType::BorderCollie))->toBeTrue();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('account deletion', function () {
    it('deletes the family entitlement with the family and keeps the ledger without the family link', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();

        expect(Family::count())->toBe(0)
            ->and(FamilyEntitlement::count())->toBe(0)
            ->and(PurchaseEvent::sole()->family_id)->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('Filament (read only)', function () {
    it('lists purchase events and family entitlements for a superadmin, without create / edit', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcSubEvent($parent, 'INITIAL_PURCHASE', '2026-11-07 10:00:00'));
        rcSend(rcSubEvent($parent, 'BILLING_ISSUE', '2026-11-07 10:00:00'));
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        Livewire::test(ListPurchaseEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords(PurchaseEvent::all())
            ->assertSee('BILLING_ISSUE')
            ->assertSee('recorded');
        Livewire::test(ListFamilyEntitlements::class)
            ->assertOk()
            ->assertCanSeeTableRecords(FamilyEntitlement::all())
            ->assertSee(RC_SUB);

        expect(PurchaseEventResource::canCreate())->toBeFalse()
            ->and(FamilyEntitlementResource::canCreate())->toBeFalse()
            ->and(PurchaseEventResource::getPages())->toHaveKeys(['index'])->not->toHaveKey('edit');
    });
});
