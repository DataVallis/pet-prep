<?php

use App\Enums\PushType;
use App\Jobs\CheckPushReceipts;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\PushTicket;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ChildProfileService;
use App\Services\EscalationService;
use App\Services\FamilyInviteService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\ExpoPushException;
use App\Services\Push\PushCopy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M3-02 — Expo push notifications for escalation phases + parent alarm
|--------------------------------------------------------------------------
*/

const PN_SEND = 'https://exp.host/--/api/v2/push/send';
const PN_RECEIPTS = 'https://exp.host/--/api/v2/push/getReceipts';

const PN_NOW = '2026-10-14 10:00:00'; // 12:00 in Ljubljana, a Wednesday

function pnToken(string $suffix = ''): string
{
    return 'ExponentPushToken['.($suffix !== '' ? $suffix : Str::random(22)).']';
}

/**
 * Parent "Mama Ana" + child "Maja" caring for a born pet (no quiet hours),
 * at a fixed weekday noon in Ljubljana.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function pnFamily(array $petAttributes = []): array
{
    config(['push.enabled' => true]);

    $parent = User::factory()->parent()->create(['name' => 'Mama Ana', 'timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'hunger_level' => 100,
        'thirst_level' => 100,
        'energy_level' => 100,
        'hygiene_level' => 100,
        'escalation_level' => 0,
    ], $petAttributes));

    return [$parent, $child, disableHygieneEvents($pet)];
}

/**
 * A registered install. Default language 'sl' = an install from before M1-18
 * (backfilled), so the copy assertions below stay Slovenian; pass null for an
 * install that never stated a language.
 */
function pnDevice(User $user, ?string $token = null, string $platform = 'ios', ?string $locale = 'sl'): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => $token ?? pnToken(),
        'platform' => $platform,
        'app_version' => '1.0.0',
        'locale' => $locale,
        'last_seen_at' => now(),
    ]);
}

/**
 * Fake Expo: one ticket per message ($ticketFor decides it, default ok).
 * Every sent message is collected in the returned ArrayObject.
 */
function pnFakeExpo(?Closure $ticketFor = null, ?Closure $receipts = null): ArrayObject
{
    $sent = new ArrayObject;
    pnResetHttp();
    Http::fake([
        PN_SEND => function (Request $request) use ($ticketFor, $sent) {
            $tickets = [];
            foreach ($request->data() as $i => $message) {
                $sent->append($message);
                $tickets[] = $ticketFor ? $ticketFor($message, $i) : ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        },
        PN_RECEIPTS => function (Request $request) use ($receipts) {
            $data = [];
            foreach ($request->data()['ids'] as $id) {
                $data[$id] = $receipts ? $receipts($id) : ['status' => 'ok'];
            }

            return Http::response(['data' => $data]);
        },
    ]);

    return $sent;
}

/** Fresh HTTP fake (stubs of an earlier Http::fake() would win otherwise). */
function pnResetHttp(): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
}

function pnQueued(Pet $pet, PushType $type = PushType::SoftWarning, string $metric = 'hunger'): PushNotification
{
    // A phase 1 / 2 reminder is only delivered while its metric is still low (m2).
    if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true) && $metric !== 'energy') {
        Pet::whereKey($pet->id)->update([$metric.'_level' => 5]);
    }
    Queue::fake([SendPushNotification::class]);
    $row = app(NotificationService::class)->escalation($pet, $type, $metric);
    Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->pushNotificationId === $row->id);

    return $row;
}

function pnRun(PushNotification $row): void
{
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));
}

function pnEscalate(Pet $pet): void
{
    app(EscalationService::class)->processPetEscalation($pet);
}

/** @return list<string> */
function pnRecipientsOf(ArrayObject $sent): array
{
    return collect($sent->getArrayCopy())->pluck('to')->sort()->values()->all();
}

