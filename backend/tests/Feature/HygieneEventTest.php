<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventStatus;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetHygieneEvent;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\HygieneEventService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use App\Services\Results\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Random hygiene events (M1-05, PRODUCT_SPEC §5 "Higiena")
|--------------------------------------------------------------------------
|
| Mutt 1× / border collie 2× per family-local day (breed_configs
| .poops_per_day), at random minutes outside quiet hours. When an event's
| time passes, hygiene drops to 0 %; cleaning restores 100 %. No gradual
| decay. Family: Europe/Ljubljana (CEST = UTC+2 until 2026-10-25).
*/

const HY_SCHOOL_AND_BED = [
    'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
    'school_start' => '08:00', 'school_end' => '13:00',
];

/**
 * A Ljubljana family; the pet is created at the current test time.
 */
function hyPet(string $breed = 'mutt', array $quietHours = [], array $pet = []): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quietHours !== []) {
        setQuietHours(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));
    } else {
        withoutQuietHours($parent); // [] = no quiet time at all (default night 21–07 since 2026-10-08)
    }

    return Pet::factory()->create(array_merge(['user_id' => $child->id, 'breed_type' => $breed], $pet));
}

function hyTick(Pet $pet, Carbon|string $utc): Pet
{
    Carbon::setTestNow($utc instanceof Carbon ? $utc : Carbon::parse($utc, 'UTC'));
    app(PetDecayService::class)->processPetDecay($fresh = Pet::findOrFail($pet->id));

    return $fresh->refresh();
}

/**
 * The instants (UTC) the service will schedule for $pet on $localDate.
 *
 * @return list<Carbon>
 */
function hyPlanned(Pet $pet, string $localDate, int $count): array
{
    return app(HygieneEventService::class)->scheduleDay($pet, $localDate, $pet->quietHours(), $count);
}

/**
 * Plant pending events at fixed UTC instants (instead of the random draw)
 * and mark their local days as scheduled, so tests don't depend on where
 * the RNG lands (the seed includes the pet ID).
 *
 * @param  list<string>  $utcTimes
 * @return list<Carbon>
 */
function hyPlant(Pet $pet, array $utcTimes): array
{
    $instants = [];
    $lastDate = null;
    foreach ($utcTimes as $utc) {
        $at = Carbon::parse($utc, 'UTC');
        $lastDate = max($lastDate ?? '', $pet->localDate($at));
        PetHygieneEvent::create([
            'pet_id' => $pet->id,
            'local_date' => $pet->localDate($at),
            'scheduled_at' => $at,
            'status' => HygieneEventStatus::Pending,
        ]);
        $instants[] = $at;
    }
    Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => $lastDate]);
    $pet->refresh();

    return $instants;
}

function hyEvents(Pet $pet, ?HygieneEventStatus $status = null)
{
    return PetHygieneEvent::where('pet_id', $pet->id)
        ->when($status, fn ($q) => $q->where('status', $status->value))
        ->orderBy('scheduled_at')
        ->get();
}

beforeEach(function () {
    seedBreedConfigs();
    useHygieneSalt('test-salt');
    Carbon::setTestNow('2026-10-04 22:00:00'); // 00:00 CEST on 5 Oct
});

