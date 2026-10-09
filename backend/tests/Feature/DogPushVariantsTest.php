<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\HygieneEventStatus;
use App\Enums\PushType;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| M5-R06-06b — dog push decisions (David 2026-10-09)
|--------------------------------------------------------------------------
|
| M3-12 for a dog, now like the cat: (1) while a chewed item is open, food /
| water reminders say "tidy first" (cleaning does not resolve chewing), with
| another mess "clean and tidy first"; (2) if the meal / water would still be
| refused once the mess is resolved, the reminder gives the time
| (`*_first_wait`) instead of "then you can feed it"; nothing more today → no
| reminder. (3) The Slovenian parent alarm addresses parents formally
| ("Vaš otrok") — child texts keep "ti".
*/

function dpvAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a born mutt (no quiet hours) at $local; feed windows 06–10 / 17–21.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function dpvFamily(string $local = '2026-10-21 08:00'): array
{
    config(['push.enabled' => true]);
    seedBreedConfigs();
    dpvAt($local);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    withoutQuietHours($parent);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $dog = disableHygieneEvents(Pet::factory()->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-10-20 08:00', 'Europe/Ljubljana')->utc(),
        'hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100,
    ]));

    return [$parent, $child, $dog->fresh()];
}

function dpvOpen(Pet $pet, array $kinds): Pet
{
    foreach ($kinds as $kind) {
        PetHygieneEvent::create(['pet_id' => $pet->id, 'kind' => $kind, 'local_date' => $pet->localDate(now()),
            'scheduled_at' => now()->subMinutes(30), 'status' => HygieneEventStatus::Applied]);
    }
    Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subMinutes(30)]);

    return $pet->fresh();
}

function dpvFed(Pet $pet, User $child, string $local): void
{
    ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
        'pet_id' => $pet->id, 'actor_user_id' => $child->id, 'activity_type' => ActivityType::FedPet,
        'value' => null, 'created_at' => Carbon::parse($local, 'Europe/Ljubljana')->utc(),
    ])->save());
}