// Fixed clock before any test builds its attributes (now()->subHours(…)).
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse(PN_NOW, 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('POST /api/devices', function () {
    it('registers a parent and a child install', function () {
        [$parent, $child] = pnFamily();
        $parentToken = pnToken('parentAAAAAAAAAAAAAAAA');
        $childToken = pnToken('childBBBBBBBBBBBBBBBBB');

        actingAsRole($parent);
        $this->postJson('/api/devices', ['expo_push_token' => $parentToken, 'platform' => 'ios', 'app_version' => '1.2.0 (57)'])
            ->assertOk()
            ->assertJsonPath('device.platform', 'ios')
            ->assertJsonPath('device.enabled', true)
            ->assertJsonMissingPath('device.expo_push_token');

        app('auth')->forgetGuards();
        actingAsRole($child);
        $this->postJson('/api/devices', ['expo_push_token' => $childToken, 'platform' => 'android'])
            ->assertOk();

        expect(DevicePushToken::where('expo_push_token', $parentToken)->value('user_id'))->toBe($parent->id)
            ->and(DevicePushToken::where('expo_push_token', $childToken)->first())
            ->user_id->toBe($child->id)
            ->platform->value->toBe('android')
            ->app_version->toBeNull();
    });

    it('upserts the same install: one row, refreshed, re-enabled, moved to the account that registered last', function () {
        [$parent, $child] = pnFamily();
        $token = pnToken('sharedPhoneCCCCCCCCCCC');
        $device = pnDevice($child, $token);
        $device->forceFill(['disabled_at' => now()->subDay(), 'disabled_reason' => 'DeviceNotRegistered', 'last_seen_at' => now()->subWeek()])->save();

        actingAsRole($parent);
        $this->postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios', 'app_version' => '2.0.0'])
            ->assertOk()
            ->assertJsonPath('device.enabled', true);

        expect(DevicePushToken::where('expo_push_token', $token)->count())->toBe(1)
            ->and(DevicePushToken::where('expo_push_token', $token)->first())
            ->user_id->toBe($parent->id)
            ->app_version->toBe('2.0.0')
            ->disabled_at->toBeNull()
            ->disabled_reason->toBeNull()
            ->last_seen_at->toEqual(now());
    });

    it('validates the Expo token format, platform and app version', function (array $body) {
        [$parent] = pnFamily();
        actingAsRole($parent);

        $this->postJson('/api/devices', $body)->assertUnprocessable();
        expect(DevicePushToken::count())->toBe(0);
    })->with([
        'missing token' => [['platform' => 'ios']],
        'raw FCM token' => [['expo_push_token' => 'fcm:abcdefghijklmnopqrstuvwxyz', 'platform' => 'android']],
        'no brackets' => [['expo_push_token' => 'ExponentPushToken-abcdefghijkl', 'platform' => 'ios']],
        'script in token' => [['expo_push_token' => 'ExponentPushToken[<script>alert(1)</script>]', 'platform' => 'ios']],
        'unknown platform' => [['expo_push_token' => 'ExponentPushToken[abcdefghijklmnop]', 'platform' => 'web']],
        'free-text version' => [['expo_push_token' => 'ExponentPushToken[abcdefghijklmnop]', 'platform' => 'ios', 'app_version' => "Maja's iPhone <3"]],
    ]);

    it('needs a signed-in user', function () {
        $this->postJson('/api/devices', ['expo_push_token' => pnToken(), 'platform' => 'ios'])->assertUnauthorized();
    });
});

describe('POST /api/devices/unregister and device removal', function () {
    it('removes only the caller\'s own registration and is idempotent (token in the body)', function () {
        [$parent, $child] = pnFamily();
        $own = pnDevice($parent, pnToken('ownDDDDDDDDDDDDDDDDDDD'));
        $childs = pnDevice($child, pnToken('childEEEEEEEEEEEEEEEEE'));

        actingAsRole($parent);
        $this->postJson('/api/devices/unregister', ['expo_push_token' => $own->expo_push_token])->assertNoContent();
        $this->postJson('/api/devices/unregister', ['expo_push_token' => $own->expo_push_token])->assertNoContent();
        $this->postJson('/api/devices/unregister', ['expo_push_token' => $childs->expo_push_token])->assertNoContent();
        $this->postJson('/api/devices/unregister', ['expo_push_token' => 'not-a-token'])->assertUnprocessable();

        expect(DevicePushToken::find($own->id))->toBeNull()
            ->and(DevicePushToken::find($childs->id))->not->toBeNull();
    });

    it('no longer accepts the token in a DELETE path', function () {
        [$parent] = pnFamily();
        $own = pnDevice($parent);
        actingAsRole($parent);

        expect($this->deleteJson('/api/devices/'.rawurlencode($own->expo_push_token))->status())->toBeIn([404, 405]);
        expect(DevicePushToken::find($own->id))->not->toBeNull();
    });

    it('logs a move to another account with user ids only, never the token', function () {
        [$parent, $child] = pnFamily();
        $token = pnToken('movedMMMMMMMMMMMMMMMMMM');
        $device = pnDevice($child, $token);
        Log::spy();

        actingAsRole($parent);
        $this->postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'Push: device moved to another account'
            && $context === ['device_push_token_id' => $device->id, 'from_user_id' => $child->id, 'to_user_id' => $parent->id])->once();
        Log::shouldNotHaveReceived('info', [Mockery::on(fn ($m) => is_string($m) && str_contains($m, $token)), Mockery::any()]);
    });

    it('drops the push device with the Sanctum token on logout', function () {
        [$parent] = pnFamily();
        $plain = $parent->createToken('phone', ['parent'])->plainTextToken;
        $token = pnToken('logoutFFFFFFFFFFFFFFFFF');

        $this->withToken($plain)->postJson('/api/devices', ['expo_push_token' => $token, 'platform' => 'ios'])->assertOk();
        expect(DevicePushToken::where('expo_push_token', $token)->value('personal_access_token_id'))->not->toBeNull();

        app('auth')->forgetGuards();
        $this->withToken($plain)->postJson('/api/logout')->assertOk();

        expect(DevicePushToken::where('expo_push_token', $token)->exists())->toBeFalse();
    });

    it('removes a child\'s push devices when the parent signs the child out everywhere', function () {
        [, $child] = pnFamily();
        pnDevice($child);
        pnDevice($child);

        app(ChildProfileService::class)->revokeDevices($child);

        expect(DevicePushToken::where('user_id', $child->id)->count())->toBe(0);
    });

    it('removes push devices with a deleted child profile and with a deleted parent account', function () {
        [$parent, $child] = pnFamily();
        pnDevice($child);
        pnDevice($parent);

        app(AccountDeletionService::class)->deleteChildProfile($parent, $child);
        expect(DevicePushToken::where('user_id', $child->id)->exists())->toBeFalse()
            ->and(DevicePushToken::where('user_id', $parent->id)->exists())->toBeTrue();

        app(AccountDeletionService::class)->deleteParentAccount($parent);
        expect(DevicePushToken::count())->toBe(0);
    });
});

