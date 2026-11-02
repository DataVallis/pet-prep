<?php

use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\PushTicket;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Shared phone follows the pet opened last (David 2026-11-02)
|--------------------------------------------------------------------------
| A parent plays several children's pets on one phone (»Igraj kot«). Every
| child session registers the same Expo token; child-audience pushes go only
| to the child who registered there last. Parent rows get every pet's
| parent pushes; legacy rows (no follow stamp) and a child's own phone are
| unchanged.
*/

const PF_PHONE = 'ExponentPushToken[sharedFamilyPhoneFollow01]';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'UTC')); // 12:00 Ljubljana, Wednesday
    config(['push.enabled' => true]);
    Queue::fake([SendPushNotification::class]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Parent + two children of one family, each caring for their own born pet.
 *
 * @return array{parent: User, a: User, b: User, petA: Pet, petB: Pet}
 */
function pfFamily(): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    withoutQuietHours($parent);
    $pets = [];
    $children = [];
    foreach (['a', 'b'] as $key) {
        $children[$key] = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Otrok '.$key]);
        $pets[$key] = disableHygieneEvents(Pet::factory()->create([
            'user_id' => $children[$key]->id,
            'hunger_level' => 100,
            'thirst_level' => 100,
            'energy_level' => 100,
            'hygiene_level' => 100,
            'escalation_level' => 0,
        ]));
    }

    return ['parent' => $parent, 'a' => $children['a'], 'b' => $children['b'], 'petA' => $pets['a'], 'petB' => $pets['b']];
}

function pfBearer(string $token): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => 'Bearer '.$token]);
}

/** A real parent token + a real shared-phone child session per child (»Igraj kot«). */
function pfSessions(array $f): array
{
    $parentToken = $f['parent']->createToken('Pixel 8', ['parent'])->plainTextToken;
    $sessions = ['parent' => $parentToken];
    foreach (['a', 'b'] as $key) {
        pfBearer($parentToken);
        $sessions[$key] = postJson("/api/parent/children/{$f[$key]->id}/device-session", ['device_name' => 'Pixel 8'])
            ->assertOk()->json('token');
    }

    return $sessions;
}

/** The app registers the install for the open session (sign-in, switch, cold start). */
function pfRegister(string $bearer, string $expo = PF_PHONE): void
{
    Carbon::setTestNow(now()->addSecond());
    pfBearer($bearer);
    postJson('/api/devices', ['expo_push_token' => $expo, 'platform' => 'android'])->assertOk();
}

/** Fake Expo; returns the list of sent messages. */
function pfExpo(): ArrayObject
{
    $sent = new ArrayObject;
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
            $tickets = [];
            foreach ($request->data() as $message) {
                $sent->append($message);
                $tickets[] = ['status' => 'ok', 'id' => 'ticket-'.Str::random(10)];
            }

            return Http::response(['data' => $tickets]);
        },
    ]);

    return $sent;
}

/** Decide a push for the pet (past the dedupe window), without delivering it. */
function pfDecide(Pet $pet, PushType $type = PushType::SoftWarning): PushNotification
{
    Carbon::setTestNow(now()->addMinutes((int) config('push.dedupe_minutes') + 1));
    if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)) {
        Pet::whereKey($pet->id)->update(['hunger_level' => 5]);
    }
    $row = app(NotificationService::class)->escalation($pet->fresh(), $type, 'hunger');
    expect($row->status)->toBe(PushNotification::STATUS_QUEUED);

    return $row;
}

function pfDeliver(PushNotification $row): PushNotification
{
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

    return $row->fresh();
}

/** @return list<int> device rows that got a ticket for the notification */
function pfTicketRows(PushNotification $row): array
{
    return PushTicket::where('push_notification_id', $row->id)->orderBy('device_push_token_id')
        ->pluck('device_push_token_id')->map(fn ($id): int => (int) $id)->all();
}

function pfRow(User $user, string $expo = PF_PHONE): DevicePushToken
{
    return DevicePushToken::where('user_id', $user->id)->where('expo_push_token', $expo)->firstOrFail();
}

