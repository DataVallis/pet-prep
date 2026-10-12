<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\CareRefusal;
use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScheduleService;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-F11 — "Dolij, ko se prazni" (David 2026-10-12)
|--------------------------------------------------------------------------
|
| David's report: a child who watered at 08, 11 and 14 could not water at
| 17:00 although thirst showed 72 % — the app said "tomorrow" (daily cap of
| water_times_per_day = 3). New rule: no daily cap; water when the LIVE
| thirst shows ≤ breed `water_refill_below` (60) AND the minimum gap
| (`water_min_gap_minutes`, dog 180 / cat 240) passed. Refusal stays
| `water_too_soon` + next_allowed_at = later of the gap end and the forecast
| minute thirst shows ≤ the threshold (same decay as the tick: dog mutt
| −10 %/h, cat −8 %/h, ×0.1 in quiet hours — default 21:00–07:00).
|
| Family in Europe/Ljubljana, CEST (UTC+2) on 2026-10-04 (a Sunday).
*/

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Local Ljubljana wall clock → test now. */
function wrAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A child with a legacy mutt (or a young domestic cat), born the day before,
 * acting as that child. Default quiet hours 21:00–07:00 stay on.
 *
 * @return array{0: User, 1: Pet}
 */
function wrChild(string $local = '2026-10-04 17:00', string $species = 'dog'): array
{
    wrAt($local);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $attributes = [
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-03 08:00', 'Europe/Ljubljana')->utc(),
        'last_decay_at' => now(),
    ];
    if ($species === 'cat') {
        seedLifeStageData();
        $attributes += ['breed_type' => BreedType::DomesticCat->value, 'arrival_age_months' => 12, 'origin' => 'adopted'];
    }
    $pet = disableHygieneEvents(Pet::factory()->create($attributes));

    actingAsRole($child);

    return [$child, $pet->fresh()];
}

/** Precise metrics, no decay owed (last_decay_at = now unless given). */
function wrSet(Pet $pet, array $attributes): Pet
{
    Pet::whereKey($pet->id)->update(array_merge(['last_decay_at' => now()], $attributes));

    return $pet->refresh();
}

function wrWatered(Pet $pet, User $child, string $local): void
{
    ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
        'pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::WateredPet,
        'value' => 50, 'created_at' => Carbon::parse($local, 'Europe/Ljubljana')->utc(),
    ])->save());
}

function wrWaterRows(Pet $pet): int
{
    return ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::WateredPet->value)->count();
}

describe('David\'s report: no daily cap', function () {
    it('allows water at 72 % at 17:00 after three refills today (08, 11, 14)', function () {
        [$child, $pet] = wrChild('2026-10-04 17:00');
        foreach (['2026-10-04 08:00', '2026-10-04 11:00', '2026-10-04 14:00'] as $at) {
            wrWatered($pet, $child, $at);
        }
        // Stored 82 % at 16:00 → live 72 % at 17:00 (the tick has not run since).
        wrSet($pet, ['thirst_level' => 82, 'last_decay_at' => now()->subHour()]);

        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('water.can_water', false)
            ->assertJsonPath('water.refill_below', 60);

        // 72 % is above 60 → still refused, but with today's time, never "tomorrow":
        // 72 − t/6 < 60.5 ⇔ t > 69 min (60.5 at 18:09 rounds up to 61) → 18:10.
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:10:00+02:00');
    });

    it('allows water once thirst shows ≤ 60 % after three refills (the fourth refill of the day)', function () {
        [$child, $pet] = wrChild('2026-10-04 17:00');
        foreach (['2026-10-04 08:00', '2026-10-04 11:00', '2026-10-04 14:00'] as $at) {
            wrWatered($pet, $child, $at);
        }
        wrSet($pet, ['thirst_level' => 60]);

        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('state.pet.thirst_level', 100)
            ->assertJsonPath('state.water.used_today', 4)
            ->assertJsonPath('state.water.remaining_today', 0)
            ->assertJsonPath('state.water.times_per_day', 3);

        expect(wrWaterRows($pet))->toBe(4);
    });

    it('never refuses with water_daily_limit, also after 5 refills in one day', function () {
        [, $pet] = wrChild('2026-10-04 07:00');
        foreach (['07:00', '10:00', '13:00', '16:00', '19:00'] as $time) {
            wrAt("2026-10-04 {$time}");
            wrSet($pet, ['thirst_level' => 50]);
            $this->postJson('/api/child/pet/water')->assertOk();
        }

        expect(wrWaterRows($pet))->toBe(5);

        wrAt('2026-10-04 22:00');
        wrSet($pet, ['thirst_level' => 40]);
        $this->postJson('/api/child/pet/water')->assertOk()->assertJsonPath('state.water.used_today', 6);
    });

    it('is the live thirst that decides, not the stored value of the last tick', function () {
        [, $pet] = wrChild('2026-10-04 17:00');
        // Stored 80 % two hours ago → 60 % now.
        wrSet($pet, ['thirst_level' => 80, 'last_decay_at' => now()->subHours(2)]);

        $this->getJson('/api/child/pet')->assertOk()->assertJsonPath('water.can_water', true)
            ->assertJsonPath('water.next_allowed_at', null);
        $this->postJson('/api/child/pet/water')->assertOk();
    });
});