describe('Scheduling', function () {
    it('schedules poops_per_day events per local day: mutt 1, border collie 2', function () {
        $mutt = hyPet('mutt');
        $collie = hyPet('border_collie');

        // One tick per local day for three days.
        foreach (['2026-10-05 10:00', '2026-10-06 10:00', '2026-10-07 10:00'] as $utc) {
            hyTick($mutt, $utc);
            hyTick($collie, $utc);
        }

        expect(hyEvents($mutt)->groupBy(fn ($e) => $e->local_date->toDateString())->map->count()->all())
            ->toBe(['2026-10-05' => 1, '2026-10-06' => 1, '2026-10-07' => 1]);
        expect(hyEvents($collie)->groupBy(fn ($e) => $e->local_date->toDateString())->map->count()->all())
            ->toBe(['2026-10-05' => 2, '2026-10-06' => 2, '2026-10-07' => 2]);
    });

    it('never schedules inside quiet hours (school, bedtime, DST nights)', function () {
        $pet = hyPet('border_collie', HY_SCHOOL_AND_BED);
        $quiet = $pet->quietHours();
        $service = app(HygieneEventService::class);

        $day = Carbon::parse('2026-10-01');
        for ($i = 0; $i < 200; $i++, $day->addDay()) { // includes 2026-10-25 and 2027-03-28
            foreach ($service->scheduleDay($pet, $day->toDateString(), $quiet, 4) as $at) {
                expect($quiet->isQuietNow($at))->toBeFalse();
                $local = $at->copy()->setTimezone('Europe/Ljubljana');
                expect($local->toDateString())->toBe($day->toDateString());
                expect($local->format('H:i') >= '06:00' && $local->format('H:i') < '22:00')->toBeTrue();
                expect($local->format('H:i') >= '08:00' && $local->format('H:i') < '13:00')->toBeFalse();
            }
        }
    });

    it('is deterministic for a fixed salt and differs for another salt', function () {
        $pet = hyPet('border_collie', HY_SCHOOL_AND_BED);
        $quiet = $pet->quietHours();
        $plan = fn (string $salt) => collect(range(0, 29))->flatMap(
            fn (int $d) => (new HygieneEventService($salt))->scheduleDay($pet, Carbon::parse('2026-11-01')->addDays($d)->toDateString(), $quiet, 2)
        )->map->toIso8601String()->all();

        expect($plan('test-salt'))->toBe($plan('test-salt'));
        expect($plan('other-salt'))->not->toBe($plan('test-salt'));
    });

    it('spreads two events: one in each half of the open time', function () {
        $pet = hyPet('border_collie');
        // No quiet hours: open 00:00–24:00 local → halves split at 12:00 local.
        foreach (range(0, 59) as $d) {
            $date = Carbon::parse('2026-11-01')->addDays($d)->toDateString();
            [$first, $second] = hyPlanned($pet, $date, 2);
            expect($first->copy()->setTimezone('Europe/Ljubljana')->format('H:i') < '12:00')->toBeTrue();
            expect($second->copy()->setTimezone('Europe/Ljubljana')->format('H:i') >= '12:00')->toBeTrue();
        }
    });

    it('schedules nothing on a day that is entirely quiet or when poops_per_day is 0', function () {
        $pet = hyPet('mutt', ['school_start' => '00:00', 'school_end' => '23:59', 'bedtime_start' => '23:59', 'bedtime_end' => '00:00']);

        expect(hyPlanned($pet, '2026-10-05', 2))->toBe([]);
        expect(hyPlanned(hyPet(), '2026-10-05', 0))->toBe([]);
    });

    it('does not schedule a day twice', function () {
        $pet = hyPet('border_collie');

        hyTick($pet, '2026-10-05 01:00');
        hyTick($pet, '2026-10-05 02:00');
        hyTick($pet, '2026-10-05 03:00');

        expect(hyEvents($pet))->toHaveCount(2);
        expect($pet->fresh()->hygiene_scheduled_through)->toBe('2026-10-05');
    });
});