describe('Escalation pushes — recipients and copy', function () {
    it('sends phase 1 to every caretaker child only, with the spec text and {type, pet_id} data', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 30]);
        $childDevice = pnDevice($child);
        pnDevice($parent);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect(pnRecipientsOf($sent))->toBe([$childDevice->expo_push_token]);
        $message = $sent[0];
        expect($message['title'])->toBe('PetPrep')
            ->and($message['body'])->toBe('Tvoj kuža te milo gleda in kaže na posodo s hrano.')
            ->and($message['data'])->toBe(['type' => 'soft_warning', 'pet_id' => $pet->id])
            ->and($message['priority'])->toBe('default')
            ->and($message['channelId'])->toBe('default');

        $row = PushNotification::sole();
        expect($row->status)->toBe('sent')
            ->and($row->type)->toBe(PushType::SoftWarning)
            ->and($row->metric)->toBe('hunger')
            ->and(PushTicket::where('push_notification_id', $row->id)->value('status'))->toBe('ok');
    });

    it('sends phase 2 with high priority, sound and the alarm channel', function () {
        [, $child, $pet] = pnFamily(['thirst_level' => 8]);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($sent)->toHaveCount(1)
            ->and($sent[0])->toMatchArray([
                'body' => 'Če mu ne daš vode v 30 minutah, bo zbolel.',
                'priority' => 'high',
                'sound' => 'default',
                'channelId' => 'alarm',
                'data' => ['type' => 'critical_alert', 'pet_id' => $pet->id],
            ]);
    });

    it('turns low energy into a normal walk reminder — never alarm style, never "zbolel v 30 minutah"', function () {
        [, $child, $pet] = pnFamily(['energy_level' => 5]);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->escalation_level)->toBe(0) // energy is not on the phase ladder
            ->and($sent)->toHaveCount(1)
            ->and($sent[0])->toMatchArray([
                'priority' => 'default',
                'channelId' => 'default',
                'data' => ['type' => 'walk_reminder', 'pet_id' => $pet->id],
            ]);
        expect($sent[0]['body'])->toContain('sprehod')
            ->not->toContain('30 minut')
            ->not->toContain('zbolel');
        expect(PushNotification::sole())->metric->toBe('energy')->type->toBe(PushType::WalkReminder);
    });

    it('sends phase 3 to every parent of the family (not the child) with the spec sentence', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(2)]);
        $second = User::factory()->parent()->create(['name' => 'Oče Bor']);
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);
        $d1 = pnDevice($parent);
        $d2 = pnDevice($second->refresh());
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->escalation_level)->toBe(3)
            ->and(pnRecipientsOf($sent))->toBe(collect([$d1->expo_push_token, $d2->expo_push_token])->sort()->values()->all())
            ->and($sent[0]['body'])->toStartWith('Tvoj otrok danes ni poskrbel za psa.')
            ->and($sent[0]['body'])->toContain('brez hrane')
            ->and($sent[0]['priority'])->toBe('high')
            ->and($sent[0]['data'])->toBe(['type' => 'parent_intervention_alarm', 'pet_id' => $pet->id]);
    });

    it('tells parents and caretakers about an illness, each in their own words', function () {
        [$parent, $child, $pet] = pnFamily(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7), 'escalation_level' => 3]);
        $parentDevice = pnDevice($parent);
        $childDevice = pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->isIll())->toBeTrue();
        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken)->toHaveCount(2)
            ->and($byToken[$parentDevice->expo_push_token]['body'])->toBe(PushCopy::body(PushType::Illness, 'hygiene', 'parent', 'sl'))
            ->and($byToken[$childDevice->expo_push_token]['body'])->toBe(PushCopy::body(PushType::Illness, 'hygiene', 'child', 'sl'))
            ->and($byToken[$childDevice->expo_push_token]['data'])->toBe(['type' => 'illness_triggered', 'pet_id' => $pet->id]);
    });

    it('tells parents and caretakers about a game over', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25), 'escalation_level' => 3]);
        pnDevice($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->is_game_over)->toBeTrue()
            ->and($sent)->toHaveCount(2)
            ->and(collect($sent->getArrayCopy())->pluck('data.type')->unique()->all())->toBe(['game_over_virtual_shelter']);
    });

    it('never puts a child name, parent name or any extra field into what Expo receives', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25), 'escalation_level' => 3]);
        pnDevice($parent);
        pnDevice($child);
        $bodies = [];
        pnFakeExpo();

        pnEscalate($pet);

        Http::assertSent(function (Request $request) use (&$bodies) {
            $bodies[] = $request->body();

            return true;
        });
        $raw = implode("\n", $bodies);
        expect($raw)->not->toContain('Maja')
            ->not->toContain('Mama Ana')
            ->not->toContain('"user_id"');
        foreach (json_decode($bodies[0], true) as $message) {
            expect(array_keys($message))->toEqualCanonicalizing(['to', 'title', 'body', 'data', 'sound', 'priority', 'channelId', 'ttl'])
                ->and(array_keys($message['data']))->toBe(['type', 'pet_id']);
        }
    });

    it('records but sends nothing for a caretaker without devices', function () {
        [, , $pet] = pnFamily(['hunger_level' => 30]);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($sent)->toHaveCount(0)
            ->and(PushNotification::sole())->status->toBe('suppressed')->suppressed_reason->toBe('no_devices');
        Http::assertNothingSent();
    });

    it('does nothing at all while push is switched off', function () {
        [, $child, $pet] = pnFamily(['hunger_level' => 30]);
        config(['push.enabled' => false]);
        pnDevice($child);
        pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->escalation_level)->toBe(1)
            ->and(PushNotification::count())->toBe(0);
        Http::assertNothingSent();
    });
});

describe('Escalation pushes — quiet hours and duplicate guard', function () {
    it('suppresses every push during the family quiet hours (local clock)', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(2)]);
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '13:00', // 12:00 local now
            'bedtime_start' => '21:00', 'bedtime_end' => '07:00',
            'is_active' => true,
        ]);
        pnDevice($parent);
        pnDevice($child);
        pnFakeExpo();

        pnEscalate($pet);

        expect($pet->refresh()->escalation_level)->toBe(3) // the game still escalates
            ->and(PushNotification::sole())->status->toBe('suppressed')->suppressed_reason->toBe('quiet_hours');
        Http::assertNothingSent();
    });

    it('sends outside the quiet hours of the same family', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 30]);
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '11:30', // 12:00 local: outside
            'bedtime_start' => '21:00', 'bedtime_end' => '07:00',
            'is_active' => true,
        ]);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($sent)->toHaveCount(1);
    });

    it('stays silent when a queued send is retried into quiet hours', function () {
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 30]);
        pnDevice($child);
        Queue::fake([SendPushNotification::class]);
        pnEscalate($pet);
        $row = PushNotification::sole();
        expect($row->status)->toBe('queued');
        Queue::assertPushedOn('notifications', SendPushNotification::class);

        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00', 'school_end' => '13:00',
            'bedtime_start' => '21:00', 'bedtime_end' => '07:00',
            'is_active' => true,
        ]);
        pnFakeExpo();
        (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

        expect($row->refresh())->status->toBe('suppressed')->suppressed_reason->toBe('quiet_hours');
        Http::assertNothingSent();
    });

    it('pushes the same pet + type at most once per 30 minutes', function () {
        [, $child, $pet] = pnFamily(['hunger_level' => 30]);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);
        // Fed to 31 % (reset), then down to 30 % again 10 min later.
        Pet::whereKey($pet->id)->update(['hunger_level' => 31]);
        pnEscalate($pet->refresh());
        $this->travel(10)->minutes();
        Pet::whereKey($pet->id)->update(['hunger_level' => 30]);
        pnEscalate($pet->refresh());

        expect($sent)->toHaveCount(1)
            ->and(PushNotification::orderBy('id')->pluck('status')->all())->toBe(['sent', 'suppressed'])
            ->and(PushNotification::latest('id')->first()->suppressed_reason)->toBe('duplicate');

        // After the window it is news again.
        $this->travel(31)->minutes();
        Pet::whereKey($pet->id)->update(['hunger_level' => 31]);
        pnEscalate($pet->refresh());
        Pet::whereKey($pet->id)->update(['hunger_level' => 30]);
        pnEscalate($pet->refresh());

        expect($sent)->toHaveCount(2);
    });

    it('gives every notification its own idempotency key', function () {
        [, $child, $pet] = pnFamily(['hunger_level' => 30]);
        pnDevice($child);
        pnFakeExpo();

        pnEscalate($pet);
        Pet::whereKey($pet->id)->update(['hunger_level' => 5]);
        pnEscalate($pet->refresh());

        expect(PushNotification::pluck('idempotency_key')->unique())->toHaveCount(2);
    });
});