describe('above the threshold: water_too_soon with the forecast time', function () {
    it('dog: 80 % at 17:00 → 18:58 (−10 %/h)', function () {
        [$child, $pet] = wrChild('2026-10-04 17:00');
        wrWatered($pet, $child, '2026-10-04 12:00'); // gap long over
        wrSet($pet, ['thirst_level' => 80]);

        // 80 − t/6 < 60.5 ⇔ t > 117 min (60.5 at 117 rounds up to 61).
        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('water.can_water', false)
            ->assertJsonPath('water.next_allowed_at', '2026-10-04T18:58:00+02:00');
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:58:00+02:00');

        wrAt('2026-10-04 18:57');
        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'water_too_soon');
        wrAt('2026-10-04 18:58');
        $this->postJson('/api/child/pet/water')->assertOk();
    });

    it('cat: 70 % at 17:00 → 18:12 (−8 %/h)', function () {
        [$child, $pet] = wrChild('2026-10-04 17:00', 'cat');
        wrWatered($pet, $child, '2026-10-04 12:00'); // 300 min ago > 240
        wrSet($pet, ['thirst_level' => 70]);

        // 70 − 0.1333·t < 60.5 ⇔ t > 71.25 min.
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:12:00+02:00')
            ->assertJsonPath('state.water.refill_below', 60)
            ->assertJsonPath('state.water.min_gap_minutes', 240);
    });

    it('dog across quiet hours (21:00–07:00, ×0.1): 75.23 % at 20:00 → 01:44', function () {
        [, $pet] = wrChild('2026-10-04 20:00');
        wrSet($pet, ['thirst_level' => 75.23]);

        // 65.23 at 21:00, then −1 %/h: > 4.73 h more → 284 min after 21:00.
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-05T01:44:00+02:00');
    });

    it('cat across quiet hours: 66 % at 20:30 → 22:53', function () {
        [, $pet] = wrChild('2026-10-04 20:30', 'cat');
        wrSet($pet, ['thirst_level' => 66]);

        // 62 at 21:00, then −0.8 %/h: > 1.5 more → 113 min after 21:00.
        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('water.next_allowed_at', '2026-10-04T22:53:00+02:00');
    });

    it('reads the threshold from breed_configs', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['water_refill_below' => 80]);
        [, $pet] = wrChild('2026-10-04 17:00');
        wrSet($pet, ['thirst_level' => 80]);

        $this->postJson('/api/child/pet/water')->assertOk();
        $this->getJson('/api/child/pet')->assertJsonPath('water.refill_below', 80);
    });
});

describe('the minimum gap still applies', function () {
    it('thirst below the threshold but inside the gap → water_too_soon at the gap end', function () {
        [$child, $pet] = wrChild('2026-10-04 16:00');
        wrWatered($pet, $child, '2026-10-04 15:00');
        wrSet($pet, ['thirst_level' => 40]);

        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:00:00+02:00');
    });

    it('both: the later of the gap end and the threshold minute', function () {
        [$child, $pet] = wrChild('2026-10-04 16:00');
        wrWatered($pet, $child, '2026-10-04 15:00'); // gap until 18:00
        wrSet($pet, ['thirst_level' => 90]); // 90 − t/6 < 60.5 ⇔ t > 177 min → 18:58

        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:58:00+02:00');

        wrSet($pet, ['thirst_level' => 70]); // ≤ 60 at 17:00 → the gap end wins
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2026-10-04T18:00:00+02:00');
    });
});

