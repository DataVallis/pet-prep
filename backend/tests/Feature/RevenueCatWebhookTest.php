<?php

use App\Events\PetUpdated;
use App\Filament\Resources\ChallengeCreditResource;
use App\Filament\Resources\ChallengeCreditResource\Pages\ListChallengeCredits;
use App\Filament\Resources\PurchaseEventResource;
use App\Filament\Resources\PurchaseEventResource\Pages\ListPurchaseEvents;
use App\Models\ChallengeCredit;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PurchaseEvent;
use App\Models\User;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M3-08 / M3-11 — RevenueCat webhook → purchase_events ledger → challenge
| credits (consumable per pet, PAYMENTS_SPEC P1). Trial / lock / activate /
| billing: ChallengeTrialTest.
|--------------------------------------------------------------------------
*/

const RC_SECRET = 'rc-test-webhook-secret';
const RC_PRODUCT = 'petprep_challenge_12w';

beforeEach(function () {
    config([
        'services.revenuecat.webhook_secret' => RC_SECRET,
        'services.revenuecat.accept_sandbox' => true,
        'services.revenuecat.challenge_products' => [RC_PRODUCT],
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00', 'UTC'));
    $this->withoutMiddleware([ThrottleRequests::class]);
});

/**
 * A RevenueCat event (one purchase of the challenge consumable by default).
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
        'entitlement_ids' => null,
        'store' => 'APP_STORE',
        'environment' => 'PRODUCTION',
        'transaction_id' => 'tx-'.Str::random(10),
        'original_transaction_id' => null,
        'purchased_at_ms' => Carbon::now()->getTimestampMs(),
        'expiration_at_ms' => null,
        'event_timestamp_ms' => Carbon::now()->getTimestampMs(),
    ], $overrides);
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

/** Unrevoked credits of the parent's family. */
function rcCredits(User $parent): int
{
    return ChallengeCredit::where('family_id', rcFamily($parent)->id)->whereNull('revoked_at')->count();
}

/**
 * Parent with a child and an unpaid challenge pet (trial).
 *
 * @return array{0: User, 1: Pet}
 */
function rcParentWithPet(): array
{
    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $pet = Pet::factory()->mutt()->trial()->create(['user_id' => $child->id]);

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
            ->and(ChallengeCredit::count())->toBe(0);
    });

    it('answers 401 for a missing or wrong Authorization header, before validating the body', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), null)->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Bearer wrong')->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Bearer '.RC_SECRET.'x')->assertUnauthorized();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), 'Basic '.RC_SECRET)->assertUnauthorized();
        // Garbage body + wrong secret → 401, not a 422 that describes the schema.
        test()->withHeaders(['Authorization' => 'nope'])->postJson('/api/webhooks/revenuecat', ['x' => 1])->assertUnauthorized();

        expect(PurchaseEvent::count())->toBe(0)->and(rcCredits($parent))->toBe(0);
    });

    it('accepts "Bearer <secret>" and the bare secret', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk()->assertJsonPath('outcome', 'granted');
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]), RC_SECRET)->assertOk()->assertJsonPath('outcome', 'granted');

        expect(PurchaseEvent::count())->toBe(2)->and(rcCredits($parent))->toBe(2);
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
    it('stores every field of the event once and answers a repeat 200 without a second credit', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $pet] = rcParentWithPet();
        $event = rcEvent([
            'app_user_id' => (string) $parent->id,
            'original_app_user_id' => '$RCAnonymousID:abc',
            'aliases' => ['$RCAnonymousID:abc', (string) $parent->id],
            'entitlement_ids' => ['challenge'],
            'transaction_id' => '2000000123',
            'original_transaction_id' => '2000000123',
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
            ->and($row->purchased_at->toIso8601String())->toBe('2026-10-07T10:00:00+00:00')
            ->and($row->family_id)->toBe(rcFamily($parent)->id)
            ->and($row->outcome->value)->toBe('granted')
            ->and($row->processed_at)->not->toBeNull()
            // No customer e-mail / name in our copy of the payload.
            ->and($row->payload['event'])->not->toHaveKey('subscriber_attributes');

        $credit = ChallengeCredit::sole();
        expect($credit->purchase_event_id)->toBe($row->id)
            ->and($credit->family_id)->toBe(rcFamily($parent)->id)
            ->and($credit->product_id)->toBe(RC_PRODUCT)
            ->and($credit->transaction_id)->toBe('2000000123')
            ->and($credit->purchased_at->toIso8601String())->toBe('2026-10-07T10:00:00+00:00')
            // The family's only unpaid challenge pet got it.
            ->and($credit->pet_id)->toBe($pet->id)
            ->and($credit->assigned_via)->toBe('webhook');

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'challenge_paid');
    });

    it('keeps one credit per purchase event and one unrevoked credit per pet (DB constraints)', function () {
        [$parent, $pet] = rcParentWithPet();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk();
        $credit = ChallengeCredit::sole();

        expect(fn () => DB::transaction(fn () => ChallengeCredit::create([
            'family_id' => $credit->family_id, 'purchase_event_id' => $credit->purchase_event_id, 'product_id' => RC_PRODUCT, 'purchased_at' => now(),
        ])))->toThrow(UniqueConstraintViolationException::class);

        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertOk();
        $second = ChallengeCredit::whereNull('pet_id')->sole();
        expect(fn () => DB::transaction(fn () => $second->forceFill(['pet_id' => $pet->id, 'assigned_at' => now(), 'assigned_via' => 'parent'])->save()))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('event types', function () {
    it('INITIAL_PURCHASE of the consumable also creates a credit; another product creates none', function () {
        $parent = User::factory()->parent()->create();

        rcSend(rcEvent(['type' => 'INITIAL_PURCHASE', 'app_user_id' => (string) $parent->id]))->assertJsonPath('outcome', 'granted');
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'product_id' => 'mystery']))->assertJsonPath('outcome', 'unknown_product');
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'product_id' => null]))->assertJsonPath('outcome', 'unknown_product');

        expect(rcCredits($parent))->toBe(1);
    });

    it('a refund (CANCELLATION) revokes the purchase\'s credit by transaction id; a subscription-style one is only recorded', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-1']));
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-2']));

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-1', 'cancel_reason' => 'CUSTOMER_SUPPORT']))
            ->assertOk()->assertJsonPath('outcome', 'revoked');
        $revoked = ChallengeCredit::where('transaction_id', 'tx-1')->sole();
        expect($revoked->revoke_reason->value)->toBe('refund')
            ->and($revoked->revoke_event_id)->toBe(PurchaseEvent::where('type', 'CANCELLATION')->sole()->event_id)
            ->and(ChallengeCredit::where('transaction_id', 'tx-2')->sole()->revoked_at)->toBeNull();

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-2', 'expiration_at_ms' => now()->addMonth()->getTimestampMs()]))
            ->assertJsonPath('outcome', 'recorded');
        // A REFUND type (should RevenueCat send one) works like the cancellation.
        rcSend(rcEvent(['type' => 'REFUND', 'app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-2']))->assertJsonPath('outcome', 'revoked');
        expect(rcCredits($parent))->toBe(0);
    });

    it('without a transaction id a refund revokes the newest unused credit first', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-old', 'purchased_at_ms' => now()->subDay()->getTimestampMs()]));
        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'transaction_id' => 'tx-new']));

        rcSend(rcEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'transaction_id' => null]))->assertJsonPath('outcome', 'revoked');

        expect(ChallengeCredit::whereNotNull('revoked_at')->sole()->transaction_id)->toBe('tx-new');
    });

    it('records BILLING_ISSUE, PRODUCT_CHANGE, SUBSCRIBER_ALIAS and TEST; ignores RENEWAL, EXPIRATION and unknown types', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        foreach (['BILLING_ISSUE', 'PRODUCT_CHANGE', 'SUBSCRIBER_ALIAS', 'TEST'] as $type) {
            rcSend(rcEvent(['type' => $type, 'app_user_id' => (string) $parent->id, 'new_product_id' => 'x']))->assertOk()->assertJsonPath('outcome', 'recorded');
        }
        foreach (['RENEWAL', 'EXPIRATION', 'UNCANCELLATION', 'SUBSCRIPTION_PAUSED'] as $type) {
            rcSend(rcEvent(['type' => $type, 'app_user_id' => (string) $parent->id]))->assertOk()->assertJsonPath('outcome', 'ignored');
        }

        expect(rcCredits($parent))->toBe(1);
    });

    it('answers TEST events from the dashboard (unknown user) with 200', function () {
        rcSend(rcEvent(['type' => 'TEST', 'app_user_id' => 'dashboard-test-user']))
            ->assertOk()->assertJsonPath('outcome', 'recorded');

        expect(PurchaseEvent::sole()->family_id)->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('TRANSFER', function () {
    it('moves only the unassigned credits to the new family; an assigned one stays with its pet', function () {
        [$a, $petA] = rcParentWithPet();
        $b = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $a->id])); // auto-assigned to petA
        rcSend(rcEvent(['app_user_id' => (string) $a->id])); // unassigned

        rcSend(rcEvent([
            'type' => 'TRANSFER', 'app_user_id' => null, 'product_id' => null,
            'transferred_from' => [(string) $a->id, '$RCAnonymousID:old'],
            'transferred_to' => [(string) $b->id],
        ]))->assertOk()->assertJsonPath('outcome', 'transferred');

        $moved = ChallengeCredit::where('family_id', rcFamily($b)->id)->sole();
        expect($moved->transferred_from_family_id)->toBe(rcFamily($a)->id)
            ->and($moved->transferred_at)->not->toBeNull()
            ->and(ChallengeCredit::where('pet_id', $petA->id)->sole()->family_id)->toBe(rcFamily($a)->id)
            ->and($petA->fresh()->challenge_paid_at)->not->toBeNull()
            ->and(PurchaseEvent::where('type', 'TRANSFER')->sole()->family_id)->toBe(rcFamily($b)->id);

        // Nothing left to move → recorded.
        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => [(string) $a->id], 'transferred_to' => [(string) $b->id]]))
            ->assertJsonPath('outcome', 'recorded');
    });

    it('moves nothing to an unknown receiver; unknown on both sides → unknown_user; same family → recorded', function () {
        $a = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $a->id]));

        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => ['nobody'], 'transferred_to' => ['$RCAnonymousID:x']]))
            ->assertOk()->assertJsonPath('outcome', 'unknown_user');
        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => [(string) $a->id], 'transferred_to' => ['$RCAnonymousID:x']]))
            ->assertOk()->assertJsonPath('outcome', 'recorded');
        $b = rcSecondParent($a);
        rcSend(rcEvent(['type' => 'TRANSFER', 'app_user_id' => null, 'transferred_from' => [(string) $a->id], 'transferred_to' => [(string) $b->id]]))
            ->assertOk()->assertJsonPath('outcome', 'recorded');

        expect(rcCredits($a))->toBe(1)->and(ChallengeCredit::count())->toBe(1);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('user mapping', function () {
    it('stores events of unknown users with 200 (RevenueCat would retry a 4xx forever)', function (string $appUserId) {
        rcSend(rcEvent(['app_user_id' => $appUserId]))->assertOk()->assertJsonPath('outcome', 'unknown_user');

        expect(PurchaseEvent::sole()->family_id)->toBeNull()
            ->and(ChallengeCredit::count())->toBe(0);
    })->with(['999999', '$RCAnonymousID:8a7c', 'not-a-number', '12abc', '0', '99999999999999999999999']);

    it('never matches by e-mail and never a child', function () {
        $parent = User::factory()->parent()->create(['email' => 'mama@example.com']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);

        rcSend(rcEvent(['app_user_id' => 'mama@example.com']))->assertJsonPath('outcome', 'unknown_user');
        rcSend(rcEvent(['app_user_id' => (string) $child->id]))->assertJsonPath('outcome', 'unknown_user');

        expect(rcCredits($parent))->toBe(0);
    });

    it('falls back to original_app_user_id and aliases', function () {
        $p1 = User::factory()->parent()->create();
        $p2 = User::factory()->parent()->create();

        rcSend(rcEvent(['app_user_id' => '$RCAnonymousID:1', 'original_app_user_id' => (string) $p1->id]))->assertJsonPath('outcome', 'granted');
        rcSend(rcEvent(['app_user_id' => '$RCAnonymousID:2', 'aliases' => ['$RCAnonymousID:2', (string) $p2->id]]))->assertJsonPath('outcome', 'granted');

        expect(rcCredits($p1))->toBe(1)->and(rcCredits($p2))->toBe(1);
    });

    it('puts the credit in the family, whichever parent bought it', function () {
        $first = User::factory()->parent()->create();
        $second = rcSecondParent($first);

        rcSend(rcEvent(['app_user_id' => (string) $second->id]))->assertJsonPath('outcome', 'granted');

        expect(rcCredits($first))->toBe(1)->and(rcFamily($first)->id)->toBe(rcFamily($second)->id);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('sandbox', function () {
    it('stores SANDBOX events but creates credits only when accept_sandbox is on', function () {
        $parent = User::factory()->parent()->create();
        config(['services.revenuecat.accept_sandbox' => false]);

        rcSend(rcEvent(['app_user_id' => (string) $parent->id, 'environment' => 'SANDBOX']))->assertOk()->assertJsonPath('outcome', 'sandbox_ignored');
        expect(rcCredits($parent))->toBe(0)
            ->and(PurchaseEvent::sole()->environment)->toBe('SANDBOX')
            ->and(PurchaseEvent::sole()->family_id)->toBe(rcFamily($parent)->id);

        // Production events still work with the flag off.
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]))->assertJsonPath('outcome', 'granted');
        expect(rcCredits($parent))->toBe(1);

        config(['services.revenuecat.accept_sandbox' => true]);
        $other = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $other->id, 'environment' => 'SANDBOX']))->assertJsonPath('outcome', 'granted');
        expect(ChallengeCredit::where('family_id', rcFamily($other)->id)->sole()->environment)->toBe('SANDBOX');
    });

    it('defaults accept_sandbox to false in production and true elsewhere; the product list from env', function () {
        $config = fn (string $env) => (function () use ($env) {
            putenv("APP_ENV={$env}");
            $_ENV['APP_ENV'] = $env;
            $_SERVER['APP_ENV'] = $env;
            try {
                return (require config_path('services.php'))['revenuecat'];
            } finally {
                putenv('APP_ENV=testing');
                $_ENV['APP_ENV'] = 'testing';
                $_SERVER['APP_ENV'] = 'testing';
            }
        })();

        expect($config('production')['accept_sandbox'])->toBeFalse()
            ->and($config('local')['accept_sandbox'])->toBeTrue()
            ->and($config('testing')['challenge_products'])->toBe(['petprep_challenge_12w']);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('account deletion', function () {
    it('deletes the family\'s credits with the family and keeps the ledger without the family link', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));

        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson('/api/parent/account/delete', ['password' => 'password', 'confirm' => true])->assertOk();

        expect(Family::count())->toBe(0)
            ->and(ChallengeCredit::count())->toBe(0)
            ->and(PurchaseEvent::sole()->family_id)->toBeNull();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('Filament (read only)', function () {
    it('lists purchase events and challenge credits for a superadmin, without create / edit', function () {
        $parent = User::factory()->parent()->create();
        rcSend(rcEvent(['app_user_id' => (string) $parent->id]));
        rcSend(rcEvent(['type' => 'BILLING_ISSUE', 'app_user_id' => (string) $parent->id]));
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));

        Livewire::test(ListPurchaseEvents::class)
            ->assertOk()
            ->assertCanSeeTableRecords(PurchaseEvent::all())
            ->assertSee('BILLING_ISSUE')
            ->assertSee('recorded');
        Livewire::test(ListChallengeCredits::class)
            ->assertOk()
            ->assertCanSeeTableRecords(ChallengeCredit::all())
            ->assertSee(RC_PRODUCT);

        expect(PurchaseEventResource::canCreate())->toBeFalse()
            ->and(ChallengeCreditResource::canCreate())->toBeFalse()
            ->and(PurchaseEventResource::getPages())->toHaveKeys(['index'])->not->toHaveKey('edit');
    });
});
