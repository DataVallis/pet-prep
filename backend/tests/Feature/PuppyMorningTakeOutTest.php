<?php

use App\Enums\HygieneEventKind;
use App\Enums\PushType;
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
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R02 morning take-out (David 2026-11-08 »ja, daj 30 min«)
|--------------------------------------------------------------------------
| Suki, a 5-month puppy, slept through the night (quiet hours until 07:00)
| and the HUD said »najkasneje ob 12:00« (07:00 + 5 h hold). New rule: when
| the overnight quiet stretch ends, the puppy must go out within
| `behaviour.puppy.morning_take_out_minutes` (30) of non-quiet time; after a
| take-out the normal hold applies. A clock start in the last
| `night_take_out_counts_minutes` (60) of the night counts as the morning
| outing (Claude's default). Daytime quiet stretches only pause the clock.
|
| Times are family-local unless they end in "UTC". Europe/Ljubljana is UTC+1
| in November.
*/

beforeEach(function () {
    seedLifeStageData();
});

afterEach(function () {
    Carbon::setTestNow();
});

const PM_TZ = 'Europe/Ljubljana';

function pmAt(string $local, string $tz = PM_TZ): Carbon
{
    $at = Carbon::parse($local, $tz)->utc();
    Carbon::setTestNow($at);

    return $at;
}

/**
 * A profiled puppy with behaviour events, born at $bornLocal, poops off.
 * $quiet: quiet-hour fields (null = quiet hours off). Default = the family
 * default night 21:00–07:00, no school.
 *
 * @param  array<string, mixed>|null  $quiet
 * @return array{0: User, 1: User, 2: Pet}
 */
function pmFamily(string $bornLocal = '2026-11-08 08:00', int $months = 5, ?array $quiet = ['bedtime_start' => '21:00', 'bedtime_end' => '07:00'], string $tz = PM_TZ): array
{
    $born = pmAt($bornLocal, $tz);
    $parent = User::factory()->parent()->create(['timezone' => $tz]);
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

/**
 * The puppy as it stands at $local after a quiet night: its clock last
 * restarted at $clockLocal (an evening take-out by default) and the scheduler
 * ran up to $local (no outage).
 */
function pmAfterNight(Pet $pet, string $local, string $clockLocal = '2026-11-08 20:00', string $tz = PM_TZ): Pet
{
    $at = pmAt($local, $tz);
    Pet::whereKey($pet->id)->update([
        'potty_clock_started_at' => Carbon::parse($clockLocal, $tz)->utc(),
        'last_decay_at' => $at,
        'hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100,
    ]);

    return $pet->fresh();
}

/** One decay tick (+ escalation) at $local. */
function pmTick(Pet $pet, string $local, string $tz = PM_TZ): Pet
{
    pmAt($local, $tz);
    app(PetDecayService::class)->processPetDecay($pet);
    Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100, 'hunger_zero_since' => null, 'thirst_zero_since' => null]);
    app(EscalationService::class)->processPetEscalation($pet->fresh());

    return $pet->fresh();
}

/** Ticks every $step minutes in ($fromLocal, $toLocal]. */
function pmRun(Pet $pet, string $fromLocal, string $toLocal, int $step = 5, string $tz = PM_TZ): Pet
{
    $end = Carbon::parse($toLocal, $tz);
    for ($t = Carbon::parse($fromLocal, $tz)->addMinutes($step); $t->lessThan($end); $t->addMinutes($step)) {
        $pet = pmTick($pet, $t->format('Y-m-d H:i:s'), $tz);
    }

    return pmTick($pet, $end->format('Y-m-d H:i:s'), $tz);
}

/** The clock's due instant, family-local "Y-m-d H:i". */
function pmDue(Pet $pet, string $tz = PM_TZ): ?string
{
    $clock = app(BehaviourEventService::class)->pottyClock($pet->fresh(), $pet->quietHours());

    return $clock === null ? null : $clock['due_at']->setTimezone($tz)->format('Y-m-d H:i');
}

