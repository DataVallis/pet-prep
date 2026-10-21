<?php

use App\Enums\PetStatusPeriodKind;
use App\Enums\PushType;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Jobs\SendPushNotification;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetStatusPeriod;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\LifeStageService;
use App\Services\PetBirthdayService;
use App\Services\Push\PushCopy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-T13 — the pet's first birthday (APP_REFINEMENT_SPEC §9, David 2026-10-20)
|--------------------------------------------------------------------------
| The birthday is the moment the pet reaches 12 months by the simulation's age
| formula (arrival age + one month per program week; payment-lock time is not
| program time). Once per pet. Due from the family-local start of that day and
| shown the whole day; the tick celebrates it once — one PetUpdated
| `pet_birthday`, one push to the caretaker children and the parents (title =
| name, body without it, payload {type, pet_id}), held over quiet hours.
*/

const PB_BORN = '2026-10-07 10:00:00'; // Wed 12:00 in Ljubljana (CEST)

beforeEach(function () {
    Http::preventStrayRequests();
    seedLifeStageData();
    config(['push.enabled' => true]);
});

/** Pushes stay queued (not sent) — every describe except "push delivery". */
function pbHoldPushes(): void
{
    Queue::fake([SendPushNotification::class]);
}

function pbAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born, purchased Border Collie of a child (profile: arrival age), born at PB_BORN unless given.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function pbFamily(int $arrival = 2, array $attributes = [], string $tz = 'Europe/Ljubljana', bool $quiet = false, string $born = PB_BORN): array
{
    pbAt($born);
    $parent = User::factory()->parent()->create(['timezone' => $tz]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quiet) {
        setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'is_active' => true]);
    } else {
        withoutQuietHours($parent);
    }
    $pet = disableHygieneEvents(Pet::factory()->borderCollie()->purchased()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($born, 'UTC'),
        'arrival_age_months' => $arrival,
        'name' => 'Luna',
    ], $attributes)));

    return [$parent, $child, $pet->fresh()];
}

function pbDevice(User $user, string $locale = 'sl'): DevicePushToken
{
    return DevicePushToken::create([
        'user_id' => $user->id,
        'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios',
        'app_version' => '1.0.0',
        'locale' => $locale,
        'last_seen_at' => now(),
    ]);
}

function pbTick(): array
{
    return app(PetBirthdayService::class)->processDue();
}