describe('child pushes on a shared phone follow the pet opened last', function () {
    it('reaches the phone only for the child who registered last; a switch flips it', function () {
        $f = pfFamily();
        $s = pfSessions($f);
        $sent = pfExpo();
        pfRegister($s['parent']);
        pfRegister($s['a']);
        pfRegister($s['b']); // B's pet was opened last

        $forA = pfDeliver(pfDecide($f['petA']));
        expect($forA->status)->toBe(PushNotification::STATUS_SUPPRESSED)
            ->and($forA->suppressed_reason)->toBe('no_devices')
            ->and(pfTicketRows($forA))->toBe([]);

        $forB = pfDeliver(pfDecide($f['petB']));
        expect($forB->status)->toBe(PushNotification::STATUS_SENT)
            ->and(pfTicketRows($forB))->toBe([pfRow($f['b'])->id])
            ->and($sent)->toHaveCount(1)
            ->and($sent[0]['to'])->toBe(PF_PHONE)
            ->and($sent[0]['data'])->toBe(['type' => PushType::SoftWarning->value, 'pet_id' => $f['petB']->id]);

        // Switch to A: A's pet now gets it, B's doesn't — rows are kept, not deleted.
        pfRegister($s['a']);
        expect(DevicePushToken::where('expo_push_token', PF_PHONE)->count())->toBe(3);

        $forA = pfDeliver(pfDecide($f['petA']));
        $forB = pfDeliver(pfDecide($f['petB']));
        expect($forA->status)->toBe(PushNotification::STATUS_SENT)
            ->and(pfTicketRows($forA))->toBe([pfRow($f['a'])->id])
            ->and($forB->suppressed_reason)->toBe('no_devices')
            ->and($sent)->toHaveCount(2);
    });

    it('decides the followed child at delivery, not when the push was decided', function () {
        $f = pfFamily();
        $s = pfSessions($f);
        pfExpo();
        pfRegister($s['a']);
        pfRegister($s['b']);

        $row = pfDecide($f['petA']); // B followed at decision time
        pfRegister($s['a']);         // the parent switches to A before the worker runs

        expect(pfDeliver($row)->status)->toBe(PushNotification::STATUS_SENT)
            ->and(pfTicketRows($row))->toBe([pfRow($f['a'])->id]);
    });

    it('a parent registration (back to parent mode) does not change the followed child', function () {
        $f = pfFamily();
        $s = pfSessions($f);
        pfExpo();
        pfRegister($s['a']);
        pfRegister($s['b']);
        pfRegister($s['parent']);
        pfRegister($s['parent']); // cold start in parent mode

        expect(pfRow($f['parent'])->followed_at)->toBeNull();
        expect(pfDeliver(pfDecide($f['petB']))->status)->toBe(PushNotification::STATUS_SENT)
            ->and(pfDeliver(pfDecide($f['petA']))->suppressed_reason)->toBe('no_devices');
    });

    it('parent pushes reach the parent row for every pet; the unfollowed child row is skipped', function () {
        $f = pfFamily();
        $s = pfSessions($f);
        $sent = pfExpo();
        pfRegister($s['parent']);
        pfRegister($s['a']);
        pfRegister($s['b']);
        $parentRow = pfRow($f['parent'])->id;

        foreach (['petA', 'petB'] as $pet) {
            $row = pfDeliver(pfDecide($f[$pet], PushType::ParentAlarm));
            expect($row->status)->toBe(PushNotification::STATUS_SENT)
                ->and(pfTicketRows($row))->toBe([$parentRow]);
        }

        // Illness = parents + caretakers: the parent row always, A's child row only if followed.
        $illA = pfDeliver(pfDecide($f['petA'], PushType::Illness));
        $illB = pfDeliver(pfDecide($f['petB'], PushType::Illness));
        expect(pfTicketRows($illA))->toBe([$parentRow])
            ->and(pfTicketRows($illB))->toBe(collect([$parentRow, pfRow($f['b'])->id])->sort()->values()->all())
            ->and($sent)->toHaveCount(5);
    });

    it('keeps today\'s behaviour for legacy rows without a follow stamp', function () {
        $f = pfFamily();
        pfExpo();
        foreach (['a', 'b'] as $key) {
            DevicePushToken::create([
                'user_id' => $f[$key]->id,
                'expo_push_token' => PF_PHONE,
                'platform' => 'android',
                'locale' => 'sl',
                'last_seen_at' => now(),
            ]);
        }

        expect(pfTicketRows(pfDeliver(pfDecide($f['petA']))))->toBe([pfRow($f['a'])->id])
            ->and(pfTicketRows(pfDeliver(pfDecide($f['petB']))))->toBe([pfRow($f['b'])->id]);
    });

    it('a child\'s own phone is unchanged; two phones that last opened the same pet both get it', function () {
        $f = pfFamily();
        $s = pfSessions($f);
        pfExpo();
        $ownPhone = 'ExponentPushToken[childAOwnPhone0000000]';
        $tablet = 'ExponentPushToken[familyTabletFollow0001]';
        $ownToken = $f['a']->createToken('iPhone', ['child'])->plainTextToken;

        pfRegister($ownToken, $ownPhone);   // A's own phone: one child row
        pfRegister($s['a']);                 // shared phone: A …
        pfRegister($s['b']);                 // … then B (followed there)
        pfRegister($s['b'], $tablet);        // the tablet also last opened B

        // A still gets its pet's push on the own phone, not on the shared phone.
        expect(pfTicketRows(pfDeliver(pfDecide($f['petA']))))->toBe([pfRow($f['a'], $ownPhone)->id]);
        // B's push reaches both phones that last opened B's pet.
        expect(pfTicketRows(pfDeliver(pfDecide($f['petB']))))
            ->toBe(collect([pfRow($f['b'])->id, pfRow($f['b'], $tablet)->id])->sort()->values()->all());
    });
});