function dpvCopy(Pet $pet, PushType $type, string $metric): ?array
{
    $row = new PushNotification(['type' => $type, 'metric' => $metric]);

    return (new ReflectionMethod(NotificationService::class, 'actionCopy'))->invoke(app(NotificationService::class), $row, $pet);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('chewing open, feeding possible: "tidy first", never "clean first" (EN + SL)', function (array $kinds, string $variant, string $en, string $sl) {
    [, , $dog] = dpvFamily();
    $dog = dpvOpen($dog, $kinds);

    expect(dpvCopy($dog, PushType::SoftWarning, 'hunger'))->toBe(['variant' => $variant, 'replace' => []])
        ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'en', $variant))->toBe($en)
        ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'sl', $variant))->toBe($sl);
})->with([
    'chewing only' => [[HygieneEventKind::Chewing], PushCopy::VARIANT_TIDY_FIRST,
        'Your dog is hungry, but first tidy up what it chewed and give it a toy. Then you can feed it.',
        'Tvoj kuža je lačen, a najprej pospravi pregrizeno in mu daj igračo. Potem ga lahko nahraniš.'],
    'chewing + poop' => [[HygieneEventKind::Chewing, HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_AND_TIDY_FIRST,
        'Your dog is hungry, but first clean up the mess, tidy away what it chewed and give it a toy. Then you can feed it.',
        'Tvoj kuža je lačen, a najprej počisti nered, pospravi pregrizeno in mu daj igračo. Potem ga lahko nahraniš.'],
    'chewing + accident' => [[HygieneEventKind::Chewing, HygieneEventKind::Accident], PushCopy::VARIANT_CLEAN_AND_TIDY_FIRST,
        'Your dog is hungry, but first clean up the mess, tidy away what it chewed and give it a toy. Then you can feed it.',
        'Tvoj kuža je lačen, a najprej počisti nered, pospravi pregrizeno in mu daj igračo. Potem ga lahko nahraniš.'],
    'poop only (unchanged)' => [[HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_FIRST,
        'Your dog is hungry, but the mess has to be cleaned up first. Then you can feed it.',
        'Tvoj kuža je lačen, a najprej je treba počistiti nered. Potem ga lahko nahraniš.'],
]);

it('water while chewing is open: "tidy first, then you can give it water"', function () {
    [, , $dog] = dpvFamily();
    $dog = dpvOpen($dog, [HygieneEventKind::Chewing]);

    $copy = dpvCopy($dog, PushType::CriticalAlert, 'thirst');
    expect($copy)->toBe(['variant' => PushCopy::VARIANT_TIDY_FIRST, 'replace' => []])
        ->and(PushCopy::body(PushType::CriticalAlert, 'thirst', 'child', 'sl', $copy['variant']))
        ->toBe('Tvoj kuža je žejen, a najprej pospravi pregrizeno in mu daj igračo. Potem mu lahko daš vodo.');
});

it('fed at 07:00, hungry at 15:45 with a mess: the next meal time, never "then you can feed it"', function (array $kinds, string $variant, string $en, string $sl) {
    [, $child, $dog] = dpvFamily('2026-10-21 07:00');
    dpvFed($dog, $child, '2026-10-21 07:00');
    dpvAt('2026-10-21 15:45');
    Pet::whereKey($dog->id)->update(['hunger_level' => 30]);
    $dog = dpvOpen($dog->fresh(), $kinds);

    $copy = dpvCopy($dog, PushType::SoftWarning, 'hunger');
    expect($copy)->toBe(['variant' => $variant, 'replace' => ['time' => '17:00']])
        ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'en', $copy['variant'], $copy['replace']))->toBe($en)->not->toContain('Then you can')
        ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'sl', $copy['variant'], $copy['replace']))->toBe($sl);
})->with([
    'poop' => [[HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_FIRST_WAIT,
        'Your dog is hungry, but the mess has to be cleaned up first. The next meal is at 17:00.',
        'Tvoj kuža je lačen, a najprej je treba počistiti nered. Naslednji obrok je ob 17:00.'],
    'chewing' => [[HygieneEventKind::Chewing], PushCopy::VARIANT_TIDY_FIRST_WAIT,
        'Your dog is hungry, but first tidy up what it chewed and give it a toy. The next meal is at 17:00.',
        'Tvoj kuža je lačen, a najprej pospravi pregrizeno in mu daj igračo. Naslednji obrok je ob 17:00.'],
    'chewing + poop' => [[HygieneEventKind::Chewing, HygieneEventKind::Poop], PushCopy::VARIANT_CLEAN_AND_TIDY_FIRST_WAIT,
        'Your dog is hungry, but first clean up the mess, tidy away what it chewed and give it a toy. The next meal is at 17:00.',
        'Tvoj kuža je lačen, a najprej počisti nered, pospravi pregrizeno in mu daj igračo. Naslednji obrok je ob 17:00.'],
]);

it('a mess after the last meal of the day: no food reminder at all', function () {
    [, $child, $dog] = dpvFamily('2026-10-21 07:00');
    dpvFed($dog, $child, '2026-10-21 07:00');
    dpvFed($dog, $child, '2026-10-21 18:00');
    dpvAt('2026-10-21 21:30');
    Pet::whereKey($dog->id)->update(['hunger_level' => 30]);
    $dog = dpvOpen($dog->fresh(), [HygieneEventKind::Poop]);

    expect(dpvCopy($dog, PushType::SoftWarning, 'hunger'))->toBeNull();
});

it('sends parents the Slovenian alarm formally ("Vaš otrok"); English and the children\'s texts are unchanged', function () {
    expect(PushCopy::body(PushType::ParentAlarm, 'hunger', 'parent', 'sl'))->toBe('Vaš otrok danes ni poskrbel za psa. Kuža je že več kot uro brez hrane.')
        ->and(PushCopy::body(PushType::ParentAlarm, 'hunger', 'parent', 'en'))->toBe('Your child hasn’t looked after the dog today. The dog has had no food for over an hour.')
        ->and(PushCopy::body(PushType::SoftWarning, 'hunger', 'child', 'sl'))->toBe('Tvoj kuža te milo gleda in kaže na posodo s hrano.');

    // No parent-facing Slovenian text addresses the parent with "ti".
    $sl = require lang_path('sl/push.php');
    $parentTexts = [$sl['parent_alarm'], $sl['cat']['parent_alarm'], ...array_values($sl['parent_alarm_detail']),
        ...array_values($sl['illness']['parent']), $sl['game_over']['parent'], $sl['trial_ending'],
        $sl['payment_required']['parent'], $sl['payment_required']['parent_no_trial'],
        ...array_values($sl['cat']['illness']['parent']), $sl['cat']['game_over']['parent'],
        $sl['cat']['payment_required']['parent'], $sl['cat']['payment_required']['parent_no_trial']];
    foreach ($parentTexts as $text) {
        expect(preg_match('/(?<!\p{L})(tvoj\p{L}*|ti|te|tebi)(?!\p{L})/iu', $text))->toBe(0, $text);
    }
});

it('delivers the dog\'s "tidy first" through Expo', function () {
    [, $child, $dog] = dpvFamily();
    DevicePushToken::create(['user_id' => $child->id, 'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
        'platform' => 'ios', 'app_version' => '1.0.0', 'locale' => 'en', 'last_seen_at' => now()]);
    $dog = dpvOpen($dog, [HygieneEventKind::Chewing]);
    Pet::whereKey($dog->id)->update(['hunger_level' => 25]);

    $sent = new ArrayObject;
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['https://exp.host/--/api/v2/push/send' => function (Request $request) use ($sent) {
        foreach ($request->data() as $m) {
            $sent->append($m);
        }

        return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 't-'.Str::random(8)], $request->data())]);
    }]);
    Queue::fake([SendPushNotification::class]);
    $row = app(NotificationService::class)->escalation($dog->fresh(), PushType::SoftWarning, 'hunger');
    (new SendPushNotification($row->id))->handle(app(NotificationService::class), app(ExpoPushClient::class));

    expect(collect($sent->getArrayCopy())->pluck('body')->all())
        ->toBe(['Your dog is hungry, but first tidy up what it chewed and give it a toy. Then you can feed it.']);
});