describe('Expo delivery — tickets, receipts, retries', function () {
    it('disables a token Expo reports as DeviceNotRegistered on the ticket', function () {
        [, $child, $pet] = pnFamily();
        $gone = pnDevice($child, pnToken('goneGGGGGGGGGGGGGGGGGG'));
        $ok = pnDevice($child, pnToken('okHHHHHHHHHHHHHHHHHHHH'));
        $row = pnQueued($pet);
        pnFakeExpo(fn (array $m) => $m['to'] === $gone->expo_push_token
            ? ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]
            : ['status' => 'ok', 'id' => 'ticket-ok']);

        pnRun($row);

        expect($gone->refresh())->disabled_at->not->toBeNull()->disabled_reason->toBe('DeviceNotRegistered')
            ->and($ok->refresh()->disabled_at)->toBeNull()
            ->and($row->refresh()->status)->toBe('sent')
            ->and(PushTicket::where('device_push_token_id', $gone->id)->value('error'))->toBe('DeviceNotRegistered');

        // A disabled device is skipped next time.
        $next = pnQueued($pet, PushType::CriticalAlert);
        $sent = pnFakeExpo();
        pnRun($next);
        expect(pnRecipientsOf($sent))->toBe([$ok->expo_push_token]);
    });

    it('reads receipts after 15 minutes and disables DeviceNotRegistered devices', function () {
        [, $child, $pet] = pnFamily();
        $gone = pnDevice($child, pnToken('lateIIIIIIIIIIIIIIIIII'));
        $ok = pnDevice($child, pnToken('fineJJJJJJJJJJJJJJJJJJ'));
        $row = pnQueued($pet);
        pnFakeExpo(fn (array $m) => ['status' => 'ok', 'id' => $m['to'] === $gone->expo_push_token ? 'r-gone' : 'r-ok']);
        pnRun($row);

        // Too early: nothing asked.
        $receiptsAsked = 0;
        pnFakeExpo(null, function (string $id) use (&$receiptsAsked) {
            $receiptsAsked++;

            return $id === 'r-gone'
                ? ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']]
                : ['status' => 'ok'];
        });
        (new CheckPushReceipts)->handle(app(NotificationService::class), app(ExpoPushClient::class));
        expect($receiptsAsked)->toBe(0);

        $this->travel(16)->minutes();
        $this->artisan('push:receipts')->assertSuccessful(); // queues CheckPushReceipts (sync in tests)

        expect($receiptsAsked)->toBe(2)
            ->and($gone->refresh()->disabled_at)->not->toBeNull()
            ->and($ok->refresh()->disabled_at)->toBeNull()
            ->and(PushTicket::where('ticket_id', 'r-gone')->value('receipt_error'))->toBe('DeviceNotRegistered')
            ->and(PushTicket::whereNull('receipt_checked_at')->count())->toBe(0);
    });

    it('retries a transient Expo failure and does not resend to devices that already have a ticket', function () {
        [, $child, $pet] = pnFamily();
        config(['push.chunk_size' => 2]);
        $devices = collect(range(1, 3))->map(fn ($i) => pnDevice($child, pnToken("chunk{$i}KKKKKKKKKKKKKKKKK")));
        $row = pnQueued($pet);

        $calls = 0;
        $delivered = [];
        pnResetHttp();
        Http::fake([PN_SEND => function (Request $request) use (&$calls, &$delivered) {
            $calls++;
            if ($calls === 2) {
                return Http::response(['errors' => [['code' => 'INTERNAL']]], 503);
            }
            $tickets = [];
            foreach ($request->data() as $m) {
                $delivered[] = $m['to'];
                $tickets[] = ['status' => 'ok', 'id' => 'tk-'.Str::random(6)];
            }

            return Http::response(['data' => $tickets]);
        }]);

        expect(fn () => pnRun($row))->toThrow(ExpoPushException::class);
        expect($row->refresh()->status)->toBe('queued')
            ->and(PushTicket::count())->toBe(2);

        pnRun($row); // the worker's next attempt

        expect($row->refresh())->status->toBe('sent')->attempts->toBe(2)
            ->and($delivered)->toHaveCount(3)
            ->and(collect($delivered)->sort()->values()->all())->toBe($devices->pluck('expo_push_token')->sort()->values()->all());

        // A duplicate run of a sent notification sends nothing.
        pnRun($row);
        expect($calls)->toBe(3);
    });

    it('fails without retry when Expo rejects the request, and marks the row failed', function () {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        $row = pnQueued($pet);
        pnResetHttp();
        Http::fake([PN_SEND => Http::response(['errors' => [['code' => 'VALIDATION_ERROR']]], 400)]);

        $job = (new SendPushNotification($row->id))->withFakeQueueInteractions();
        $job->handle(app(NotificationService::class), app(ExpoPushClient::class));

        $job->assertFailed();
        $job->failed(new ExpoPushException('rejected', retryable: false));
        expect($row->refresh())->status->toBe('failed')->last_error->toBe('rejected');
    });

    it('declares retries with backoff on the notifications queue', function () {
        $job = new SendPushNotification(1);

        expect($job->tries)->toBe(5)
            ->and($job->backoff)->toBe([10, 30, 60, 180])
            ->and($job->uniqueId())->toBe('1');
    });

    it('sends 100 messages per request at most', function () {
        [, $child, $pet] = pnFamily();
        foreach (range(1, 101) as $i) {
            pnDevice($child, pnToken(sprintf('bulk%03dLLLLLLLLLLLLLLLL', $i)));
        }
        $row = pnQueued($pet);
        $sent = pnFakeExpo();

        pnRun($row);

        Http::assertSentCount(2);
        expect($sent)->toHaveCount(101)
            ->and(PushTicket::where('push_notification_id', $row->id)->count())->toBe(101);
    });

    it('sends the Expo access token when configured, and never inside a DB transaction', function () {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        config(['push.expo_access_token' => 'expo-secret']);
        $row = pnQueued($pet);
        pnFakeExpo();

        pnRun($row);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer expo-secret'));

        expect(fn () => DB::transaction(fn () => app(ExpoPushClient::class)->send([])))
            ->toThrow(LogicException::class);
    });

    it('prunes push rows older than 30 days', function () {
        [, , $pet] = pnFamily();
        $old = PushNotification::create([
            'idempotency_key' => (string) Str::uuid(), 'pet_id' => $pet->id, 'type' => 'soft_warning',
            'recipients' => [], 'status' => 'sent',
        ]);
        PushNotification::whereKey($old->id)->update(['created_at' => now()->subDays(31)]);

        $this->artisan('push:receipts')->assertSuccessful();

        expect(PushNotification::find($old->id))->toBeNull();
    });
});

/** Family quiet hours: school + bedtime (family-local "HH:MM"). */
function pnQuiet(User $parent, string $schoolStart = '08:00', string $schoolEnd = '13:00', string $bedStart = '22:00', string $bedEnd = '06:00'): QuietHours
{
    return QuietHours::create([
        'parent_id' => $parent->id,
        'school_start' => $schoolStart, 'school_end' => $schoolEnd,
        'bedtime_start' => $bedStart, 'bedtime_end' => $bedEnd,
        'is_active' => true,
    ]);
}

function pnAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