describe('birthday date', function () {
    beforeEach(fn () => pbHoldPushes());

    it('is the family-local day the age formula reaches 12 months — the same instant ageMonthsAt counts', function () {
        [, , $pet] = pbFamily(2);
        $service = app(PetBirthdayService::class);

        // 10 program weeks after 2026-10-07 12:00 CEST = 2026-12-16 12:00 CET (11:00 UTC).
        $at = $service->birthdayAt($pet, now());
        expect($at->toIso8601String())->toBe('2026-12-16T11:00:00+00:00')
            ->and($service->birthdayDate($pet, now()))->toBe('2026-12-16');

        $ages = app(LifeStageService::class);
        expect($ages->ageMonthsAt($pet, $at->subSecond()))->toBe(11)
            ->and($ages->ageMonthsAt($pet, $at))->toBe(12);
    });

    it('uses the family timezone for the date (New York: still the 15th when it is the 16th in UTC)', function () {
        // 2026-10-06 22:00 EDT; 10 weeks later 2026-12-15 22:00 EST = 2026-12-16 03:00 UTC.
        [, , $pet] = pbFamily(2, [], 'America/New_York', false, '2026-10-07 02:00:00');

        expect(app(PetBirthdayService::class)->birthdayAt($pet, now())->toIso8601String())->toBe('2026-12-16T03:00:00+00:00')
            ->and(app(PetBirthdayService::class)->birthdayDate($pet, now()))->toBe('2026-12-15');

        // Due from 00:00 New York time on the 15th (05:00 UTC), not before.
        pbAt('2026-12-15 04:59:59');
        expect(pbTick()['celebrated'])->toBe(0)
            ->and(app(PetBirthdayService::class)->summary($pet->fresh())->isToday)->toBeFalse();
        pbAt('2026-12-15 05:00:00');
        expect(app(PetBirthdayService::class)->summary($pet->fresh())->isToday)->toBeTrue()
            ->and(pbTick()['celebrated'])->toBe(1);
    });

    it('moves later by the payment-lock time before it (program clock)', function () {
        [, , $pet] = pbFamily(2);
        // A 10-day lock in the 2nd week (closed).
        PetStatusPeriod::create([
            'pet_id' => $pet->id, 'kind' => PetStatusPeriodKind::PaymentLock->value,
            'started_at' => Carbon::parse('2026-10-14 10:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-10-24 10:00:00', 'UTC'),
        ]);
        $pet = $pet->fresh();
        pbAt('2026-11-01 10:00:00');

        $service = app(PetBirthdayService::class);
        $at = $service->birthdayAt($pet, now());
        expect($at->toIso8601String())->toBe('2026-12-26T11:00:00+00:00')
            ->and($service->birthdayDate($pet, now()))->toBe('2026-12-26');

        $ages = app(LifeStageService::class);
        expect($ages->ageMonthsAt($pet, $at->subSecond()))->toBe(11)
            ->and($ages->ageMonthsAt($pet, $at))->toBe(12);

        // Not celebrated on the lock-free date.
        pbAt('2026-12-16 12:00:00');
        expect(pbTick()['celebrated'])->toBe(0);
        pbAt('2026-12-26 08:00:00');
        expect(pbTick()['celebrated'])->toBe(1);
    });

    it('a young dog (arrival 9 months) turns 1 after 3 weeks; one that arrives at 12+ months, a legacy or an unborn pet has none', function () {
        [, , $young] = pbFamily(9);
        expect(app(PetBirthdayService::class)->birthdayDate($young, now()))->toBe('2026-10-28');

        [, , $adult] = pbFamily(36);
        [, , $legacy] = pbFamily(2, ['arrival_age_months' => null]);
        [, , $unborn] = pbFamily(2, ['born_at' => null]);
        foreach ([$adult, $legacy, $unborn] as $pet) {
            expect(app(PetBirthdayService::class)->summary($pet->fresh()))->toBeNull();
        }
    });
});

describe('celebration', function () {
    beforeEach(fn () => pbHoldPushes());

    it('celebrates once at the family-local start of the day: one PetUpdated, one push to children and parents', function () {
        [$parent, $child, $pet] = pbFamily(2);
        pbDevice($child);
        pbDevice($parent);

        // 2026-12-16 00:00 in Ljubljana = 2026-12-15 23:00 UTC.
        pbAt('2026-12-15 22:59:59');
        Event::fake([PetUpdated::class]);
        expect(pbTick()['celebrated'])->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
        // The next look is scheduled for the local midnight (the tick doesn't recompute every minute).
        expect($pet->fresh()->birthday_next_check_at->toIso8601String())->toBe('2026-12-15T23:00:00+00:00');

        pbAt('2026-12-15 23:00:00');
        expect(pbTick()['celebrated'])->toBe(1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'pet_birthday' && $e->petId === $pet->id);

        $push = PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetBirthday->value)->sole();
        expect(collect($push->recipients)->pluck('user_id')->sort()->values()->all())->toBe(collect([$parent->id, $child->id])->sort()->values()->all())
            ->and($push->status)->toBe(PushNotification::STATUS_QUEUED);

        // Later ticks the same day (and after) never celebrate again.
        foreach (['2026-12-16 08:00:00', '2026-12-16 22:00:00', '2027-01-20 10:00:00'] as $t) {
            pbAt($t);
            expect(pbTick()['celebrated'])->toBe(0);
        }
        expect(PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetBirthday->value)->count())->toBe(1);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('holds the push over quiet hours (midnight is inside the night window) instead of dropping it', function () {
        [, , $pet] = pbFamily(2, [], 'Europe/Ljubljana', true);
        pbAt('2026-12-15 23:00:00'); // 00:00 local, bedtime 21–07

        expect(pbTick()['celebrated'])->toBe(1);
        $push = PushNotification::where('pet_id', $pet->id)->where('type', PushType::PetBirthday->value)->sole();
        expect($push->status)->toBe(PushNotification::STATUS_SCHEDULED)
            ->and($push->send_after->toIso8601String())->toBe('2026-12-16T06:00:00+00:00'); // 07:00 local
    });

    it('waits while the pet is hard-stopped and celebrates once the lock is lifted the same day; a day spent fully locked passes', function () {
        [, , $pet] = pbFamily(2);
        Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]);

        pbAt('2026-12-15 23:00:00');
        expect(pbTick()['celebrated'])->toBe(0)
            ->and($pet->fresh()->birthday_celebrated_at)->toBeNull();

        Pet::whereKey($pet->id)->update(['is_hard_stopped' => false]);
        pbAt('2026-12-16 09:00:00');
        expect(pbTick()['celebrated'])->toBe(1);

        [, , $other] = pbFamily(2);
        Pet::whereKey($other->id)->update(['is_hard_stopped' => true]);
        pbAt('2026-12-16 10:00:00');
        pbTick();
        Pet::whereKey($other->id)->update(['is_hard_stopped' => false]);
        pbAt('2026-12-17 00:30:00'); // the next local day
        expect(pbTick()['celebrated'])->toBe(0)
            ->and($other->fresh()->birthday_celebrated_at)->toBeNull()
            ->and($other->fresh()->birthday_next_check_at->year)->toBe(2999);
    });

    it('has a cat text that never names a dog (SL »muca«, EN "kitty")', function () {
        foreach (['child', 'parent'] as $audience) {
            expect(PushCopy::body(PushType::PetBirthday, null, $audience, 'sl', null, [], Species::Cat))->toContain('uca')
                ->not->toContain('kuž')
                ->and(PushCopy::body(PushType::PetBirthday, null, $audience, 'en', null, [], Species::Cat))->toContain('kitty')
                ->not->toContain('pup');
        }
    });
});

