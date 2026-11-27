<?php

use App\Enums\HygieneEventKind;
use App\Enums\PushType;
use App\Models\DevicePushToken;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\BehaviourPayload;
use App\Services\EscalationService;
use App\Services\NotificationService;
use App\Services\PetDecayService;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\PushCopy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Puppy evening take-out (David 2026-11-26 »pred spanjem mora še ven«)
|--------------------------------------------------------------------------
| A puppy taken out at 13:06 got »jutri ob 7:30« because the hold clock
| stops in the night's quiet hours. New rule: the last
| `behaviour.puppy.evening_window_minutes` (120) before the NIGHT quiet hours
| start (bedtime, pet-local) are the evening window. When the normal hold
| would run into / past the night, the deadline is the night's start; a
| take-out inside the window is the evening outing (the morning rule
| follows); nobody took it out → no accident at bedtime, the accident
| happens at the END of the night (»Luža zjutraj«), once. Push at the start
| of the window, once a day, never in quiet hours.
|
| Times are pet-local unless they end in "UTC". Europe/Ljubljana is UTC+1 in
| November; 2026-11-09 is a Monday.
*/

beforeEach(function () {
    seedLifeStageData();
});

afterEach(function () {
    Carbon::setTestNow();
});

const PE_TZ = 'Europe/Ljubljana';

function peAt(string $local, string $tz = PE_TZ): Carbon
{
    $at = Carbon::parse($local, $tz)->utc();
    Carbon::setTestNow($at);

    return $at;
}

/**
 * A profiled 5-month puppy (holds 5 h) with behaviour events, poops off.
 * $quiet: family quiet-hour fields (null = quiet hours off); default night
 * 21:00–07:00, no school.
 *
 * @param  array<string, mixed>|null  $quiet
 * @return array{0: User, 1: User, 2: Pet}
 */
function peFamily(int $months = 5, ?array $quiet = ['bedtime_start' => '21:00', 'bedtime_end' => '07:00'], string $bornLocal = '2026-11-08 08:00'): array
{
    $born = peAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => PE_TZ]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    if ($quiet === null) {
        withoutQuietHours($parent);
    } else {
        setQuietHours(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quiet));
    }
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'born_at' => $born,
        'last_decay_at' => $born,
        'last_step_reset_at' => $born,
        'arrival_age_months' => $months,
        'behaviour_events_enabled' => true,
    ]);
    Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => '2999-12-31', 'behaviour_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

/** The puppy at $local: its clock last restarted at $clockLocal, the scheduler ran up to $local. */
function peClockAt(Pet $pet, string $local, string $clockLocal, string $tz = PE_TZ): Pet
{
    $at = peAt($local, $tz);
    Pet::whereKey($pet->id)->update([
        'potty_clock_started_at' => Carbon::parse($clockLocal, $tz)->utc(),
        'last_decay_at' => $at,
        'hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100,
    ]);

    return $pet->fresh();
}

/** One decay tick (+ escalation) at $local. */
function peTick(Pet $pet, string $local, string $tz = PE_TZ): Pet
{
    peAt($local, $tz);
    app(PetDecayService::class)->processPetDecay($pet);
    Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hunger_zero_since' => null, 'thirst_zero_since' => null]);
    app(EscalationService::class)->processPetEscalation($pet->fresh());

    return $pet->fresh();
}

/** Ticks every $step minutes in ($fromLocal, $toLocal]. */
function peRun(Pet $pet, string $fromLocal, string $toLocal, int $step = 5, string $tz = PE_TZ): Pet
{
    $end = Carbon::parse($toLocal, $tz);
    for ($t = Carbon::parse($fromLocal, $tz)->addMinutes($step); $t->lessThan($end); $t->addMinutes($step)) {
        $pet = peTick($pet, $t->format('Y-m-d H:i:s'), $tz);
    }

    return peTick($pet, $end->format('Y-m-d H:i:s'), $tz);
}

/** @return array{due: string, accident: string, evening: bool, morning: bool}|null pet-local "Y-m-d H:i" */
function peClock(Pet $pet, string $tz = PE_TZ): ?array
{
    $pet = $pet->fresh();
    $clock = app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours());

    return $clock === null ? null : [
        'due' => $clock['due_at']->setTimezone($tz)->format('Y-m-d H:i'),
        'accident' => $clock['accident_at']->setTimezone($tz)->format('Y-m-d H:i'),
        'evening' => $clock['evening'] !== null,
        'morning' => $clock['morning'] !== null,
    ];
}

function peDue(Pet $pet, string $tz = PE_TZ): ?string
{
    return peClock($pet, $tz)['due'] ?? null;
}