/** @return list<string> accident instants, family-local "Y-m-d H:i" */
function pmAccidents(Pet $pet, string $tz = PM_TZ): array
{
    return PetHygieneEvent::where('pet_id', $pet->id)->where('kind', HygieneEventKind::Accident->value)
        ->orderBy('scheduled_at')->get()
        ->map(fn ($e) => $e->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d H:i'))->all();
}

function pmTakeOut(User $child, string $local): void
{
    pmAt($local);
    actingAsRole($child);
    postJson('/api/child/pet/take-out')->assertOk()->assertJsonPath('status', 'accepted');
}

describe('Suki: a 5-month puppy after a night with quiet hours until 07:00', function () {
    it('must go out by 07:30 (not 12:00) — the HUD reads it from behaviour.take_out.next_due_at', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:50');

        expect(app(BehaviourEventService::class)->holdHoursOn($pet, '2026-11-09'))->toBe(5);
        $takeOut = BehaviourPayload::for($pet)->takeOut;
        expect($takeOut['hold_hours'])->toBe(5)
            ->and($takeOut['next_due_at'])->toBe('2026-11-09T07:30:00+01:00')
            // The clock restarted at the family-local midnight, as before.
            ->and($takeOut['clock_started_at'])->toBe('2026-11-09T00:00:00+01:00');

        // Already during the night the deadline is the morning one (»jutri ob 7:30«).
        pmAt('2026-11-08 23:00');
        expect(pmDue($pet))->toBe('2026-11-09 07:30');
    });

    it('has the normal accident at 07:30 when nobody takes it out; then the normal 5 h hold', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:50');

        $pet = pmRun($pet, '2026-11-09 06:50', '2026-11-09 07:29');
        expect(pmAccidents($pet))->toBe([])
            ->and($pet->displayMetric('hygiene_level'))->toBe(100);

        $pet = pmRun($pet, '2026-11-09 07:29', '2026-11-09 07:31', 1);
        expect(pmAccidents($pet))->toBe(['2026-11-09 07:30'])
            ->and($pet->displayMetric('hygiene_level'))->toBe(0)
            ->and($pet->hygiene_zero_since->toDateTimeString())->toBe('2026-11-09 06:30:00')
            ->and(pmDue($pet))->toBe('2026-11-09 12:30');
    });

    it('take-out at 07:10 → next due 12:10, no accident at 07:30', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:50');
        $pet = pmRun($pet, '2026-11-09 06:50', '2026-11-09 07:10');

        pmAt('2026-11-09 07:10');
        actingAsRole($child);
        postJson('/api/child/pet/take-out')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.behaviour.take_out.next_due_at', '2026-11-09T12:10:00+01:00');

        $pet = pmRun($pet->fresh(), '2026-11-09 07:10', '2026-11-09 08:00');
        expect(pmAccidents($pet))->toBe([])
            ->and(pmDue($pet))->toBe('2026-11-09 12:10');
    });
});

describe('night take-outs (Claude default: the last 60 min of the night count)', function () {
    it('counts a take-out in the last hour of the night as the morning outing (normal hold from 07:00)', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:10');
        pmTakeOut($child, '2026-11-09 06:15');

        // Non-quiet time starts at 07:00 → 07:00 + 5 h.
        expect(pmDue($pet))->toBe('2026-11-09 12:00');
    });

    it('counts a take-out exactly 60 min before the end of the night', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 05:58');
        pmTakeOut($child, '2026-11-09 06:00');

        expect(pmDue($pet))->toBe('2026-11-09 12:00');
    });

    it('does not count an earlier night take-out: still out by 07:30', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 05:25');
        pmTakeOut($child, '2026-11-09 05:30');

        expect(pmDue($pet))->toBe('2026-11-09 07:30');
        $pet = pmRun($pet->fresh(), '2026-11-09 05:30', '2026-11-09 07:31');
        expect(pmAccidents($pet))->toBe(['2026-11-09 07:30']);
    });

    it('treats an evening take-out before midnight like any other: the clock restarts at midnight, out by 07:30', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-08 23:25', '2026-11-08 20:00');
        pmTakeOut($child, '2026-11-08 23:30');

        expect(pmDue($pet))->toBe('2026-11-09 07:30');
    });
});

