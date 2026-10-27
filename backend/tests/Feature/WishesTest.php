<?php

use App\Enums\PushType;
use App\Enums\WishStatus;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\PetResource\RelationManagers\WishesRelationManager;
use App\Filament\Resources\WishCreditResource\Pages\ListWishCredits;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetItem;
use App\Models\PetWish;
use App\Models\PurchaseEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Models\WishCredit;
use App\Services\AccountDeletionService;
use App\Services\AccountExportService;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M6-W01 / W02 »Želje« (SHOP_SPEC, docs/engineering/WISHES_API.md)
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+1 on 2026-10-27 after the DST change),
| default quiet hours 21:00–07:00 local. Tests run at 10:00 UTC = 11:00 local.
*/

const WI_SECRET = 'rc-wish-secret';

beforeEach(function () {
    config([
        'services.revenuecat.webhook_secret' => WI_SECRET,
        'services.revenuecat.accept_sandbox' => true,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'UTC'));
    $this->withoutMiddleware([ThrottleRequests::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Parent + child + a born dog (paid challenge unless $factory says otherwise).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function wiFamily(?callable $factory = null, array $attributes = []): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $f = Pet::factory()->borderCollie();
    $f = $factory !== null ? $factory($f) : $f;
    $pet = $f->create(array_merge(['user_id' => $child->id, 'born_at' => now()->subDay(), 'last_decay_at' => now()], $attributes));
    disableHygieneEvents($pet);

    return [$parent, $child, $pet->fresh()];
}

function wiAs(User $user): void
{
    app('auth')->forgetGuards();
    actingAsRole($user);
}

function wiWish(User $child, string $key): TestResponse
{
    wiAs($child);

    return postJson('/api/child/wishes', ['item_key' => $key]);
}

/** @param  array<string, mixed>  $overrides */
function wiEvent(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'type' => 'NON_RENEWING_PURCHASE',
        'app_user_id' => null,
        'aliases' => [],
        'product_id' => 'petprep_wish_bed',
        'store' => 'APP_STORE',
        'environment' => 'PRODUCTION',
        'transaction_id' => 'tx-'.Str::random(10),
        'purchased_at_ms' => Carbon::now()->getTimestampMs(),
        'expiration_at_ms' => null,
        'event_timestamp_ms' => Carbon::now()->getTimestampMs(),
    ], $overrides);
}

function wiSend(array $event): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.WI_SECRET])
        ->postJson('/api/webhooks/revenuecat', ['api_version' => '1.0', 'event' => $event]);
}

/** A purchase of $product by $parent through the webhook; returns the event. */
function wiBuy(User $parent, string $product = 'petprep_wish_bed', array $overrides = []): array
{
    $event = wiEvent(array_merge(['app_user_id' => (string) $parent->id, 'product_id' => $product], $overrides));
    wiSend($event)->assertOk();

    return $event;
}

function wiRedeem(User $parent, Pet $pet, string $product, ?int $wishId = null, ?string $key = null): TestResponse
{
    wiAs($parent);
    $headers = $key !== null ? ['Idempotency-Key' => $key] : [];

    return test()->withHeaders($headers)->postJson("/api/parent/pets/{$pet->id}/items/redeem", array_filter(['product_id' => $product, 'wish_id' => $wishId]));
}

function wiFamilyOf(User $parent): Family
{
    return app(FamilyService::class)->familyOf($parent);
}