/** @return list<string> accident instants, pet-local "Y-m-d H:i" */
function peAccidents(Pet $pet, string $tz = PE_TZ): array
{
    return PetHygieneEvent::where('pet_id', $pet->id)->where('kind', HygieneEventKind::Accident->value)
        ->orderBy('scheduled_at')->get()
        ->map(fn ($e) => $e->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d H:i'))->all();
}

function peTakeOut(User $child, string $local, string $tz = PE_TZ): void
{
    peAt($local, $tz);
    actingAsRole($child);
    postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'accepted');
}

describe('the evening deadline', function () {
    it('becomes the start of the night when the hold would run into it (16:30 + 5 h → by 21:00, not »jutri ob 7:30«)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 16:25', '2026-11-09 07:10');
        peTakeOut($child, '2026-11-09 16:30');

        expect(peClock($pet))->toBe(['due' => '2026-11-09 21:00', 'accident' => '2026-11-10 07:00', 'evening' => true, 'morning' => false]);
        $takeOut = BehaviourPayload::for($pet->fresh())->takeOut;
        expect($takeOut['next_due_at'])->toBe('2026-11-09T21:00:00+01:00')
            ->and($takeOut['kind'])->toBe('evening')
            ->and($takeOut['hold_hours'])->toBe(5);
    });

    it('also applies when the hold would end exactly at bedtime (16:00 + 5 h = 21:00)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 15:55', '2026-11-09 07:10');
        peTakeOut($child, '2026-11-09 16:00');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-09 21:00', 'accident' => '2026-11-10 07:00', 'evening' => true]);
    });

    it('stays the start of the night after a take-out before the window (18:30)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:25', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 18:30');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-09 21:00', 'evening' => true]);
    });

    it('counts a take-out inside the window as the evening outing: next deadline the morning one (07:30)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 19:25', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 19:30');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-10 07:30', 'evening' => false, 'morning' => true])
            ->and(BehaviourPayload::for($pet->fresh())->takeOut['kind'])->toBe('morning');
    });

    it('counts a take-out exactly at the window start (19:00)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:55', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 19:00');

        expect(peDue($pet))->toBe('2026-11-10 07:30');
    });

    it('leaves a hold that ends before bedtime alone (14:00 + 5 h → 19:00, kind regular)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 13:55', '2026-11-09 07:10');
        peTakeOut($child, '2026-11-09 14:00');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-09 19:00', 'evening' => false])
            ->and(BehaviourPayload::for($pet->fresh())->takeOut['kind'])->toBe('regular');
    });

    it('reads the window length from config (60 min → a 19:30 take-out is before the window)', function () {
        config(['behaviour.puppy.evening_window_minutes' => 60]);
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 19:25', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 19:30');

        expect(peDue($pet))->toBe('2026-11-09 21:00');
    });

    it('can be switched off in config (0 → the old midnight restart + morning rule, 07:30)', function () {
        config(['behaviour.puppy.evening_window_minutes' => 0]);
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 16:25', '2026-11-09 07:10');
        peTakeOut($child, '2026-11-09 16:30');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-10 07:30', 'evening' => false]);
    });
});

describe('nobody takes the puppy out before bed: »Luža zjutraj«', function () {
    it('has no accident at bedtime and exactly one at the end of the night; then the normal hold', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 20:50', '2026-11-09 16:30');

        $pet = peRun($pet, '2026-11-09 20:50', '2026-11-09 21:30');
        expect(peAccidents($pet))->toBe([])
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);

        // In the night the deadline shown is the accident at the night's end.
        $takeOut = BehaviourPayload::for($pet)->takeOut;
        expect($takeOut['kind'])->toBe('morning')
            ->and($takeOut['next_due_at'])->toBe('2026-11-10T07:00:00+01:00');

        $pet = peRun($pet, '2026-11-09 21:30', '2026-11-10 07:40');
        expect(peAccidents($pet))->toBe(['2026-11-10 07:00'])
            ->and($pet->displayMetric('hygiene_level'))->toBe(0)
            // No second puddle that morning: the clock restarted at 07:00, normal 5 h hold.
            ->and(peClock($pet))->toMatchArray(['due' => '2026-11-10 12:00', 'evening' => false, 'morning' => false]);
    });

    it('sends no morning push on top of the morning puddle', function () {
        config(['push.enabled' => true]);
        Queue::fake();
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-10 06:40', '2026-11-09 16:30');

        peRun($pet, '2026-11-10 06:40', '2026-11-10 07:20', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->pluck('metric')->all())->toBe([]);
    });

    it('lets a late take-out in the night still empty the bladder (22:00 → morning rule 07:30, no 07:00 puddle)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 21:55', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 22:00');

        expect(peDue($pet))->toBe('2026-11-10 07:30');
        $pet = peClockAt($pet->fresh(), '2026-11-10 06:50', '2026-11-09 22:00');
        $pet = peRun($pet, '2026-11-10 06:50', '2026-11-10 07:10');
        expect(peAccidents($pet))->toBe([]);
    });
});