describe('Application in the game loop', function () {
    it('keeps hygiene at 100 % until the event, then drops it to 0 % at the event minute', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local

        $cursor = Carbon::parse('2026-10-04 22:00:00');
        $end = Carbon::parse('2026-10-05 10:00:00');
        $zeroAt = null;
        while ($cursor->lessThan($end)) {
            $cursor->addMinute();
            $fresh = hyTick($pet, $cursor->copy());
            if ($zeroAt === null && $fresh->hygiene_level == 0.0) {
                $zeroAt = $cursor->copy();
            }
            if ($zeroAt === null) {
                expect($fresh->hygiene_level)->toBe(100.0); // no gradual decay
            }
        }

        expect($zeroAt?->toIso8601String())->toBe($at->toIso8601String());
        $pet->refresh();
        expect($pet->hygiene_level)->toBe(0.0);
        expect($pet->hygiene_zero_since->toIso8601String())->toBe($at->toIso8601String());
        expect(hyEvents($pet, HygieneEventStatus::Applied))->toHaveCount(1);
    });

    it('applies missed events exactly once after a scheduler gap, with the neglect clock at the event time', function () {
        $pet = hyPet('border_collie');
        [$first, $second] = hyPlant($pet, ['2026-10-05 05:41:00', '2026-10-05 15:03:00']);
        Event::fake([PetUpdated::class]);

        // Scheduler down all day: one catch-up tick just before local midnight.
        $after = hyTick($pet, '2026-10-05 21:59:00');

        expect($after->hygiene_level)->toBe(0.0);
        expect($after->hygiene_zero_since->toIso8601String())->toBe($first->toIso8601String());
        expect(hyEvents($pet, HygieneEventStatus::Applied)->pluck('scheduled_at')->map->toIso8601String()->all())
            ->toBe([$first->toIso8601String(), $second->toIso8601String()]);

        // Cleaned, then more ticks: the old events don't fire again.
        app(PetActivityService::class)->clean($after);
        hyTick($pet, '2026-10-05 21:59:30');
        expect($pet->fresh()->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Applied))->toHaveCount(2);
    });

    it('gives the same hygiene timeline with 5-minute ticks as with 1-minute ticks (rounded to the tick)', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local

        $cursor = Carbon::parse('2026-10-04 22:00:00');
        $zeroAt = null;
        while ($zeroAt === null && $cursor->lessThan(Carbon::parse('2026-10-05 22:00:00'))) {
            $cursor->addMinutes(5);
            if (hyTick($pet, $cursor->copy())->hygiene_level == 0.0) {
                $zeroAt = $cursor->copy();
            }
        }

        expect($zeroAt->greaterThanOrEqualTo($at))->toBeTrue();
        expect($at->diffInMinutes($zeroAt))->toBeLessThan(5);
        expect($pet->fresh()->hygiene_zero_since->toIso8601String())->toBe($at->toIso8601String());
    });

    it('skips events that fall before the pet was created', function () {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $pet = hyPet('mutt');
        hyPlant($pet, ['2026-10-05 09:00:00']); // an hour before birth

        $after = hyTick($pet, '2026-10-05 10:30:00');

        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Skipped))->toHaveCount(1);
    });

    it('skips scheduled times before birth on the birth day (random draw)', function () {
        Carbon::setTestNow('2026-10-05 21:58:00'); // 23:58 local: the day's draws are (almost surely) past
        $pet = hyPet('border_collie');

        $after = hyTick($pet, '2026-10-05 21:59:00');
        $draws = hyEvents($pet);

        expect($draws)->toHaveCount(2);
        foreach ($draws as $event) {
            $expected = $event->scheduled_at->greaterThan(Carbon::parse('2026-10-05 21:58:00')) ? 'applied' : 'skipped';
            expect($event->status->value)->toBe($expected);
        }
    });

    it('skips an event that lands in quiet hours set after it was scheduled', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, '2026-10-04 22:01:00'); // schedules the day

        $local = $at->copy()->setTimezone('Europe/Ljubljana');
        setQuietHours([
            'parent_id' => $pet->user->parent_id, 'is_active' => true,
            'school_start' => $local->copy()->subMinutes(30)->format('H:i'),
            'school_end' => $local->copy()->addMinutes(30)->format('H:i'),
        ]);

        $after = hyTick($pet, '2026-10-05 21:59:00');

        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Skipped))->toHaveCount(1);
    });
});

describe('Frozen pets get no events during the freeze', function () {
    it('skips an event during a hard stop, with ticks running', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, '2026-10-04 22:01:00');

        Carbon::setTestNow($at->copy()->subHour());
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);
        hyTick($pet, $at->copy()->addMinutes(1));
        hyTick($pet, $at->copy()->addMinutes(30));
        Carbon::setTestNow($at->copy()->addHour());
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => false]);

        $after = hyTick($pet, $at->copy()->addMinutes(61));

        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Skipped))->toHaveCount(1);
    });

    it('skips an event during a hard stop even when no tick ran during the freeze', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, '2026-10-04 22:01:00');

        Carbon::setTestNow($at->copy()->subMinutes(10));
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);
        Carbon::setTestNow($at->copy()->addMinutes(10));
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => false]);

        $after = hyTick($pet, $at->copy()->addMinutes(11));

        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Skipped))->toHaveCount(1);
    });

    it('skips an event during illness', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, '2026-10-04 22:01:00');
        Pet::whereKey($pet->id)->update(['illness_until' => $at->copy()->addHours(2), 'frozen_at' => $at->copy()->subHours(10)]);

        hyTick($pet, $at->copy()->addMinute());                // frozen tick
        $after = hyTick($pet, $at->copy()->addHours(2)->addMinute()); // first tick after illness

        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Skipped))->toHaveCount(1);
    });
});