describe('daytime quiet stretches are unchanged', function () {
    it('only pauses the clock during school: a take-out at 07:30 with school 08–13 is due at 14:30, not 13:30', function () {
        [, $child, $pet] = pmFamily('2026-11-08 08:00', 2, ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 07:25', '2026-11-09 06:20');
        pmTakeOut($child, '2026-11-09 07:30');

        // 2 h hold: 07:30–08:00 (30 min) + 13:00–14:30 after school.
        expect(pmDue($pet))->toBe('2026-11-09 14:30');
    });

    it('counts a morning take-out before school as before: night 22–06, take-out 06:20, school 08–13 → 13:20', function () {
        [, $child, $pet] = pmFamily('2026-11-08 08:00', 2, ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 06:15');
        pmTakeOut($child, '2026-11-09 06:20');

        // 2 h hold: 06:20–08:00 (100 min) + 20 min after school.
        expect(pmDue($pet))->toBe('2026-11-09 13:20');
    });

    it('applies the morning deadline after the night, before school (night 22–06 → out by 06:30)', function () {
        [, , $pet] = pmFamily('2026-11-08 08:00', 2, ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 05:00');

        expect(pmDue($pet))->toBe('2026-11-09 06:30');
    });

    it('counts the 30 minutes outside quiet hours: school right after the night (07–13) → out by 13:30', function () {
        [, , $pet] = pmFamily('2026-11-08 08:00', 5, ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '07:00', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 05:00');

        // The overnight stretch only ends at 13:00 (school joins the night).
        expect(pmDue($pet))->toBe('2026-11-09 13:30');
    });

    it('counts the 30 minutes outside quiet hours: school at 07:15 → 15 min before + 15 min after school', function () {
        [, , $pet] = pmFamily('2026-11-08 08:00', 5, ['bedtime_start' => '21:00', 'bedtime_end' => '07:00', 'school_start' => '07:15', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 05:00');

        expect(pmDue($pet))->toBe('2026-11-09 13:15');
    });

    it('has no morning rule without a night (quiet hours off): the clock restarts at midnight, due 05:00', function () {
        [, , $pet] = pmFamily(quiet: null);
        $pet = pmAfterNight($pet, '2026-11-09 03:00');

        expect(pmDue($pet))->toBe('2026-11-09 05:00');
    });

    it('can be switched off in config (0 minutes → 07:00 + hold)', function () {
        config(['behaviour.puppy.morning_take_out_minutes' => 0]);
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:50');

        expect(pmDue($pet))->toBe('2026-11-09 12:00');
    });

    it('reads the minutes from config (45 → 07:45)', function () {
        config(['behaviour.puppy.morning_take_out_minutes' => 45]);
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:50');

        expect(pmDue($pet))->toBe('2026-11-09 07:45');
    });

    it('never applies to adults or legacy pets', function () {
        [, , $adult] = pmFamily(months: 36);
        [, , $legacy] = pmFamily();
        Pet::whereKey($legacy->id)->update(['arrival_age_months' => null]);
        pmAt('2026-11-09 06:50');

        expect(pmDue($adult))->toBeNull()->and(pmDue($legacy))->toBeNull();
    });
});

describe('births, freezes and scheduler outages', function () {
    it('gives a puppy born mid-morning the normal hold (born 09:00 → 14:00)', function () {
        [, , $pet] = pmFamily('2026-11-09 09:00');
        Pet::whereKey($pet->id)->update(['potty_clock_started_at' => null]);

        expect(pmDue($pet))->toBe('2026-11-09 14:00');
    });

    it('gives a puppy born during the night the morning deadline (born 03:00 → 07:30)', function () {
        [, , $pet] = pmFamily('2026-11-09 03:00');
        Pet::whereKey($pet->id)->update(['potty_clock_started_at' => null]);

        expect(pmDue($pet))->toBe('2026-11-09 07:30');
    });

    it('resumes a freeze that ended mid-morning with the normal hold (hard stop until 09:00 → 14:00)', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        $pet = pmRun($pet->fresh(), '2026-11-09 06:00', '2026-11-09 09:00');
        expect(pmAccidents($pet))->toBe([]);

        Pet::find($pet->id)->update(['is_hard_stopped' => false]);
        $pet = pmRun($pet->fresh(), '2026-11-09 09:00', '2026-11-09 09:10');
        expect(pmAccidents($pet))->toBe([])
            ->and(pmDue($pet))->toBe('2026-11-09 14:00');
    });

    it('keeps the morning deadline after a freeze that ended in the night (hard stop until 05:00 → 07:30)', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 04:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        $pet = pmRun($pet->fresh(), '2026-11-09 04:00', '2026-11-09 05:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);
        $pet = pmRun($pet->fresh(), '2026-11-09 05:00', '2026-11-09 05:10');

        expect(pmDue($pet))->toBe('2026-11-09 07:30');
    });

    it('makes up nothing after an outage over the morning: no accident, the clock restarts at the tick', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 05:00');
        // The scheduler is down 05:00 → 07:40 (the 07:30 deadline passes).
        $pet = pmTick($pet, '2026-11-09 07:40');

        expect(pmAccidents($pet))->toBe([])
            ->and(pmDue($pet))->toBe('2026-11-09 12:40');
    });

    it('keeps the morning deadline after an outage that ended in the night', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 01:00');
        $pet = pmTick($pet, '2026-11-09 05:00');   // outage 01:00 → 05:00

        expect(pmDue($pet))->toBe('2026-11-09 07:30');
    });
});

describe('timezone and DST', function () {
    it('uses the family timezone: New York, night until 07:00 EST → 07:30 EST (12:30 UTC)', function () {
        [, , $pet] = pmFamily('2026-11-08 08:00', 5, tz: 'America/New_York');
        $pet = pmAfterNight($pet, '2026-11-09 06:50', '2026-11-08 20:00', 'America/New_York');

        $clock = app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours());
        expect($clock['due_at']->toDateTimeString())->toBe('2026-11-09 12:30:00')
            ->and(BehaviourPayload::for($pet)->takeOut['next_due_at'])->toBe('2026-11-09T07:30:00-05:00');
    });

    it('follows the wall clock on the night clocks go back (25 Oct 2026): 07:30 CET = 06:30 UTC', function () {
        [, , $pet] = pmFamily('2026-10-24 08:00');
        $pet = pmAfterNight($pet, '2026-10-25 06:50', '2026-10-24 20:00');

        expect(app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2026-10-25 06:30:00');
        $pet = pmRun($pet, '2026-10-25 06:50', '2026-10-25 07:31');
        expect(pmAccidents($pet))->toBe(['2026-10-25 07:30']);
    });

    it('follows the wall clock on the night clocks go forward (28 Mar 2027): 07:30 CEST = 05:30 UTC', function () {
        [, , $pet] = pmFamily('2027-03-27 08:00');
        $pet = pmAfterNight($pet, '2027-03-28 06:50', '2027-03-27 20:00');

        expect(app(BehaviourEventService::class)->pottyClock($pet, $pet->quietHours())['due_at']->toDateTimeString())
            ->toBe('2027-03-28 05:30:00');
    });
});

describe('the morning push (»Dobro jutro! Kuža mora … ven«)', function () {
    beforeEach(function () {
        config(['push.enabled' => true]);
        Queue::fake();
    });

    it('reminds the caretaker children once at the end of the night, never during it', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:45');
        $pet = pmRun($pet, '2026-11-09 06:45', '2026-11-09 06:59', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);

        $pet = pmRun($pet, '2026-11-09 06:59', '2026-11-09 07:10', 1);
        $push = PushNotification::where('pet_id', $pet->id)->where('type', PushType::TakeOutReminder->value)->sole();
        expect($push->created_at->toDateTimeString())->toBe('2026-11-09 06:00:00')   // 07:00 local, first tick after the night
            ->and($push->metric)->toBe('morning:2026-11-09')
            ->and($push->status)->toBe(PushNotification::STATUS_QUEUED)
            ->and(collect($push->recipients)->pluck('audience')->unique()->all())->toBe(['child'])
            ->and(collect($push->recipients)->pluck('user_id')->all())->toBe([$child->id])
            ->and(PushType::TakeOutReminder->isUrgent())->toBeFalse()
            ->and(PushType::TakeOutReminder->ttlSeconds())->toBe(1800);
    });

    it('does not remind when the puppy went out in the last hour of the night (no morning deadline)', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:10');
        pmTakeOut($child, '2026-11-09 06:15');
        pmRun($pet->fresh(), '2026-11-09 06:15', '2026-11-09 07:20');

        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);
    });

    it('drops the reminder at send time once the puppy was taken out', function () {
        [, $child, $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 06:55');
        $pet = pmRun($pet, '2026-11-09 06:55', '2026-11-09 07:01', 1);
        $push = PushNotification::where('type', PushType::TakeOutReminder->value)->sole();

        pmTakeOut($child, '2026-11-09 07:02');
        app(NotificationService::class)->deliver($push->id, app(ExpoPushClient::class));
        expect($push->fresh())->status->toBe(PushNotification::STATUS_SUPPRESSED)
            ->suppressed_reason->toBe('taken_out');
    });

    it('fires at the end of the night stretch (06:00) with night 22–06, and again the next morning', function () {
        [, , $pet] = pmFamily('2026-11-08 08:00', 2, ['bedtime_start' => '22:00', 'bedtime_end' => '06:00', 'school_start' => '08:00', 'school_end' => '13:00']);
        $pet = pmAfterNight($pet, '2026-11-09 05:55');
        $pet = pmRun($pet, '2026-11-09 05:55', '2026-11-09 06:05', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->pluck('metric')->all())->toBe(['morning:2026-11-09']);

        $pet = pmAfterNight($pet, '2026-11-10 05:55', '2026-11-09 21:00');
        pmRun($pet, '2026-11-10 05:55', '2026-11-10 06:05', 1);
        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->orderBy('id')->pluck('metric')->all())
            ->toBe(['morning:2026-11-09', 'morning:2026-11-10']);
    });

    it('sends nothing after an outage over the morning (the clock restarted, no morning deadline)', function () {
        [, , $pet] = pmFamily();
        $pet = pmAfterNight($pet, '2026-11-09 05:00');
        pmTick($pet, '2026-11-09 07:40');    // outage: the clock restarts, no morning deadline

        expect(PushNotification::where('type', PushType::TakeOutReminder->value)->count())->toBe(0);
    });

    it('has a calm text without names or minute counts, in both languages', function () {
        expect(PushCopy::body(PushType::TakeOutReminder, 'morning:2026-11-09', 'child', 'sl'))
            ->toBe('Dobro jutro! Kuža mora po noči čim prej ven. Pritisni »Pelji ven«.')
            ->and(PushCopy::body(PushType::TakeOutReminder, 'morning:2026-11-09', 'child', 'en'))
            ->toBe('Good morning! After the night your pup needs to go out soon. Press “Take out”.');
    });
});
