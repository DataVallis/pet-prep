<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PushType;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\NotificationService;
use App\Services\Push\PushCopy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-R06-06b QA B1 (David 2026-10-09) — the child always hears about a mess
|--------------------------------------------------------------------------
|
| When hunger / thirst and hygiene reach 0 together, the phase push used to
| name food / water; if feeding / water was not possible again today the push
| was dropped (`not_actionable`), the ladder had already moved on and the pet
| got sick 6 h later without the child ever hearing about the mess. Now (both
| species): the ladder prefers hygiene on a tie while a mess is open, and a
| food / water reminder that cannot be acted on today but has a mess open is
| sent as the mess reminder (incl. tidy / scratcher variants). Plus the M3-12
| `*_wait` cases from QA m1.
*/

function pmpAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a born pet (no quiet hours) at $local and the child's English device.
 * Dog: mutt (water 3×, ≥ 180 min); cat: young domestic cat (water 2×, ≥ 240 min).
 * Both: feed windows 06–10 / 17–21.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function pmpFamily(string $species, string $local = '2026-10-21 07:00'): array
{
    config(['push.enabled' => true]);
    seedBreedConfigs();
    seedLifeStageData();
    pmpAt($local);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    withoutQuietHours($parent);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $attributes = [
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-20 08:00', 'Europe/Ljubljana')->utc(),
        'hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100,
    ];
    if ($species === 'cat') {
        $attributes += ['breed_type' => BreedType::DomesticCat->value, 'arrival_age_months' => 12, 'origin' => 'adopted'];
    }
    $pet = disableHygieneEvents(Pet::factory()->create($attributes));
    DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'en', 'last_seen_at' => now()]);

    return [$parent, $child, $pet->fresh()];
}

function pmpLog(Pet $pet, User $child, ActivityType $type, string $local): void
{
    ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
        'pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => $type,
        'value' => null, 'created_at' => Carbon::parse($local, 'Europe/Ljubljana')->utc(),
    ])->save());
}

/** Both meals of the day and every water refill are used (06–10 / 17–21 windows). */
function pmpDayUsedUp(Pet $pet, User $child, int $waters): void
{
    pmpLog($pet, $child, ActivityType::FedPet, '2026-10-21 07:00');
    pmpLog($pet, $child, ActivityType::FedPet, '2026-10-21 18:00');
    foreach (array_slice(['2026-10-21 07:05', '2026-10-21 12:00', '2026-10-21 17:00'], 0, $waters) as $at) {
        pmpLog($pet, $child, ActivityType::WateredPet, $at);
    }
}

function pmpOpen(Pet $pet, array $kinds): Pet
{
    foreach ($kinds as $kind) {
        PetHygieneEvent::create(['pet_id' => $pet->id, 'kind' => $kind, 'local_date' => $pet->localDate(now()),
            'scheduled_at' => now()->subMinutes(10), 'status' => HygieneEventStatus::Applied]);
    }
    Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subMinutes(10)]);

    return $pet->fresh();
}

function pmpCopy(Pet $pet, PushType $type, string $metric): ?array
{
    $row = new PushNotification(['type' => $type, 'metric' => $metric]);

    return (new ReflectionMethod(NotificationService::class, 'actionCopy'))->invoke(app(NotificationService::class), $row, $pet);
}

function pmpFakeExpo(): ArrayObject
{
    $sent = new ArrayObject;
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
        foreach ($request->data() as $m) {
            $sent->append($m);
        }

        return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 't-'.Str::random(8)], $request->data())]);
    }]);

    return $sent;
}

afterEach(function () {
    Carbon::setTestNow();
});

describe('QA B1: hunger / thirst and hygiene at 0 together after the day is used up (escalation end to end)', function () {
    it('sends the child the mess push, not nothing', function (string $species, string $zeroMetric, HygieneEventKind $mess, string $body) {
        [, $child, $pet] = pmpFamily($species);
        pmpDayUsedUp($pet, $child, $species === 'cat' ? 2 : 3);
        pmpAt('2026-10-21 21:30');
        Pet::whereKey($pet->id)->update([$zeroMetric.'_level' => 0, $zeroMetric.'_zero_since' => now()->subMinutes(10), 'escalation_level' => 1]);
        $pet = pmpOpen($pet->fresh(), [$mess]);
        $sent = pmpFakeExpo();

        app(EscalationService::class)->processPetEscalation($pet);

        $row = PushNotification::where('pet_id', $pet->id)->sole();
        expect($pet->fresh()->escalation_level)->toBe(2)
            ->and($row->type)->toBe(PushType::CriticalAlert)
            ->and($row->metric)->toBe('hygiene')
            ->and($row->status)->toBe(PushNotification::STATUS_SENT)
            ->and(collect($sent->getArrayCopy())->pluck('body')->all())->toBe([$body]);
    })->with([
        'dog, hunger + poop' => ['dog', 'hunger', HygieneEventKind::Poop, 'Your dog made a mess! Clean it up as soon as you can, or it will get sick.'],
        'dog, thirst + chewing' => ['dog', 'thirst', HygieneEventKind::Chewing, 'Your dog chewed a slipper! Tidy it up and give it a toy as soon as you can, or it will get sick.'],
        'cat, hunger + litter accident' => ['cat', 'hunger', HygieneEventKind::LitterAccident, 'Your cat made a mess next to the litter tray! Clean it up as soon as you can, or it will get sick.'],
        'cat, thirst + scratching' => ['cat', 'thirst', HygieneEventKind::Scratching, 'Your cat scratched the sofa! Carry it to the scratching post and praise it as soon as you can, or it will get sick.'],
    ]);

    it('a food reminder already queued for hunger is delivered as the mess text when feeding is over for today', function () {
        [, $child, $dog] = pmpFamily('dog');
        pmpDayUsedUp($dog, $child, 3);
        pmpAt('2026-10-21 21:30');
        Pet::whereKey($dog->id)->update(['hunger_level' => 5]);
        $dog = pmpOpen($dog->fresh(), [HygieneEventKind::Poop]);

        expect(pmpCopy($dog, PushType::SoftWarning, 'hunger'))->toBe(['variant' => null, 'replace' => [], 'metric' => 'hygiene']);

        $sent = pmpFakeExpo();
        $row = app(NotificationService::class)->escalation($dog, PushType::SoftWarning, 'hunger');
        expect($row->fresh()->status)->toBe(PushNotification::STATUS_SENT)
            ->and(collect($sent->getArrayCopy())->pluck('body')->all())
            ->toBe(['Your dog is giving you a gentle look and pointing at a mess that needs cleaning up.']);
    });
});