describe('Cleaning', function () {
    it('restores 100 %, clears the neglect clock, logs cleaned_poop and broadcasts once', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, $at->copy()->addMinutes(30));
        expect($pet->fresh()->hygiene_level)->toBe(0.0);
        Event::fake([PetUpdated::class]);

        $result = app(PetActivityService::class)->clean($pet);

        expect($result->status)->toBe(ActionResult::ACCEPTED);
        expect($result->hygieneLevel)->toBe(100);
        $pet->refresh();
        expect($pet->hygiene_level)->toBe(100.0);
        expect($pet->hygiene_zero_since)->toBeNull();
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::CleanedPoop->value)->count())->toBe(1);
        expect(hyEvents($pet, HygieneEventStatus::Applied)->first()->cleaned_at)->not->toBeNull();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'cleaned_poop');
    });

    it('does nothing when the pet is already clean', function () {
        $pet = hyPet('mutt');
        Event::fake([PetUpdated::class]);

        $result = app(PetActivityService::class)->clean($pet);

        expect($result->status)->toBe(ActionResult::UNCHANGED);
        expect(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('cleans up a legacy partly-dirty pet (interim decay) to 100 %', function () {
        $pet = hyPet('mutt', pet: ['hygiene_level' => 64.0]);

        expect(app(PetActivityService::class)->clean($pet)->status)->toBe(ActionResult::ACCEPTED);
        expect($pet->fresh()->hygiene_level)->toBe(100.0);
    });

    it('settles an event that is due but not yet ticked, so the next tick does not dirty the pet again', function () {
        $pet = hyPet('mutt');
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        hyTick($pet, $at->copy()->subMinute()); // last tick before the event

        Carbon::setTestNow($at->copy()->addSeconds(30)); // event happened, no tick yet
        $result = app(PetActivityService::class)->clean($pet);
        $after = hyTick($pet, $at->copy()->addMinute());

        expect($result->status)->toBe(ActionResult::ACCEPTED);
        expect($after->hygiene_level)->toBe(100.0);
        expect(hyEvents($pet, HygieneEventStatus::Applied)->first()->cleaned_at)->not->toBeNull();
    });

    it('is refused while the pet is locked', function () {
        $pet = hyPet('mutt', pet: ['hygiene_level' => 0.0, 'is_hard_stopped' => true]);

        expect(app(PetActivityService::class)->clean($pet)->status)->toBe(ActionResult::LOCKED);
        expect($pet->fresh()->hygiene_level)->toBe(0.0);
    });
});

describe('Hygiene neglect', function () {
    it('makes the pet ill 6 h after an uncleaned event (no quiet hours)', function () {
        $pet = hyPet('mutt', pet: ['energy_level' => 100]);
        [$at] = hyPlant($pet, ['2026-10-05 08:17:00']); // 10:17 local
        // Keep energy out of the way (steps would be needed after midnight).
        $escalation = app(EscalationService::class);

        $cursor = $at->copy()->subMinutes(5);
        $illAt = null;
        while ($illAt === null && $cursor->lessThan($at->copy()->addHours(7))) {
            $cursor->addMinutes(5);
            hyTick($pet, $cursor->copy());
            Pet::whereKey($pet->id)->update(['energy_level' => 100, 'energy_zero_since' => null]);
            $escalation->processPetEscalation(Pet::findOrFail($pet->id));
            if (Pet::findOrFail($pet->id)->illness_until !== null) {
                $illAt = $cursor->copy();
            }
        }

        expect($at->diffInMinutes($illAt))->toBeGreaterThanOrEqual(360)->toBeLessThan(365);
    });
});