describe('who and which night', function () {
    it('never applies to adults or legacy pets', function () {
        [, , $adult] = peFamily(36);
        [, , $legacy] = peFamily();
        Pet::whereKey($legacy->id)->update(['arrival_age_months' => null]);
        peAt('2026-11-09 16:30');

        expect(peClock($adult))->toBeNull()->and(peClock($legacy))->toBeNull();
    });

    it('has no evening rule without night quiet hours (quiet hours off: 16:30 + 5 h = 21:30)', function () {
        [, , $pet] = peFamily(quiet: null);
        $pet = peClockAt($pet, '2026-11-09 16:30', '2026-11-09 16:30');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-09 21:30', 'evening' => false]);
    });

    it('has no evening rule when bedtime starts after midnight (00:30–07:00)', function () {
        [, , $pet] = peFamily(quiet: ['bedtime_start' => '00:30', 'bedtime_end' => '07:00']);
        $pet = peClockAt($pet, '2026-11-09 21:00', '2026-11-09 21:00');

        // 21:00 + 5 h runs over midnight → the clock restarts at 00:00, the night is quiet from 00:30.
        expect(peClock($pet)['evening'])->toBeFalse();
    });

    it('ignores the school window (07:30 + 5 h with school 08–13 → 17:30, regular)', function () {
        [, $child, $pet] = peFamily(quiet: ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $pet = peClockAt($pet, '2026-11-09 07:25', '2026-11-09 07:00');
        peTakeOut($child, '2026-11-09 07:30');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-09 17:30', 'evening' => false]);
    });

    it('uses the pet\'s own quiet hours and time zone (New York pet, bedtime 22–06 → by 22:00 New York time)', function () {
        [, , $pet] = peFamily();
        Pet::whereKey($pet->id)->update([
            'timezone' => 'America/New_York',
            'own_quiet_hours' => json_encode(['school_start' => null, 'school_end' => null, 'bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'is_active' => true]),
        ]);
        $ny = 'America/New_York';
        $pet = peClockAt($pet->fresh(), '2026-11-09 18:00', '2026-11-09 18:00', $ny);

        // 18:00 + 5 h would be 23:00 NY (night) → deadline 22:00 NY (03:00 UTC), puddle at 06:00 NY.
        expect(peClock($pet, $ny))->toBe(['due' => '2026-11-09 22:00', 'accident' => '2026-11-10 06:00', 'evening' => true, 'morning' => false])
            ->and(BehaviourPayload::for($pet->fresh())->takeOut['next_due_at'])->toBe('2026-11-09T22:00:00-05:00');

        // The family's night (21:00 Ljubljana = 15:00 NY) plays no part; a take-out at 20:30 NY is in the window.
        $pet = peClockAt($pet->fresh(), '2026-11-09 20:30', '2026-11-09 20:30', $ny);
        expect(peClock($pet, $ny))->toMatchArray(['due' => '2026-11-10 06:30', 'evening' => false]);
    });
});

describe('births, freezes, outages, DST (consistent with the morning rule)', function () {
    it('counts a freeze that ended inside the window as the evening outing (hard stop until 19:30 → 07:30)', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 19:00', '2026-11-09 16:30');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        $pet = peRun($pet->fresh(), '2026-11-09 19:00', '2026-11-09 19:30');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);
        $pet = peRun($pet->fresh(), '2026-11-09 19:30', '2026-11-09 19:40');

        expect(peClock($pet))->toMatchArray(['due' => '2026-11-10 07:30', 'evening' => false]);
    });

    it('gives a puppy born before the window the evening deadline (born 17:00 → by 21:00)', function () {
        [, , $pet] = peFamily();
        Pet::whereKey($pet->id)->update(['born_at' => Carbon::parse('2026-11-09 17:00', PE_TZ)->utc(), 'potty_clock_started_at' => null]);
        peAt('2026-11-09 17:00');

        expect(peDue($pet))->toBe('2026-11-09 21:00');
    });

    it('makes up nothing after an outage over bedtime and the morning: the clock restarts at the tick', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 20:00', '2026-11-09 16:30');
        $pet = peTick($pet, '2026-11-10 07:20');   // scheduler down 20:00 → 07:20

        expect(peAccidents($pet))->toBe([])
            ->and(peDue($pet))->toBe('2026-11-10 12:20');
    });

    it('follows the wall clock on the night clocks go back (24 → 25 Oct 2026): by 21:00 CEST, puddle at 07:00 CET', function () {
        [, , $pet] = peFamily(bornLocal: '2026-10-23 08:00');
        $pet = peClockAt($pet, '2026-10-24 16:30', '2026-10-24 16:30');

        $clock = app(BehaviourEventService::class)->pottyClock($pet->fresh(), $pet->quietHours());
        expect($clock['due_at']->toDateTimeString())->toBe('2026-10-24 19:00:00')        // 21:00 CEST
            ->and($clock['accident_at']->toDateTimeString())->toBe('2026-10-25 06:00:00'); // 07:00 CET
    });
});

describe('the evening window in the payload (take_out.window_starts_at, David 2026-11-27)', function () {
    it('sends the window start with the evening deadline (clock 16:30 → window 19:00, by 21:00)', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 16:30');
        $takeOut = BehaviourPayload::for($pet)->takeOut;

        expect($takeOut['kind'])->toBe('evening')
            ->and($takeOut['window_starts_at'])->toBe('2026-11-09T19:00:00+01:00')
            ->and($takeOut['next_due_at'])->toBe('2026-11-09T21:00:00+01:00');
    });

    it('David\'s device case: a take-out at 18:56 is before the window — still evening, window 19:00, by 21:00', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 16:30');
        peAt('2026-11-09 18:56');
        actingAsRole($child);
        $response = postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'accepted');

        expect($response->json('state.behaviour.take_out.kind'))->toBe('evening')
            ->and($response->json('state.behaviour.take_out.window_starts_at'))->toBe('2026-11-09T19:00:00+01:00')
            ->and($response->json('state.behaviour.take_out.next_due_at'))->toBe('2026-11-09T21:00:00+01:00');
    });

    it('keeps the window start once the window is open (19:30, clock from 16:30)', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 19:30', '2026-11-09 16:30');

        expect(BehaviourPayload::for($pet)->takeOut['window_starts_at'])->toBe('2026-11-09T19:00:00+01:00');
    });

    it('is null for every other kind: in-window take-out (morning), regular, night started (morning)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 19:25', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 19:30');
        $morning = BehaviourPayload::for($pet->fresh())->takeOut;
        expect($morning['kind'])->toBe('morning')->and($morning['window_starts_at'])->toBeNull();

        $pet = peClockAt($pet->fresh(), '2026-11-09 14:00', '2026-11-09 13:00');
        $regular = BehaviourPayload::for($pet)->takeOut;
        expect($regular['kind'])->toBe('regular')->and($regular['window_starts_at'])->toBeNull();

        $pet = peClockAt($pet->fresh(), '2026-11-09 22:00', '2026-11-09 16:30');
        $night = BehaviourPayload::for($pet)->takeOut;
        expect($night['kind'])->toBe('morning')->and($night['window_starts_at'])->toBeNull();
    });

    it('follows the configured window length (60 min → 20:00)', function () {
        config(['behaviour.puppy.evening_window_minutes' => 60]);
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 16:30');

        expect(BehaviourPayload::for($pet)->takeOut['window_starts_at'])->toBe('2026-11-09T20:00:00+01:00');
    });
});