// ─────────────────────────────────────────────────────────────────────────
describe('catalogue', function () {
    it('seeds six shop items and lists the child catalogue without the bundle, by species', function () {
        [, $child] = wiFamily();
        wiAs($child);
        getJson('/api/child/wishes')->assertOk()
            ->assertJsonPath('species', 'dog')
            ->assertJsonPath('open_count', 0)
            ->assertJsonPath('open_limit', 3)
            ->assertJsonPath('can_wish', true)
            ->assertJsonMissingPath('blocked_reason')
            ->assertJsonPath('items.0', ['key' => 'bed', 'kind' => 'durable', 'variant' => 'dog_bed', 'status' => 'available'])
            ->assertJsonPath('items.2.variant', 'dog_rope')
            ->assertJsonPath('items.3.variant', 'dog_squeaky')
            ->assertJsonPath('items.4', ['key' => 'treats', 'kind' => 'consumable', 'variant' => 'dog_treats', 'status' => 'available', 'treats_left' => 0])
            ->assertJsonCount(5, 'items');
    });

    it('uses the cat variants for a cat', function () {
        seedBreedConfigs();
        [, $child] = wiFamily(fn ($f) => $f->siberianCat());
        wiAs($child);
        getJson('/api/child/wishes')->assertOk()
            ->assertJsonPath('species', 'cat')
            ->assertJsonPath('items.0.variant', 'cat_basket')
            ->assertJsonPath('items.2.variant', 'cat_mouse')
            ->assertJsonPath('items.3.variant', 'cat_feather');
    });

    it('answers 404 no_pet for a child without a pet', function () {
        $child = User::factory()->child()->create();
        wiAs($child);
        getJson('/api/child/wishes')->assertNotFound()->assertJsonPath('reason', 'no_pet');
    });

    it('refuses a parent token on the child route and a child on the parent route', function () {
        [$parent, $child] = wiFamily();
        wiAs($parent);
        getJson('/api/child/wishes')->assertForbidden();
        wiAs($child);
        getJson('/api/parent/wishes')->assertForbidden();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('child wishes', function () {
    it('creates a wish, lists it as requested, shows it to the parent and on the dashboard', function () {
        [$parent, $child, $pet] = wiFamily();
        $res = wiWish($child, 'bed')->assertCreated()
            ->assertJsonPath('wish.item_key', 'bed')
            ->assertJsonPath('wish.status', 'open')
            ->assertJsonPath('open_count', 1);
        $id = $res->json('wish.id');

        wiAs($child);
        getJson('/api/child/wishes')->assertJsonPath('items.0.status', 'requested')->assertJsonPath('items.0.wish_id', $id);
        getJson('/api/child/pet')->assertJsonPath('wishes.open_count', 1)
            ->assertJsonPath('wishes.items', [])
            ->assertJsonPath('wishes.treats', ['left' => 0, 'given_today' => 0, 'daily_limit' => 2]);

        wiAs($parent);
        getJson('/api/parent/wishes')->assertOk()
            ->assertJsonPath('open_count', 1)
            ->assertJsonPath('wishes.0.id', $id)
            ->assertJsonPath('wishes.0.pet_id', $pet->id)
            ->assertJsonPath('wishes.0.child_id', $child->id)
            ->assertJsonPath('wishes.0.child_name', $child->name)
            ->assertJsonPath('wishes.0.variant', 'dog_bed')
            ->assertJsonPath('wishes.0.product_id', 'petprep_wish_bed')
            ->assertJsonPath('wishes.0.bundle_product_id', 'petprep_wish_bundle_welcome')
            ->assertJsonPath('pets.0.pet_id', $pet->id)
            ->assertJsonPath('pets.0.bundle_eligible', true)
            ->assertJsonPath('pets.0.items.0', ['key' => 'bed', 'variant' => 'dog_bed', 'product_id' => 'petprep_wish_bed', 'owned' => false, 'media_ready' => false])
            ->assertJsonPath('pets.0.items.4', ['key' => 'treats', 'variant' => 'dog_treats', 'product_id' => 'petprep_wish_treats_10', 'treats_left' => 0])
            ->assertJsonPath('unassigned_credits', []);
        getJson('/api/parent/dashboard')->assertJsonPath('wishes_open_count', 1);
    });

    it('has no bundle offer for toy_2', function () {
        [$parent, $child] = wiFamily();
        wiWish($child, 'toy_2')->assertCreated();
        wiAs($parent);
        getJson('/api/parent/wishes')->assertJsonPath('wishes.0.bundle_product_id', null);
    });

    it('refuses unknown items, the bundle, a second open wish and more than 3 open wishes', function () {
        [, $child] = wiFamily();
        wiWish($child, 'pony')->assertStatus(422)->assertJsonPath('reason', 'unknown_item');
        wiWish($child, 'welcome_bundle')->assertStatus(422)->assertJsonPath('reason', 'bundle_not_wishable');
        wiWish($child, 'bed')->assertCreated();
        wiWish($child, 'bed')->assertStatus(422)->assertJsonPath('reason', 'already_requested');
        wiWish($child, 'bowls')->assertCreated();
        wiWish($child, 'toy_1')->assertCreated();
        wiWish($child, 'toy_2')->assertStatus(422)->assertJsonPath('reason', 'wish_limit_reached');
        wiWish($child, '')->assertStatus(422);
    });

    it('refuses a durable the pet already owns', function () {
        [$parent, $child, $pet] = wiFamily();
        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed')->assertOk();
        wiWish($child, 'bed')->assertStatus(422)->assertJsonPath('reason', 'already_owned');
        wiAs($child);
        getJson('/api/child/wishes')->assertJsonPath('items.0.status', 'owned')->assertJsonPath('items.0.media_ready', false);
    });

    it('allows treats only while 2 or fewer are left', function () {
        [$parent, $child, $pet] = wiFamily();
        wiBuy($parent, 'petprep_wish_treats_10');
        wiRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        wiWish($child, 'treats')->assertStatus(422)->assertJsonPath('reason', 'treats_still_left');
        PetItem::where('pet_id', $pet->id)->update(['quantity_left' => 2]);
        wiWish($child, 'treats')->assertCreated();
    });

    it('lets the child ask again 7 days after a decline', function () {
        [$parent, $child] = wiFamily();
        $id = wiWish($child, 'bed')->json('wish.id');
        wiAs($parent);
        postJson("/api/parent/wishes/{$id}/decline")->assertOk()
            ->assertJsonPath('wish', ['id' => $id, 'status' => 'declined'])
            ->assertJsonPath('open_count', 0);
        postJson("/api/parent/wishes/{$id}/decline")->assertStatus(422)->assertJsonPath('reason', 'wish_not_open');

        wiWish($child, 'bed')->assertStatus(422)->assertJsonPath('reason', 'declined_recently')
            ->assertJsonPath('retry_at', '2026-11-03T11:00:00+01:00');
        wiAs($child);
        getJson('/api/child/wishes')->assertJsonPath('items.0.status', 'declined_recently')
            ->assertJsonPath('items.0.retry_at', '2026-11-03T11:00:00+01:00');

        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:01', 'UTC'));
        wiWish($child, 'bed')->assertCreated();
    });

    it('does not let a parent decline another family\'s wish (404)', function () {
        [, $child] = wiFamily();
        [$other] = wiFamily();
        $id = wiWish($child, 'bed')->json('wish.id');
        wiAs($other);
        postJson("/api/parent/wishes/{$id}/decline")->assertNotFound();
        postJson('/api/parent/wishes/abc/decline')->assertNotFound();
    });

    it('works on the free plan and while hard-stopped, but not after game over or with payment_required', function () {
        [, $child] = wiFamily(fn ($f) => $f->freePlan());
        wiWish($child, 'bed')->assertCreated();

        [, $child2, $pet2] = wiFamily(null, ['is_hard_stopped' => true]);
        wiWish($child2, 'bed')->assertCreated();

        [$parent3, $child3, $pet3] = wiFamily();
        $open = wiWish($child3, 'bowls')->json('wish.id');
        $pet3->update(['is_game_over' => true, 'is_active' => false]);
        wiWish($child3, 'bed')->assertStatus(423)->assertJsonPath('reason', 'game_over');
        expect(PetWish::find($open)->status)->toBe(WishStatus::Cancelled);
        wiAs($child3);
        getJson('/api/child/wishes')->assertOk()->assertJsonPath('can_wish', false)->assertJsonPath('blocked_reason', 'game_over');
        wiAs($parent3);
        getJson('/api/parent/wishes')->assertJsonPath('pets', [])->assertJsonPath('open_count', 0);

        config(['payments.enforced' => true]);
        [, $child4] = wiFamily(fn ($f) => $f->trial(), ['trial_ends_at' => now()->subDay()]);
        wiWish($child4, 'bed')->assertStatus(423)->assertJsonPath('reason', 'payment_required');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('wish push to the parents', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
    });

    function wiFakeExpo(): ArrayObject
    {
        $sent = new ArrayObject;
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        }]);

        return $sent;
    }

    function wiDevice(User $user, string $locale): DevicePushToken
    {
        return DevicePushToken::create([
            'user_id' => $user->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => $locale, 'last_seen_at' => now(),
        ]);
    }

    function wiDeliver(PushNotification $row): void
    {
        (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));
    }

    it('sends the formal SL / EN text to the parents only, title = pet name, wish id in the data', function () {
        Queue::fake([SendPushNotification::class]);
        $sent = wiFakeExpo();
        [$parent, $child, $pet] = wiFamily(null, ['name' => 'Suki']);
        wiDevice($parent, 'sl');
        wiDevice($child, 'sl');
        $second = User::factory()->parent()->create();
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);
        wiDevice($second, 'en');

        $id = wiWish($child, 'bed')->json('wish.id');
        $row = PushNotification::where('type', 'wish_created')->sole();
        expect($row->metric)->toBe((string) $id)->and(collect($row->recipients)->pluck('audience')->unique()->all())->toBe(['parent']);
        wiDeliver($row);

        $bodies = collect($sent->getArrayCopy())->pluck('body')->sort()->values()->all();
        expect($bodies)->toBe([
            'Vaš otrok si želi posteljo.',
            'Your child is wishing for a bed.',
        ]);
        foreach ($sent as $m) {
            expect($m['title'])->toBe('Suki')
                ->and($m['data'])->toBe(['type' => 'wish_created', 'pet_id' => $pet->id, 'wish_id' => $id])
                ->and($m['body'])->not->toContain('Suki')->not->toContain($child->name);
        }
    });

    it('names each item in the accusative (dog + cat)', function (string $variant, string $sl, string $en) {
        expect(PushCopy::body(PushType::WishCreated, '1', 'parent', 'sl', $variant))->toBe("Vaš otrok si želi {$sl}.")
            ->and(PushCopy::body(PushType::WishCreated, '1', 'parent', 'en', $variant))->toBe("Your child is wishing for {$en}.");
    })->with([
        ['dog_bed', 'posteljo', 'a bed'], ['cat_basket', 'košaro', 'a basket'], ['dog_bowls', 'skledi', 'bowls'],
        ['dog_rope', 'vrv za vlečenje', 'a rope toy'], ['dog_squeaky', 'piskajočo igračo', 'a squeaky toy'],
        ['cat_mouse', 'plišasto miško', 'a plush mouse'], ['cat_feather', 'palico s peresi', 'a feather wand'],
        ['dog_treats', 'priboljške', 'treats'], ['cat_treats', 'priboljške', 'treats'],
    ]);

    it('sends at most one wish push per child per 10 minutes', function () {
        Queue::fake([SendPushNotification::class]);
        [, $child] = wiFamily();
        wiWish($child, 'bed')->assertCreated();
        Carbon::setTestNow(now()->addMinutes(5));
        wiWish($child, 'bowls')->assertCreated();
        expect(PushNotification::where('type', 'wish_created')->count())->toBe(1);
        Carbon::setTestNow(now()->addMinutes(6));
        wiWish($child, 'toy_1')->assertCreated();
        expect(PushNotification::where('type', 'wish_created')->count())->toBe(2);
    });

    it('holds the push over quiet hours and drops it when the wish was decided meanwhile', function () {
        Queue::fake([SendPushNotification::class]);
        $sent = wiFakeExpo();
        Carbon::setTestNow(Carbon::parse('2026-10-27 22:00:00', 'UTC')); // 23:00 local
        [$parent, $child] = wiFamily();
        wiDevice($parent, 'sl');
        $id = wiWish($child, 'bed')->json('wish.id');
        $row = PushNotification::where('type', 'wish_created')->sole();
        expect($row->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($row->send_after->toIso8601String())->toBe('2026-10-28T06:00:00+00:00');

        wiAs($parent);
        postJson("/api/parent/wishes/{$id}/decline")->assertOk();
        $row->forceFill(['status' => PushNotification::STATUS_QUEUED])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-28 06:01:00', 'UTC'));
        wiDeliver($row);
        expect($row->fresh()->status)->toBe(PushNotification::STATUS_SUPPRESSED)
            ->and($row->fresh()->suppressed_reason)->toBe('already_decided')
            ->and($sent->count())->toBe(0);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('treats', function () {
    it('gives max 2 treats per family-local day and resets at local midnight', function () {
        [$parent, $child, $pet] = wiFamily();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'no_treats');

        wiBuy($parent, 'petprep_wish_treats_10');
        wiRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertOk()
            ->assertJsonPath('treats_left', 9)->assertJsonPath('given_today', 1)->assertJsonPath('daily_limit', 2)
            ->assertJsonPath('media', null)
            ->assertJsonPath('state.wishes.treats', ['left' => 9, 'given_today' => 1, 'daily_limit' => 2]);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 8)->assertJsonPath('given_today', 2);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'treat_limit_today')
            ->assertJsonPath('next_at', '2026-10-28T00:00:00+01:00');

        Carbon::setTestNow(Carbon::parse('2026-10-28 08:00:00', 'UTC')); // 09:00 local
        $pet->forceFill(['last_decay_at' => now()])->saveQuietly();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertOk()->assertJsonPath('treats_left', 7)->assertJsonPath('given_today', 1);
    });

    it('refuses while the pet sleeps (quiet hours) and answers 423 while locked', function () {
        [$parent, $child, $pet] = wiFamily();
        wiBuy($parent, 'petprep_wish_treats_10');
        wiRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-27 21:00:00', 'UTC')); // 22:00 local
        $pet->forceFill(['last_decay_at' => now()])->saveQuietly();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertStatus(422)->assertJsonPath('reason', 'pet_sleeping')
            ->assertJsonPath('next_at', '2026-10-28T07:00:00+01:00');

        Carbon::setTestNow(Carbon::parse('2026-10-28 10:00:00', 'UTC'));
        $pet->refresh()->update(['is_hard_stopped' => true]);
        wiAs($child);
        postJson('/api/child/pet/treat')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        $pet->refresh()->update(['is_hard_stopped' => false, 'is_game_over' => true, 'is_active' => false]);
        wiAs($child);
        postJson('/api/child/pet/treat')->assertStatus(423)->assertJsonPath('reason', 'game_over');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('webhook (W02)', function () {
    it('grants one wish credit per purchase, ignores a duplicate, stays unassigned', function () {
        [$parent] = wiFamily();
        $event = wiBuy($parent);
        wiSend($event)->assertOk();
        $credit = WishCredit::sole();
        expect($credit->item_key)->toBe('bed')->and($credit->pet_id)->toBeNull()->and($credit->family_id)->toBe(wiFamilyOf($parent)->id)
            ->and(PurchaseEvent::where('event_id', $event['id'])->value('outcome')?->value)->toBe('granted');

        wiAs($parent);
        getJson('/api/parent/wishes')->assertJsonPath('unassigned_credits', [['product_id' => 'petprep_wish_bed', 'count' => 1]]);
    });

    it('stores an unknown product and a sandbox event (not accepted) without a credit', function () {
        [$parent] = wiFamily();
        wiBuy($parent, 'petprep_wish_pony');
        expect(PurchaseEvent::latest('id')->value('outcome')?->value)->toBe('unknown_product');
        config(['services.revenuecat.accept_sandbox' => false]);
        wiBuy($parent, 'petprep_wish_bed', ['environment' => 'SANDBOX']);
        expect(PurchaseEvent::latest('id')->value('outcome')?->value)->toBe('sandbox_ignored')
            ->and(WishCredit::count())->toBe(0);
    });

    it('a refund of an assigned durable revokes the credit and removes the item; the wish stays bought', function () {
        [$parent, $child, $pet] = wiFamily();
        $id = wiWish($child, 'bed')->json('wish.id');
        $buy = wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed', $id)->assertOk();
        expect(PetWish::find($id)->status)->toBe(WishStatus::Bought);

        wiSend(wiEvent(['type' => 'REFUND', 'app_user_id' => (string) $parent->id, 'transaction_id' => $buy['transaction_id']]))->assertOk();
        expect(PurchaseEvent::latest('id')->value('outcome')?->value)->toBe('revoked')
            ->and(WishCredit::sole()->revoked_at)->not->toBeNull()
            ->and(PetItem::sole()->revoked_at)->not->toBeNull()
            ->and(PetWish::find($id)->status)->toBe(WishStatus::Bought);
        wiAs($child);
        getJson('/api/child/wishes')->assertJsonPath('items.0.status', 'available');
    });

    it('a refund of a treat pack removes its unused treats', function () {
        [$parent, $child, $pet] = wiFamily();
        $buy = wiBuy($parent, 'petprep_wish_treats_10');
        wiRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertOk();
        wiSend(wiEvent(['type' => 'CANCELLATION', 'app_user_id' => (string) $parent->id, 'product_id' => 'petprep_wish_treats_10', 'transaction_id' => $buy['transaction_id']]))->assertOk();
        expect(PetItem::sole()->quantity_left)->toBe(0);
        wiAs($child);
        getJson('/api/child/pet')->assertJsonPath('wishes.treats.left', 0);
    });

    it('moves unassigned wish credits on TRANSFER, assigned ones stay', function () {
        [$parent, , $pet] = wiFamily();
        wiBuy($parent);
        wiBuy($parent, 'petprep_wish_bowls');
        wiRedeem($parent, $pet, 'petprep_wish_bowls')->assertOk();
        $other = User::factory()->parent()->create();
        wiSend(wiEvent(['type' => 'TRANSFER', 'product_id' => null, 'transferred_from' => [(string) $parent->id], 'transferred_to' => [(string) $other->id]]))->assertOk();

        expect(PurchaseEvent::latest('id')->value('outcome')?->value)->toBe('transferred')
            ->and(WishCredit::where('product_id', 'petprep_wish_bed')->value('family_id'))->toBe(wiFamilyOf($other)->id)
            ->and(WishCredit::where('product_id', 'petprep_wish_bowls')->value('family_id'))->toBe(wiFamilyOf($parent)->id);
    });

    it('reads products from REVENUECAT_WISH_PRODUCTS-style config pairs', function () {
        config(['services.revenuecat.wish_products' => ['custom_bed' => 'bed']]);
        [$parent, , $pet] = wiFamily();
        wiBuy($parent, 'custom_bed');
        wiRedeem($parent, $pet, 'custom_bed')->assertOk();
        wiAs($parent);
        getJson('/api/parent/wishes')->assertJsonPath('pets.0.items.0.product_id', 'custom_bed')
            ->assertJsonPath('pets.0.items.1.product_id', 'petprep_wish_bowls');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('redeem', function () {
    it('answers 409 no_credit before the webhook and 200 after it; marks the wish bought', function () {
        [$parent, $child, $pet] = wiFamily();
        $id = wiWish($child, 'bed')->json('wish.id');
        wiRedeem($parent, $pet, 'petprep_wish_bed', $id)->assertStatus(409)->assertJsonPath('reason', 'no_credit');
        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed', $id)->assertOk()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('items.0', ['key' => 'bed', 'variant' => 'dog_bed', 'product_id' => 'petprep_wish_bed', 'owned' => true, 'media_ready' => false])
            ->assertJsonPath('open_count', 0);
        expect(PetWish::find($id)->status)->toBe(WishStatus::Bought)
            ->and(PetWish::find($id)->decided_by_user_id)->toBe($parent->id);
        wiAs($child);
        getJson('/api/child/pet')->assertJsonPath('wishes.items', [['key' => 'bed', 'variant' => 'dog_bed', 'media_ready' => false]]);
    });

    it('is idempotent with Idempotency-Key and refuses a durable the pet owns (the credit stays unused)', function () {
        [$parent, , $pet] = wiFamily();
        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed', null, 'tx-1')->assertOk()->assertJsonPath('status', 'assigned');
        wiRedeem($parent, $pet, 'petprep_wish_bed', null, 'tx-1')->assertOk()->assertJsonPath('status', 'already_assigned');
        expect(PetItem::count())->toBe(1);

        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed', null, 'tx-2')->assertStatus(422)->assertJsonPath('reason', 'already_owned');
        expect(WishCredit::available()->count())->toBe(1);
    });

    it('validates product, pet and wish', function () {
        [$parent, $child, $pet] = wiFamily();
        [$other, , $otherPet] = wiFamily();
        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_pony')->assertStatus(422)->assertJsonPath('reason', 'unknown_product');
        wiRedeem($parent, $otherPet, 'petprep_wish_bed')->assertNotFound()->assertJsonPath('reason', 'pet_not_found');
        $toy = wiWish($child, 'toy_2')->json('wish.id');
        wiRedeem($parent, $pet, 'petprep_wish_bed', $toy)->assertStatus(422)->assertJsonPath('reason', 'wish_mismatch');
        $pet->update(['is_game_over' => true, 'is_active' => false]);
        wiRedeem($parent, $pet, 'petprep_wish_bed')->assertStatus(422)->assertJsonPath('reason', 'pet_not_active');
        wiAs($parent);
        postJson("/api/parent/pets/{$pet->id}/items/redeem", [])->assertStatus(422);
    });

    it('the bundle gives bed + bowls + toy_1, cancels other open wishes it covers, and is refused once one is owned', function () {
        [$parent, $child, $pet] = wiFamily();
        $bed = wiWish($child, 'bed')->json('wish.id');
        $bowls = wiWish($child, 'bowls')->json('wish.id');
        $toy2 = wiWish($child, 'toy_2')->json('wish.id');
        wiBuy($parent, 'petprep_wish_bundle_welcome');
        wiRedeem($parent, $pet, 'petprep_wish_bundle_welcome', $bed)->assertOk()
            ->assertJsonPath('items.0.owned', true)->assertJsonPath('items.1.owned', true)
            ->assertJsonPath('items.2.owned', true)->assertJsonPath('items.3.owned', false)
            ->assertJsonPath('open_count', 1);
        expect(PetWish::find($bed)->status)->toBe(WishStatus::Bought)
            ->and(PetWish::find($bowls)->status)->toBe(WishStatus::Cancelled)
            ->and(PetWish::find($toy2)->status)->toBe(WishStatus::Open);

        wiBuy($parent, 'petprep_wish_bundle_welcome');
        wiRedeem($parent, $pet, 'petprep_wish_bundle_welcome')->assertStatus(422)->assertJsonPath('reason', 'bundle_not_eligible');
        wiAs($parent);
        getJson('/api/parent/wishes')->assertJsonPath('pets.0.bundle_eligible', false);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('privacy and admin', function () {
    it('exports wishes, items, treats and purchases; deleting the family removes wishes / items, credits keep no pet', function () {
        [$parent, $child, $pet] = wiFamily();
        wiWish($child, 'toy_2')->assertCreated();
        wiBuy($parent, 'petprep_wish_treats_10');
        wiRedeem($parent, $pet, 'petprep_wish_treats_10')->assertOk();
        wiAs($child);
        postJson('/api/child/pet/treat')->assertOk();

        $export = app(AccountExportService::class)->exportFor($parent);
        expect($export['pets'][0]['wishes'][0]['item_key'])->toBe('toy_2')
            ->and($export['pets'][0]['items'][0]['quantity_left'])->toBe(9)
            ->and($export['pets'][0]['treats_given'][0]['child_id'])->toBe($child->id)
            ->and($export['wish_purchases'][0]['product_id'])->toBe('petprep_wish_treats_10');

        // A single pet deletion: wishes / items / treats cascade, the credit stays used with pet_id null.
        $pet->delete();
        expect(PetWish::count())->toBe(0)->and(PetItem::count())->toBe(0)
            ->and(WishCredit::sole()->pet_id)->toBeNull()->and(WishCredit::sole()->assigned_at)->not->toBeNull();

        app(AccountDeletionService::class)->deleteFamily(wiFamilyOf($parent));
        expect(WishCredit::count())->toBe(0)->and(PurchaseEvent::count())->toBe(1);
    });

    it('shows wish credits and the pet\'s wishes / items read-only in /admin', function () {
        [$parent, $child, $pet] = wiFamily();
        wiWish($child, 'toy_2')->assertCreated();
        wiBuy($parent);
        wiRedeem($parent, $pet, 'petprep_wish_bed')->assertOk();
        $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
        actingAs($admin);

        Livewire::test(ListWishCredits::class)->assertOk()->assertCanSeeTableRecords(WishCredit::all());
        Livewire::test(WishesRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertOk()->assertCanSeeTableRecords(PetWish::all());
        Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $pet, 'pageClass' => EditPet::class])
            ->assertOk()->assertCanSeeTableRecords(PetItem::all());
    });
});