describe('QA m1: the remaining M3-12 combinations', function () {
    it('dog thirst inside the water gap with a mess: the next water time (`clean_first_wait`)', function () {
        [, $child, $dog] = pmpFamily('dog', '2026-10-21 12:00');
        pmpLog($dog, $child, ActivityType::WateredPet, '2026-10-21 11:00');
        pmpAt('2026-10-21 12:30');
        Pet::whereKey($dog->id)->update(['thirst_level' => 25]);
        $dog = pmpOpen($dog->fresh(), [HygieneEventKind::Poop]);

        $copy = pmpCopy($dog, PushType::SoftWarning, 'thirst');
        expect($copy)->toBe(['variant' => PushCopy::VARIANT_CLEAN_FIRST_WAIT, 'replace' => ['time' => '14:00']])
            ->and(PushCopy::body(PushType::SoftWarning, 'thirst', 'child', 'en', $copy['variant'], $copy['replace']))
            ->toBe('Your dog is thirsty, but the mess has to be cleaned up first. You can give it water again at 14:00.');
    });

    it('thirst after the daily water limit with a mess: the mess push (dog and cat)', function (string $species, string $body) {
        [, $child, $pet] = pmpFamily($species, '2026-10-21 07:00');
        pmpDayUsedUp($pet, $child, $species === 'cat' ? 2 : 3);
        pmpAt('2026-10-21 19:00');
        Pet::whereKey($pet->id)->update(['thirst_level' => 25]);
        $pet = pmpOpen($pet->fresh(), [$species === 'cat' ? HygieneEventKind::LitterAccident : HygieneEventKind::Poop]);

        $copy = pmpCopy($pet, PushType::SoftWarning, 'thirst');
        expect($copy)->toBe(['variant' => null, 'replace' => [], 'metric' => 'hygiene'])
            ->and(PushCopy::body(PushType::SoftWarning, $copy['metric'], 'child', 'en', $copy['variant'], [], $pet->speciesValue()))->toBe($body);
    })->with([
        'dog' => ['dog', 'Your dog is giving you a gentle look and pointing at a mess that needs cleaning up.'],
        'cat' => ['cat', 'Your cat is giving you a gentle look — there’s a mess next to the litter tray that needs cleaning up.'],
    ]);

    it('chewing open after the last meal: the tidy push (critical: the critical tidy text)', function () {
        [, $child, $dog] = pmpFamily('dog');
        pmpDayUsedUp($dog, $child, 3);
        pmpAt('2026-10-21 21:30');
        Pet::whereKey($dog->id)->update(['hunger_level' => 5]);
        $dog = pmpOpen($dog->fresh(), [HygieneEventKind::Chewing]);

        $copy = pmpCopy($dog, PushType::CriticalAlert, 'hunger');
        expect($copy)->toBe(['variant' => PushCopy::VARIANT_TIDY, 'replace' => [], 'metric' => 'hygiene'])
            ->and(PushCopy::body(PushType::CriticalAlert, $copy['metric'], 'child', 'sl', $copy['variant']))
            ->toBe('Kuža je pregriznil copat! Pospravi in mu daj igračo čim prej, sicer bo zbolel.');
    });

    it('`_wait` on a critical alert, delivered end to end (cat scratching, next meal 17:00)', function () {
        [, $child, $cat] = pmpFamily('cat');
        pmpLog($cat, $child, ActivityType::FedPet, '2026-10-21 07:00');
        pmpAt('2026-10-21 15:45');
        Pet::whereKey($cat->id)->update(['hunger_level' => 8]);
        $cat = pmpOpen($cat->fresh(), [HygieneEventKind::Scratching]);
        $sent = pmpFakeExpo();

        app(NotificationService::class)->escalation($cat, PushType::CriticalAlert, 'hunger');

        expect(collect($sent->getArrayCopy())->pluck('body')->all())
            ->toBe(['Your cat is hungry, but first carry it to the scratching post and praise it. The next meal is at 17:00.']);
    });

    it('the ladder still names food when hunger is lower and no mess is open', function () {
        [, , $dog] = pmpFamily('dog', '2026-10-21 12:00');
        Pet::whereKey($dog->id)->update(['hunger_level' => 0, 'hunger_zero_since' => now()->subMinutes(10), 'thirst_level' => 0, 'thirst_zero_since' => now()->subMinutes(10)]);
        pmpFakeExpo();

        app(EscalationService::class)->processPetEscalation($dog->fresh());

        expect(PushNotification::where('pet_id', $dog->id)->sole()->metric)->toBe('hunger');
    });
});