describe('PR #35 — the daily walk reminder (energy)', function () {
    it('comes once per family day, 2 h after the last quiet stretch: bedtime ends 06:00 → 08:00 is school → 15:00', function () {
        pnAt('2026-10-14 04:30:00'); // 06:30 Ljubljana, bedtime 22–06 just ended
        [$parent, $child, $pet] = pnFamily(['energy_level' => 0]);
        pnQuiet($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        $row = PushNotification::sole();
        expect($row->type)->toBe(PushType::WalkReminder)
            ->and($row->status)->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-14T06:00:00+00:00') // 08:00 local
            ->and($sent)->toHaveCount(0);

        // 07:59 local: not yet.
        pnAt('2026-10-14 05:59:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh()->status)->toBe('scheduled');

        // 08:00 local: school started → held again until 2 h after school (15:00).
        pnAt('2026-10-14 06:00:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh())
            ->status->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-14T13:00:00+00:00')
            ->and($sent)->toHaveCount(0);

        // 15:00 local: sent, normal priority, default channel.
        pnAt('2026-10-14 13:00:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh()->status)->toBe('sent')
            ->and($sent)->toHaveCount(1)
            ->and($sent[0])->toMatchArray(['priority' => 'default', 'channelId' => 'default', 'data' => ['type' => 'walk_reminder', 'pet_id' => $pet->id]]);

        // Later the same day, every tick: no second walk reminder, not even a row.
        pnAt('2026-10-14 17:00:00'); // 19:00 local
        pnEscalate($pet->refresh());
        pnAt('2026-10-14 17:01:00');
        pnEscalate($pet->refresh());
        expect(PushNotification::count())->toBe(1)
            ->and($sent)->toHaveCount(1);

        // Next family day, after school + 2 h: news again.
        pnAt('2026-10-15 14:00:00'); // 16:00 local
        Pet::whereKey($pet->id)->update(['escalation_level' => 0, 'energy_level' => 0]);
        pnEscalate($pet->refresh());
        expect($sent)->toHaveCount(2);
    });

    it('is dropped when the child walked before it was due', function () {
        pnAt('2026-10-14 11:30:00'); // 13:30 local, school ended 13:00 → due 15:00
        [$parent, $child, $pet] = pnFamily(['energy_level' => 5]);
        pnQuiet($parent);
        pnDevice($child);
        $sent = pnFakeExpo();
        pnEscalate($pet);
        $row = PushNotification::sole();
        expect($row->send_after->toIso8601String())->toBe('2026-10-14T13:00:00+00:00');

        Pet::whereKey($pet->id)->update(['energy_level' => 60]);
        pnAt('2026-10-14 13:01:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();

        expect($row->refresh())->status->toBe('suppressed')->suppressed_reason->toBe('walk_done')
            ->and($sent)->toHaveCount(0);
    });

    it('goes out at once without quiet hours, or long after the last quiet stretch', function () {
        pnAt('2026-10-14 15:00:00'); // 17:00 local
        [$parent, $child, $pet] = pnFamily(['energy_level' => 20]);
        pnQuiet($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        expect($sent)->toHaveCount(1)
            ->and(PushNotification::sole()->type)->toBe(PushType::WalkReminder);
    });
});

describe('PR #35 — illness / game over are held over quiet hours, the rest dropped', function () {
    it('holds a game over at 23:00 until bedtime ends at 06:00, then tells parents and children', function () {
        pnAt('2026-10-14 21:00:00'); // 23:00 local, bedtime 22–06
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25), 'escalation_level' => 3]);
        pnQuiet($parent);
        pnDevice($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        $row = PushNotification::sole();
        expect($pet->refresh()->is_game_over)->toBeTrue()
            ->and($row->status)->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-15T04:00:00+00:00') // 06:00 local
            ->and($sent)->toHaveCount(0);

        pnAt('2026-10-15 04:01:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();

        expect($row->refresh()->status)->toBe('sent')
            ->and($sent)->toHaveCount(2); // sent although the pet is game over (that is the news)
    });

    it('holds an illness across adjacent windows (bedtime → school) to the end of the whole stretch', function () {
        pnAt('2026-10-14 04:30:00'); // 06:30 local
        [$parent, , $pet] = pnFamily();
        pnQuiet($parent, '06:00', '13:00', '22:00', '06:00'); // quiet 22:00 → 13:00

        $row = app(NotificationService::class)->escalation($pet, PushType::Illness, 'walk');

        expect($row)->status->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-14T11:00:00+00:00'); // 13:00 local
    });

    it('drops phase 1 / 2 / 3 in quiet hours (no later delivery)', function () {
        pnAt('2026-10-14 21:00:00'); // 23:00 local
        [$parent, , $pet] = pnFamily();
        pnQuiet($parent);

        foreach ([PushType::SoftWarning, PushType::CriticalAlert, PushType::ParentAlarm] as $type) {
            expect(app(NotificationService::class)->escalation($pet, $type, 'hunger'))
                ->status->toBe('suppressed')->suppressed_reason->toBe('quiet_hours');
        }
    });
});

describe('PR #35 — delivery re-checks the pet; Expo project split', function () {
    it('drops a queued reminder when the pet got hard-stopped, inactive, game over or ill meanwhile', function (array $change) {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        $row = pnQueued($pet, PushType::CriticalAlert);
        Pet::whereKey($pet->id)->update($change);
        pnFakeExpo();

        pnRun($row);

        expect($row->refresh())->status->toBe('suppressed')->suppressed_reason->toBe('pet_locked');
        Http::assertNothingSent();
    })->with([
        'hard stop' => [['is_hard_stopped' => true]],
        'inactive' => [['is_active' => false]],
        'game over' => [['is_game_over' => true, 'is_active' => false]],
        'ill' => [['illness_until' => '2026-10-14 20:00:00']],
    ]);

    it('still sends illness news while the pet is ill', function () {
        [$parent, , $pet] = pnFamily();
        pnDevice($parent);
        $row = pnQueued($pet, PushType::Illness, 'hygiene');
        Pet::whereKey($pet->id)->update(['illness_until' => '2026-10-14 22:00:00']);
        $sent = pnFakeExpo();

        pnRun($row);

        expect($row->refresh()->status)->toBe('sent')->and($sent)->toHaveCount(1);
    });

    it('splits a chunk per Expo project on PUSH_TOO_MANY_EXPERIENCE_IDS and sends each group', function () {
        [, $child, $pet] = pnFamily();
        $a = pnDevice($child, pnToken('projAaaaaaaaaaaaaaaaaaa'));
        $b = pnDevice($child, pnToken('projBbbbbbbbbbbbbbbbbbb'));
        $c = pnDevice($child, pnToken('projAccccccccccccccccc'));
        $row = pnQueued($pet);

        $requests = [];
        pnResetHttp();
        Http::fake([PN_SEND => function (Request $request) use (&$requests, $a, $b, $c) {
            $requests[] = collect($request->data())->pluck('to')->all();
            if (count($requests) === 1) {
                return Http::response(['errors' => [[
                    'code' => 'PUSH_TOO_MANY_EXPERIENCE_IDS',
                    'message' => 'All push notification messages in the same request must be for the same project.',
                    'details' => [
                        '@petprep/petprep' => [$a->expo_push_token, $c->expo_push_token],
                        '@petprep/old-build' => [$b->expo_push_token],
                    ],
                ]]], 400);
            }

            return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'tk-'.Str::random(6)], $request->data())]);
        }]);

        pnRun($row);

        expect($requests)->toHaveCount(3)
            ->and($requests[1])->toBe([$a->expo_push_token, $c->expo_push_token])
            ->and($requests[2])->toBe([$b->expo_push_token])
            ->and($row->refresh()->status)->toBe('sent')
            ->and(PushTicket::where('push_notification_id', $row->id)->where('status', 'ok')->count())->toBe(3);
    });
});

describe('PR #35 re-review — energy is off the phase ladder', function () {
    it('walk reminder for energy 0 %, then hunger 10 % still gets its critical push, then the parent alarm', function () {
        pnAt('2026-10-14 13:00:00'); // 15:00 local, no quiet hours
        [$parent, $child, $pet] = pnFamily(['energy_level' => 0]);
        pnDevice($child);
        $parentDevice = pnDevice($parent);
        $sent = pnFakeExpo();

        pnEscalate($pet);
        expect($pet->refresh()->escalation_level)->toBe(0)
            ->and(collect($sent->getArrayCopy())->pluck('data.type')->all())->toBe(['walk_reminder']);

        // Hunger falls to 10 % while the walk is still missing → phase 2 for hunger.
        pnAt('2026-10-14 13:05:00');
        Pet::whereKey($pet->id)->update(['hunger_level' => 10]);
        pnEscalate($pet->refresh());

        expect($pet->refresh()->escalation_level)->toBe(2)
            ->and($sent)->toHaveCount(2)
            ->and($sent[1])->toMatchArray([
                'body' => 'Če ga ne nahraniš v 30 minutah, bo zbolel.',
                'priority' => 'high',
                'channelId' => 'alarm',
                'data' => ['type' => 'critical_alert', 'pet_id' => $pet->id],
            ]);

        // Hunger 0 % for over an hour → every parent gets the alarm.
        pnAt('2026-10-14 15:10:00');
        Pet::whereKey($pet->id)->update(['hunger_level' => 0, 'hunger_zero_since' => now()->subMinutes(70)]);
        pnEscalate($pet->refresh());

        expect($pet->refresh()->escalation_level)->toBe(3)
            ->and($sent)->toHaveCount(3)
            ->and($sent[2]['to'])->toBe($parentDevice->expo_push_token)
            ->and($sent[2]['data']['type'])->toBe('parent_intervention_alarm')
            ->and(PushNotification::where('type', 'walk_reminder')->count())->toBe(1);
    });

    it('asks for no walk reminder while quiet, and none when energy is above 30 %', function () {
        pnAt('2026-10-14 21:00:00'); // 23:00 local
        [$parent, $child, $pet] = pnFamily(['energy_level' => 0]);
        pnQuiet($parent);
        pnDevice($child);
        pnFakeExpo();

        pnEscalate($pet);
        expect(PushNotification::count())->toBe(0);

        pnAt('2026-10-14 15:00:00');
        Pet::whereKey($pet->id)->update(['energy_level' => 31]);
        pnEscalate($pet->refresh());
        expect(PushNotification::count())->toBe(0);
    });
});

describe('PR #35 re-review — recovered reminders and stuck rows', function () {
    it('drops a phase 1 / 2 reminder whose metric recovered above its threshold before sending', function (PushType $type, int $now) {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        $row = pnQueued($pet, $type, 'thirst');
        Pet::whereKey($pet->id)->update(['thirst_level' => $now]);
        pnFakeExpo();

        pnRun($row);

        expect($row->refresh())->status->toBe('suppressed')->suppressed_reason->toBe('recovered');
        Http::assertNothingSent();
    })->with([
        'phase 1, refilled' => [PushType::SoftWarning, 100],
        'phase 1, 31 %' => [PushType::SoftWarning, 31],
        'phase 2, back to 20 %' => [PushType::CriticalAlert, 20],
    ]);

    it('still sends a phase 2 reminder at exactly 10 %', function () {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        $row = pnQueued($pet, PushType::CriticalAlert, 'hunger');
        Pet::whereKey($pet->id)->update(['hunger_level' => 10]);
        $sent = pnFakeExpo();

        pnRun($row);

        expect($row->refresh()->status)->toBe('sent')->and($sent)->toHaveCount(1);
    });

    it('re-queues a row stuck in queued without any attempt for 20 min, leaves fresh ones alone', function () {
        [, $child, $pet] = pnFamily();
        pnDevice($child);
        $stuck = pnQueued($pet);
        $fresh = pnQueued($pet, PushType::CriticalAlert);
        // The lost job's unique lock (15 min) has expired by then.
        $this->travel(NotificationService::STUCK_MINUTES + 1)->minutes();
        PushNotification::whereKey($fresh->id)->update(['updated_at' => now()->subMinutes(5)]);
        Queue::fake([SendPushNotification::class]);

        $this->artisan('push:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(SendPushNotification::class, 1);
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->pushNotificationId === $stuck->id);
        expect($stuck->refresh()->updated_at->equalTo(now()))->toBeTrue();
    });

    it('puts a row back to scheduled when queueing the job fails', function () {
        [, , $pet] = pnFamily();
        $row = PushNotification::create([
            'idempotency_key' => (string) Str::uuid(), 'pet_id' => $pet->id, 'type' => 'illness_triggered',
            'recipients' => [], 'status' => 'scheduled', 'send_after' => now()->subMinute(),
        ]);
        Bus::swap(Mockery::mock(BusDispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('redis down'));
        }));

        $this->artisan('push:dispatch-scheduled')->assertSuccessful();

        expect($row->refresh()->status)->toBe('scheduled')
            ->and($row->send_after->lessThanOrEqualTo(now()))->toBeTrue();
    });
});