describe('push delivery', function () {
    it('sends the name as the title and a body without it; data has only {type, pet_id}; parents read "vi"', function () {
        [$parent, $child, $pet] = pbFamily(2);
        pbDevice($child);
        pbDevice($parent);

        $sent = [];
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => function ($request) use (&$sent) {
                foreach ($request->data() as $message) {
                    $sent[] = $message;
                }

                return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'ticket-'.Str::random(8)], $request->data())]);
            },
        ]);

        pbAt('2026-12-16 08:00:00');
        pbTick();

        expect($sent)->toHaveCount(2);
        $bodies = collect($sent)->pluck('body')->sort()->values()->all();
        expect($bodies)->toBe(collect([
            PushCopy::body(PushType::PetBirthday, null, 'child', 'sl'),
            PushCopy::body(PushType::PetBirthday, null, 'parent', 'sl'),
        ])->sort()->values()->all());
        foreach ($sent as $message) {
            expect($message['title'])->toBe('Luna')
                ->and($message['body'])->not->toContain('Luna')
                ->and(array_keys($message['data']))->toEqualCanonicalizing(['type', 'pet_id'])
                ->and($message['data']['type'])->toBe('pet_birthday')
                ->and($message['priority'])->toBe('default');
        }
        expect(PushCopy::body(PushType::PetBirthday, null, 'parent', 'sl'))->toContain('oglejte');
    });
});

describe('API', function () {
    beforeEach(fn () => pbHoldPushes());

    it('exposes birthday {date, is_today, celebrated} in the child state and the parent dashboard', function () {
        [$parent, $child, $pet] = pbFamily(2);

        pbAt('2026-12-01 10:00:00');
        $this->actingAs($child, 'sanctum')->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.birthday', ['date' => '2026-12-16', 'is_today' => false, 'celebrated' => false]);

        pbAt('2026-12-16 08:00:00');
        pbTick();
        app('auth')->forgetGuards();
        $this->actingAs($child, 'sanctum')->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.birthday', ['date' => '2026-12-16', 'is_today' => true, 'celebrated' => true]);

        app('auth')->forgetGuards();
        $pets = $this->actingAs($parent, 'sanctum')->getJson('/api/parent/dashboard')->assertOk()->json('family.pets');
        expect($pets[0]['birthday'])->toBe(['date' => '2026-12-16', 'is_today' => true, 'celebrated' => true]);

        expect(PetUpdated::payloadFor($pet->fresh(), 'pet_birthday')['birthday'])->toBe(['date' => '2026-12-16', 'is_today' => true, 'celebrated' => true]);
    });

    it('is null for a pet without a first birthday in the simulation', function () {
        [, $child] = pbFamily(36);
        $this->actingAs($child, 'sanctum')->getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.birthday', null);
    });
});