describe('no forecast', function () {
    it('a zero thirst rate above the threshold gives no time', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['thirst_decay_rate' => 0]);
        [, $pet] = wrChild('2026-10-04 17:00');
        wrSet($pet, ['thirst_level' => 90]);

        $check = app(CareScheduleService::class)->waterCheck($pet->fresh(), $pet->breedConfig(), now());
        expect($check->allowed)->toBeFalse()
            ->and($check->refusal)->toBe(CareRefusal::WaterTooSoon)
            ->and($check->nextAllowedAt)->toBeNull();
    });

    it('a frozen (hard-stopped) pet does not decay: no time, and the stored value counts', function () {
        [, $pet] = wrChild('2026-10-04 17:00');
        wrSet($pet, ['thirst_level' => 90, 'last_decay_at' => now()->subHours(5)]);
        Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]);
        $pet->refresh();

        $water = app(CareScheduleService::class)->water($pet, $pet->breedConfig(), now());
        expect($water->thirstShown)->toBe(90)
            ->and($water->notThirsty)->toBeTrue()
            ->and($water->nextAllowedAt)->toBeNull();
    });
});

/* ───────────────────────────── Push copy ───────────────────────────── */

function wrDevice(User $user): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'sl', 'last_seen_at' => now(),
    ]);
}

function wrFakeExpo(): ArrayObject
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

function wrPush(Pet $pet, PushType $type): PushNotification
{
    config(['push.enabled' => true]);
    Queue::fake([SendPushNotification::class]);
    $row = app(NotificationService::class)->escalation($pet->fresh(), $type, 'thirst');
    expect($row)->not->toBeNull();
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

    return $row->fresh();
}

describe('pushes never ask for water the app refuses', function () {
    it('after three refills at 25 % the thirst reminder asks for water — and the water button accepts', function () {
        [$child, $pet] = wrChild('2026-10-04 20:00');
        withoutQuietHours($pet);
        foreach (['2026-10-04 08:00', '2026-10-04 11:00', '2026-10-04 14:00'] as $at) {
            wrWatered($pet, $child, $at);
        }
        wrSet($pet, ['thirst_level' => 25]);
        wrDevice($child);
        $sent = wrFakeExpo();

        $row = wrPush($pet, PushType::SoftWarning);

        expect($row->status)->toBe(PushNotification::STATUS_SENT)
            ->and($sent[0]['body'])->toBe('Tvoj kuža te milo gleda in kaže na prazno posodo za vodo.');
        $this->postJson('/api/child/pet/water')->assertOk();
    });

    it('thirst still above the breed threshold: the reminder names the forecast time, never "give water now"', function () {
        // A breed whose threshold is below the soft-warning level (30): 28 % is not thirsty enough.
        BreedConfig::where('breed_slug', 'mutt')->update(['water_refill_below' => 20]);
        [$child, $pet] = wrChild('2026-10-04 17:00');
        withoutQuietHours($pet);
        wrSet($pet, ['thirst_level' => 28]);
        wrDevice($child);
        $sent = wrFakeExpo();

        $row = wrPush($pet, PushType::SoftWarning);

        // 28 → < 20.5 at −10 %/h after 45 min → 17:46 (20.5 at 17:45 rounds up).
        expect($row->status)->toBe(PushNotification::STATUS_SENT)
            ->and($sent[0]['body'])->toBe('Tvoj kuža je žejen. Vodo mu lahko spet daš ob 17:46 — ne pozabi nanj.');
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T17:46:00+02:00');
    });

    it('no reminder when the forecast time is not today', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['water_refill_below' => 20]);
        [$child, $pet] = wrChild('2026-10-04 20:45');
        // Quiet hours from 21:00 slow the decay: 28 → 25.5 at 21:00, then −1 %/h → after midnight.
        wrSet($pet, ['thirst_level' => 28]);
        wrDevice($child);
        $sent = wrFakeExpo();

        $row = wrPush($pet, PushType::SoftWarning);

        expect($row->suppressed_reason)->toBe('not_actionable')->and($sent)->toHaveCount(0);
        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'water_too_soon');
    });
});