describe('the evening push (»Kuža mora pred spanjem še ven …«)', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
        Queue::fake();
    });

    it('reminds the caretaker children once at the start of the window', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 16:30');

        $pet = peRun($pet, '2026-11-09 18:50', '2026-11-09 18:59', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);

        $pet = peRun($pet, '2026-11-09 18:59', '2026-11-09 20:30', 1);
        $push = PushNotification::where('pet_id', $pet->id)->where('type', PushType::TakeOutReminder->value)->sole();
        expect($push->created_at->toDateTimeString())->toBe('2026-11-09 18:00:00')   // 19:00 local
            ->and($push->metric)->toBe('evening:2026-11-09')
            ->and(collect($push->recipients)->pluck('audience')->unique()->all())->toBe(['child'])
            ->and(collect($push->recipients)->pluck('user_id')->all())->toBe([$child->id]);
    });

    it('does not remind when the evening deadline does not stand (out at 19:00 sharp, before the tick)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:55', '2026-11-09 16:30');
        peTakeOut($child, '2026-11-09 19:00');
        peRun($pet->fresh(), '2026-11-09 19:00', '2026-11-09 19:30');

        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);
    });

    it('drops the reminder at send time once the puppy was taken out', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:58', '2026-11-09 16:30');
        $pet = peRun($pet, '2026-11-09 18:58', '2026-11-09 19:01', 1);
        $push = PushNotification::where('type', PushType::TakeOutReminder->value)->sole();

        peTakeOut($child, '2026-11-09 19:02');
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));
        expect($push->fresh())->status->toBe(PushNotification::STATUS_SUPPRESSED)
            ->suppressed_reason->toBe('taken_out');
    });

    it('never reminds in quiet hours: school until 19:30 → the reminder comes at 19:30', function () {
        [, , $pet] = peFamily(quiet: ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '17:00', 'school_end' => '19:30']);
        // Clock 14:00: 3 h before school (17:00–19:30) + 2 h after → 21:30, past bedtime → by 21:00.
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 14:00');
        expect(peDue($pet))->toBe('2026-11-09 21:00');

        $pet = peRun($pet, '2026-11-09 18:50', '2026-11-09 19:29', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);

        peRun($pet, '2026-11-09 19:29', '2026-11-09 19:35', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->sole()->created_at->toDateTimeString())
            ->toBe('2026-11-09 18:30:00');   // 19:30 local
    });

    it('sends nothing while the pet is locked (hard stop over the window start)', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:50', '2026-11-09 16:30');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        peRun($pet->fresh(), '2026-11-09 18:50', '2026-11-09 19:20');

        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);
    });

    it('reminds again the next evening (once per day)', function () {
        [, , $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:58', '2026-11-09 16:30');
        peRun($pet, '2026-11-09 18:58', '2026-11-09 19:10', 1);
        $pet = peClockAt($pet->fresh(), '2026-11-10 18:58', '2026-11-10 16:30');
        peRun($pet, '2026-11-10 18:58', '2026-11-10 19:10', 1);

        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->orderBy('id')->pluck('metric')->all())
            ->toBe(['evening:2026-11-09', 'evening:2026-11-10']);
    });

    it('has a calm text without names, in both languages; the morning text is unchanged', function () {
        expect(PushCopy::body(PushType::TakeOutReminder, 'evening:2026-11-09', 'child', 'sl'))
            ->toBe('Kuža mora pred spanjem še ven. Pritisni »Pelji ven«.')
            ->and(PushCopy::body(PushType::TakeOutReminder, 'evening:2026-11-09', 'child', 'en'))
            ->toBe('Your pup needs to go out once more before bed. Press “Take out”.')
            ->and(PushCopy::body(PushType::TakeOutReminder, 'morning:2026-11-09', 'child', 'sl'))
            ->toBe('Dobro jutro! Kuža mora po noči čim prej ven. Pritisni »Pelji ven«.')
            ->and(PushCopy::body(PushType::TakeOutReminder, 'evening:2026-11-09', 'child', 'sl', null, ['time' => '21:00']))
            ->toBe('Kuža mora pred spanjem še ven — najkasneje ob 21:00. Pritisni »Pelji ven«.')
            ->and(PushCopy::body(PushType::TakeOutReminder, 'evening:2026-11-09', 'child', 'en', null, ['time' => '9:00 PM']))
            ->toBe('Your pup needs to go out once more before bed — by 9:00 PM at the latest. Press “Take out”.');
    });

    it('names the bedtime when it is delivered, in each phone\'s 12 / 24 h clock (David 2026-11-27)', function () {
        [, $child, $pet] = peFamily();
        $pet = peClockAt($pet, '2026-11-09 18:58', '2026-11-09 16:30');
        peRun($pet, '2026-11-09 18:58', '2026-11-09 19:01', 1);
        $push = PushNotification::where('type', PushType::TakeOutReminder->value)->sole();
        foreach ([['sl', false], ['en', true]] as [$locale, $hour12]) {
            DevicePushToken::create([
                'user_id' => $child->id,
                'expo_push_token' => 'ExponentPushToken['.Str::random(22).']',
                'platform' => 'ios',
                'app_version' => '1.0.0',
                'locale' => $locale,
                'hour12' => $hour12,
                'last_seen_at' => now(),
            ]);
        }
        $sent = [];
        Http::fake([
            'https://exp.host/--/api/v2/push/send' => function ($request) use (&$sent) {
                foreach ($request->data() as $message) {
                    $sent[] = $message['body'];
                }

                return Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => 'ticket-'.Str::random(8)], $request->data())]);
            },
        ]);

        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));

        expect(collect($sent)->sort()->values()->all())->toBe([
            'Kuža mora pred spanjem še ven — najkasneje ob 21:00. Pritisni »Pelji ven«.',
            'Your pup needs to go out once more before bed — by 9:00 PM at the latest. Press “Take out”.',
        ]);
    });
});