describe('PR #35 re-review — DST (Europe/Ljubljana, 2026-10-25: 03:00 CEST → 02:00 CET)', function () {
    it('walk reminder after the night of the clock change: bedtime ends 06:00 CET → 08:00 CET = 07:00 UTC', function () {
        pnAt('2026-10-25 05:30:00'); // 06:30 CET (UTC+1)
        [$parent, $child, $pet] = pnFamily(['energy_level' => 0]);
        pnQuiet($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        $row = PushNotification::sole();
        expect($row->status)->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-25T07:00:00+00:00');

        // 08:00 CET: school → held until 15:00 CET = 14:00 UTC.
        pnAt('2026-10-25 07:00:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh()->send_after->toIso8601String())->toBe('2026-10-25T14:00:00+00:00');

        pnAt('2026-10-25 14:00:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh()->status)->toBe('sent')->and($sent)->toHaveCount(1);
    });

    it('holds a game over from 23:00 CEST over the 9-hour night to 06:00 CET = 05:00 UTC', function () {
        pnAt('2026-10-24 21:00:00'); // 23:00 CEST (UTC+2)
        [$parent, $child, $pet] = pnFamily(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25), 'escalation_level' => 3]);
        pnQuiet($parent);
        pnDevice($parent);
        pnDevice($child);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        $row = PushNotification::sole();
        expect($row->status)->toBe('scheduled')
            ->and($row->send_after->toIso8601String())->toBe('2026-10-25T05:00:00+00:00');

        pnAt('2026-10-25 04:59:00'); // 05:59 CET — still bedtime
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($sent)->toHaveCount(0);

        pnAt('2026-10-25 05:00:00');
        $this->artisan('push:dispatch-scheduled')->assertSuccessful();
        expect($row->refresh()->status)->toBe('sent')->and($sent)->toHaveCount(2);
    });
});

it('registers push devices on their own rate limiter, not the shared api bucket (hotfix 2026-10-06)', function () {
    $route = app('router')->getRoutes()->match(Illuminate\Http\Request::create('/api/devices', 'POST'));

    expect($route->gatherMiddleware())->toContain('throttle:devices')
        ->not->toContain('throttle:api');
});

it('limits device registration to 10 per minute per user outside testing, falling back to the IP for guests', function () {
    $limitFor = function (?User $user, string $ip = '203.0.113.7'): Limit {
        $request = Illuminate\Http\Request::create('/api/devices', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->setUserResolver(fn () => $user);

        return RateLimiter::limiter('devices')($request);
    };

    // In testing the limiter is off (functional tests register freely).
    expect($limitFor(null)->maxAttempts)->toBe(Limit::none()->maxAttempts);

    $env = app()['env'];
    app()['env'] = 'production';
    try {
        $parent = createParentUser();
        $child = createChildUser();

        $limit = $limitFor($parent);
        expect($limit->maxAttempts)->toBe(10)
            ->and($limit->decaySeconds)->toBe(60)
            ->and($limit->key)->toBe('devices:'.$parent->id)
            // Per user: another user on the same IP has its own bucket.
            ->and($limitFor($child)->key)->toBe('devices:'.$child->id)
            ->and($limitFor(null)->key)->toBe('devices:203.0.113.7');
    } finally {
        app()['env'] = $env;
    }
});

// ──────────────────────────────────────────────────────────────
// M1-18: push language per install
// ──────────────────────────────────────────────────────────────

describe('POST /api/devices — install language (M1-18)', function () {
    it('stores the explicit `locale` field and refreshes it on every re-registration that sends it', function () {
        [, $child] = pnFamily();
        $token = pnToken('langFFFFFFFFFFFFFFFFFFF');
        actingAsRole($child);
        $register = fn (array $body, array $headers = []) => $this->postJson(
            '/api/devices', ['expo_push_token' => $token, 'platform' => 'ios'] + $body, $headers,
        );

        $register(['locale' => 'en'])->assertOk()->assertJsonPath('device.locale', 'en');
        expect(DevicePushToken::where('expo_push_token', $token)->value('locale'))->toBe('en');

        // The body decides, not Accept-Language.
        $register(['locale' => 'sl'], ['Accept-Language' => 'en-GB'])->assertOk()->assertJsonPath('device.locale', 'sl');
        expect(DevicePushToken::where('expo_push_token', $token)->sole()->locale)->toBe('sl');

        $register(['locale' => 'en'], ['Accept-Language' => 'sl-SI'])->assertOk()->assertJsonPath('device.locale', 'en');
    });

    it('never takes the push language from an implicit iOS Accept-Language (installed 2.0.2 builds)', function () {
        [, $child] = pnFamily();
        $known = pnToken('knownGGGGGGGGGGGGGGGGG');
        pnDevice($child, $known, 'ios', 'sl'); // backfilled pre-M1-18 install
        actingAsRole($child);

        foreach (['en-US,en;q=0.9', 'en-GB', '', 'fr-FR, *;q=0.1'] as $header) {
            $this->postJson('/api/devices', ['expo_push_token' => $known, 'platform' => 'ios'], ['Accept-Language' => $header])
                ->assertOk()->assertJsonPath('device.locale', 'sl');
        }
        // An explicit null is "not sent" too.
        $this->postJson('/api/devices', ['expo_push_token' => $known, 'platform' => 'ios', 'locale' => null], ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()->assertJsonPath('device.locale', 'sl');
        expect(DevicePushToken::where('expo_push_token', $known)->sole()->locale)->toBe('sl');
    });

    it('gives a new install without `locale` Slovenian (only pre-M1-18 builds omit it)', function () {
        [$parent, $child] = pnFamily();
        actingAsRole($child);

        $fresh = pnToken('freshHHHHHHHHHHHHHHHHH');
        $this->postJson('/api/devices', ['expo_push_token' => $fresh, 'platform' => 'ios'], ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()->assertJsonPath('device.locale', 'sl');
        expect(DevicePushToken::where('expo_push_token', $fresh)->value('locale'))->toBe('sl');

        // Moving the install to another account with an explicit language takes it.
        app('auth')->forgetGuards();
        actingAsRole($parent);
        $this->postJson('/api/devices', ['expo_push_token' => $fresh, 'platform' => 'ios', 'locale' => 'en'])->assertOk();
        expect(DevicePushToken::where('expo_push_token', $fresh)->sole())
            ->user_id->toBe($parent->id)
            ->locale->toBe('en');
    });

    it('refuses an unsupported or malformed locale', function (mixed $locale) {
        [, $child] = pnFamily();
        actingAsRole($child);

        $this->postJson('/api/devices', ['expo_push_token' => pnToken(), 'platform' => 'ios', 'locale' => $locale])
            ->assertUnprocessable()->assertJsonValidationErrors('locale');
        expect(DevicePushToken::count())->toBe(0);
    })->with([
        'unsupported' => ['de'],
        'region tag' => ['sl-SI'],
        'upper case' => ['EN'],
        'array' => [['en']],
        'number' => [1],
    ]);
});

describe('Escalation pushes — copy per install language (M1-18)', function () {
    it('renders each device in its own language in a single Expo request (mixed batch)', function () {
        [, $child, $pet] = pnFamily(['hunger_level' => 30]);
        $sl = pnDevice($child, null, 'ios', 'sl');
        $en = pnDevice($child, null, 'android', 'en');
        $none = pnDevice($child, null, 'ios', null);
        $sent = pnFakeExpo();

        pnEscalate($pet);

        Http::assertSentCount(1);
        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        expect($byToken)->toHaveCount(3)
            ->and($byToken[$sl->expo_push_token]['body'])->toBe('Tvoj kuža te milo gleda in kaže na posodo s hrano.')
            ->and($byToken[$en->expo_push_token]['body'])->toBe('Your dog is giving you a gentle look and pointing at the food bowl.')
            ->and($byToken[$none->expo_push_token]['body'])->toBe($byToken[$en->expo_push_token]['body']) // null → English
            ->and(collect($sent->getArrayCopy())->pluck('title')->unique()->all())->toBe(['PetPrep'])
            ->and(collect($sent->getArrayCopy())->pluck('data')->unique()->values()->all())->toBe([['type' => 'soft_warning', 'pet_id' => $pet->id]]);

        // Still one notification (dedupe is per pet + type, not per language) and one ticket per device.
        expect(PushNotification::count())->toBe(1)
            ->and(PushTicket::count())->toBe(3);

        pnEscalate($pet);
        expect(PushNotification::count())->toBe(1);
    });

    it('keeps batching (≤ chunk size per request) with mixed languages, parents and children each in their own words', function () {
        config(['push.chunk_size' => 2]);
        [$parent, $child, $pet] = pnFamily(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7), 'escalation_level' => 3]);
        $devices = [
            pnDevice($parent, null, 'ios', 'en'),
            pnDevice($parent, null, 'ios', 'sl'),
            pnDevice($child, null, 'android', 'sl'),
            pnDevice($child, null, 'ios', 'en'),
            pnDevice($child, null, 'ios', null),
        ];
        $sent = pnFakeExpo();

        pnEscalate($pet);

        Http::assertSentCount(3); // 2 + 2 + 1
        $byToken = collect($sent->getArrayCopy())->keyBy('to');
        $expected = [
            PushCopy::body(PushType::Illness, 'hygiene', 'parent', 'en'),
            PushCopy::body(PushType::Illness, 'hygiene', 'parent', 'sl'),
            PushCopy::body(PushType::Illness, 'hygiene', 'child', 'sl'),
            PushCopy::body(PushType::Illness, 'hygiene', 'child', 'en'),
            PushCopy::body(PushType::Illness, 'hygiene', 'child', 'en'),
        ];
        foreach ($devices as $i => $device) {
            expect($byToken[$device->expo_push_token]['body'])->toBe($expected[$i]);
        }
        expect($expected[1])->toBe('Kuža je zbolel, ker nered ni bil počiščen. 12 ur bo na opazovanju pri veterinarju.')
            ->and($expected[0])->toBe('The dog got sick because a mess wasn’t cleaned up. It will stay at the vet for 12 hours of observation.');
    });
});

describe('PushCopy languages (M1-18)', function () {
    // The Slovenian texts exactly as PushCopy had them before M1-18 — must stay byte-identical.
    it('keeps every Slovenian text byte-identical', function (PushType $type, ?string $metric, string $audience, string $text) {
        expect(PushCopy::body($type, $metric, $audience, 'sl'))->toBe($text)
            ->and(PushCopy::title('sl'))->toBe('PetPrep');
    })->with([
        [PushType::SoftWarning, 'hunger', 'child', 'Tvoj kuža te milo gleda in kaže na posodo s hrano.'],
        [PushType::SoftWarning, 'thirst', 'child', 'Tvoj kuža te milo gleda in kaže na prazno posodo za vodo.'],
        [PushType::SoftWarning, 'hygiene', 'child', 'Tvoj kuža te milo gleda in kaže na nered, ki ga je treba počistiti.'],
        [PushType::SoftWarning, null, 'child', 'Tvoj kuža te milo gleda in kaže na posodo s hrano.'],
        [PushType::CriticalAlert, 'hunger', 'child', 'Če ga ne nahraniš v 30 minutah, bo zbolel.'],
        [PushType::CriticalAlert, 'thirst', 'child', 'Če mu ne daš vode v 30 minutah, bo zbolel.'],
        [PushType::CriticalAlert, 'hygiene', 'child', 'Kuža je naredil nered! Počisti ga čim prej, sicer bo zbolel.'],
        [PushType::WalkReminder, 'energy', 'child', 'Tvoj kuža danes še ni bil na sprehodu in te čaka s povodcem. Gremo ven?'],
        [PushType::ParentAlarm, 'hunger', 'parent', 'Tvoj otrok danes ni poskrbel za psa. Kuža je že več kot uro brez hrane.'],
        [PushType::ParentAlarm, 'thirst', 'parent', 'Tvoj otrok danes ni poskrbel za psa. Kuža je že več kot uro brez vode.'],
        [PushType::ParentAlarm, 'hygiene', 'parent', 'Tvoj otrok danes ni poskrbel za psa. Nered že več kot uro ni počiščen.'],
        [PushType::ParentAlarm, null, 'parent', 'Tvoj otrok danes ni poskrbel za psa.'],
        [PushType::Illness, 'hygiene', 'child', 'Kuža je predolgo živel v neredu in je zbolel. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::Illness, 'walk', 'child', 'Kuža včeraj ni bil na sprehodu in je zbolel. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::Illness, null, 'child', 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::Illness, 'hygiene', 'parent', 'Kuža je zbolel, ker nered ni bil počiščen. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::Illness, 'walk', 'parent', 'Kuža je zbolel, ker včeraj ni bil na sprehodu. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::Illness, 'hunger', 'parent', 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.'],
        [PushType::GameOver, null, 'child', 'Kuža je odšel v zavetišče, ker zanj predolgo ni nihče poskrbel. Pogovori se s starši.'],
        [PushType::GameOver, null, 'parent', 'Kuža je odšel v zavetišče, ker 24 ur ni dobil nujne skrbi. V aplikaciji izberite, kako naprej.'],
    ]);

    it('has an English text for every case, and null / unknown languages fall back to English', function () {
        $cases = [
            [PushType::SoftWarning, 'thirst', 'child'], [PushType::CriticalAlert, 'hygiene', 'child'],
            [PushType::WalkReminder, 'energy', 'child'], [PushType::ParentAlarm, 'thirst', 'parent'],
            [PushType::Illness, 'walk', 'child'], [PushType::Illness, 'walk', 'parent'],
            [PushType::GameOver, null, 'child'], [PushType::GameOver, null, 'parent'],
        ];
        foreach ($cases as [$type, $metric, $audience]) {
            $en = PushCopy::body($type, $metric, $audience, 'en');
            expect($en)->not->toBe('')
                ->not->toStartWith('push.')
                ->not->toBe(PushCopy::body($type, $metric, $audience, 'sl'))
                ->and(PushCopy::body($type, $metric, $audience, null))->toBe($en)
                ->and(PushCopy::body($type, $metric, $audience, 'de'))->toBe($en);
        }
        expect(PushCopy::body(PushType::ParentAlarm, 'hunger', 'parent', 'en'))
            ->toBe('Your child hasn’t looked after the dog today. The dog has had no food for over an hour.');
    });

    it('has the same keys in every language file', function () {
        $keys = fn (string $locale): array => array_keys(Arr::dot(require lang_path("{$locale}/push.php")));

        expect($keys('sl'))->toBe($keys('en'));
    });
});
