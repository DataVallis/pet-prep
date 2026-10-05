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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

function pnDevice(User $user, ?string $token = null, string $platform = 'ios'): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => $token ?? pnToken(),
        'platform' => $platform,
        'app_version' => '1.0.0',
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

        expect($pet->refresh()->escalation_level)->toBe(2) // the game still escalates
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
            ->and($byToken[$parentDevice->expo_push_token]['body'])->toBe(PushCopy::body(PushType::Illness, 'hygiene', 'parent'))
            ->and($byToken[$childDevice->expo_push_token]['body'])->toBe(PushCopy::body(PushType::Illness, 'hygiene', 'child'))
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

        // Later the same day (reset + low again): no second walk reminder.
        pnAt('2026-10-14 17:00:00'); // 19:00 local
        Pet::whereKey($pet->id)->update(['escalation_level' => 0, 'energy_level' => 0]);
        pnEscalate($pet->refresh());
        expect(PushNotification::latest('id')->first())->status->toBe('suppressed')->suppressed_reason->toBe('duplicate')
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
