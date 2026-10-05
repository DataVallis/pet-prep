<?php

use App\Enums\ActivityType;
use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use App\Services\Results\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
|--------------------------------------------------------------------------
| Rule changes approved by David on 2026-10-03 (PRODUCT_SPEC §5 / §7)
|--------------------------------------------------------------------------
|
| 1. Illness recovery = fresh start: when the 12 h illness ends, hygiene is
|    100 % (the vet cleaned the dog) and every neglect clock restarts at the
|    recovery moment. Hunger / thirst keep their values.
| 2. Energy = daily walk: no hourly neglect clock for energy. At the family
|    local midnight the finished day is closed (pet_daily_walks); no walk at
|    all → ill from the end of that night's quiet hours. Some steps below
|    the goal → missed goal only.
|
| Family: Europe/Ljubljana (CEST = UTC+2 until 2026-10-25), quiet hours
| 22:00–06:00 unless a test says otherwise. All instants below are UTC.
*/

const DW_BEDTIME = ['bedtime_start' => '22:00', 'bedtime_end' => '06:00'];

function dwPet(array $quietHours = DW_BEDTIME, array $pet = [], string $breed = 'mutt'): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quietHours !== []) {
        QuietHours::create(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));
    }

    // Born days ago (no birth-day grace) unless the test says otherwise.
    return disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => $breed,
        'born_at' => now()->copy()->subDays(5),
        'energy_level' => 0,
        'daily_step_count' => 0,
        'last_step_reset_at' => now(),
    ], $pet)));
}

function dwTick(Pet $pet, string $utc): Pet
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
    app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

    return Pet::findOrFail($pet->id);
}

function dwSteps(Pet $pet, int $steps): ActionResult
{
    return app(PetActivityService::class)->recordSteps(Pet::findOrFail($pet->id), $steps, now());
}

/**
 * Game loop (decay + escalation) every 5 minutes. Returns the UTC start
 * ('Y-m-d H:i') of every illness that began during the run.
 *
 * @return list<string>
 */
function dwLoop(Pet $pet, string $fromUtc, string $toUtc, ?callable $beforeTick = null): array
{
    $cursor = Carbon::parse($fromUtc, 'UTC');
    $end = Carbon::parse($toUtc, 'UTC');
    $starts = [];
    $seen = Pet::findOrFail($pet->id)->illness_until?->toIso8601String();

    while ($cursor->lessThanOrEqualTo($end)) {
        Carbon::setTestNow($cursor);
        if ($beforeTick) {
            $beforeTick($cursor->copy());
        }
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

        $until = Pet::findOrFail($pet->id)->illness_until;
        if ($until !== null && $until->toIso8601String() !== $seen) {
            $starts[] = $until->copy()->subHours(EscalationService::ILLNESS_LOCKOUT_HOURS)->utc()->format('Y-m-d H:i');
            $seen = $until->toIso8601String();
        }
        $cursor->addMinutes(5);
    }

    return $starts;
}

/**
 * Keep hunger and thirst full so a test is only about the rule it names.
 */
function dwFeed(Pet $pet): void
{
    Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100]);
}

beforeEach(function () {
    seedBreedConfigs();
    Carbon::setTestNow('2026-10-05 10:00:00'); // Mon 12:00 CEST
});

// ──────────────────────────────────────────────────────────────────────
//  1. Illness recovery = fresh start
// ──────────────────────────────────────────────────────────────────────

describe('Illness recovery (fresh start)', function () {
    it('cleans the dog and restarts every neglect clock at the moment the illness ends', function () {
        $pet = dwPet(pet: [
            'hunger_level' => 0,
            'thirst_level' => 12.5,
            'hygiene_level' => 0,
            'hunger_zero_since' => now()->subHours(20),
            'hygiene_zero_since' => now()->subHours(8),
            'escalation_level' => 3,
        ]);
        $pet->update(['illness_until' => now()->addHours(12), 'pet_state' => 'sick']);
        $illnessUntil = Carbon::parse('2026-10-05 22:00:00', 'UTC');

        $ill = dwTick($pet, '2026-10-05 21:55:00');
        expect($ill->isIll())->toBeTrue();
        expect($ill->hygiene_level)->toBe(0.0);

        Event::fake([PetUpdated::class]);
        $after = dwTick($pet, '2026-10-05 22:05:00'); // first tick after the lockout

        expect($after->illness_until)->toBeNull();
        expect($after->hygiene_level)->toBe(100.0);
        expect($after->hygiene_zero_since)->toBeNull();
        expect($after->hunger_zero_since->equalTo($illnessUntil))->toBeTrue();
        expect($after->thirst_zero_since)->toBeNull();
        expect($after->hunger_level)->toBe(0.0);             // the child can feed now
        expect($after->thirst_level)->toBeLessThan(12.5);    // decays again from the recovery
        expect($after->thirst_level)->toBeGreaterThan(12.0);
        expect($after->frozen_at)->toBeNull();
        expect($after->last_decay_at->equalTo(Carbon::parse('2026-10-05 22:05:00')))->toBeTrue();
        expect($after->pet_state)->not->toBe(PetStateEnum::Sick);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id);
    });

    it('does not fall ill again right after recovering (the old infinite illness loop)', function () {
        // Mess at 09:00 local, never cleaned: ill at 15:00 local (6 h outside
        // quiet hours), well at 03:00 local. The dog must stay healthy after
        // that instead of falling ill again at 06:00.
        Carbon::setTestNow('2026-10-05 06:00:00');
        $pet = dwPet(pet: ['last_step_reset_at' => now()->subDay(), 'daily_step_count' => 4000, 'energy_level' => 100]);

        $illnesses = dwLoop($pet, '2026-10-05 06:55:00', '2026-10-06 19:55:00', function (Carbon $at) use ($pet) {
            dwFeed($pet);
            if ($at->format('Y-m-d H:i') === '2026-10-05 07:00') {
                Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => $at]);
            }
            if ($at->format('H:i') === '08:00') {      // walked every day at 10:00 local
                dwSteps($pet, 4000);
            }
        });

        expect($illnesses)->toBe(['2026-10-05 13:00']);
        $fresh = $pet->fresh();
        expect($fresh->isIll())->toBeFalse();
        expect($fresh->hygiene_level)->toBe(100.0);
        expect($fresh->is_game_over)->toBeFalse();
    });

    it('makes a dog that is neglected again after recovery ill again after the normal 6 h', function () {
        Carbon::setTestNow('2026-10-05 06:00:00');
        $pet = dwPet(pet: ['last_step_reset_at' => now()->subDay(), 'daily_step_count' => 4000, 'energy_level' => 100]);

        // Second mess at 07:00 local (05:00 UTC) the next morning, after the
        // 03:00 recovery: 07:00–13:00 local is outside quiet hours → ill at 13:00.
        $illnesses = dwLoop($pet, '2026-10-05 06:55:00', '2026-10-06 19:55:00', function (Carbon $at) use ($pet) {
            dwFeed($pet);
            if (in_array($at->format('Y-m-d H:i'), ['2026-10-05 07:00', '2026-10-06 05:00'], true)) {
                Pet::whereKey($pet->id)->update(['hygiene_level' => 0, 'hygiene_zero_since' => $at]);
            }
            if ($at->format('H:i') === '08:00') {
                dwSteps($pet, 4000);
            }
        });

        expect($illnesses)->toBe(['2026-10-05 13:00', '2026-10-06 11:00']);
    });

    it('does not count the illness or the time before it towards game over', function () {
        // Walked today, so the midnight walk rule doesn't interfere.
        $pet = dwPet(quietHours: [], pet: ['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(23), 'daily_step_count' => 4000, 'energy_level' => 100]);
        $pet->update(['illness_until' => now()->addHours(12), 'pet_state' => 'sick']);

        // Hunger stays 0 after recovery (22:00): game over 24 h later, not 1 h.
        dwTick($pet, '2026-10-05 22:00:00');
        expect(dwTick($pet, '2026-10-06 21:59:00')->is_game_over)->toBeFalse();
        expect(dwTick($pet, '2026-10-06 22:00:00')->is_game_over)->toBeTrue();
    });

    it('keeps a hard stop frozen through the recovery and restarts the clocks when it is lifted', function () {
        $pet = dwPet(quietHours: [], pet: ['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(20)]);
        $pet->update(['illness_until' => now()->addHours(12), 'pet_state' => 'sick']);
        $pet->refresh()->update(['is_hard_stopped' => true]);

        $stopped = dwTick($pet, '2026-10-05 23:00:00'); // illness over at 22:00, still stopped
        expect($stopped->illness_until)->toBeNull();
        expect($stopped->hygiene_level)->toBe(100.0);
        expect($stopped->frozen_at->equalTo(Carbon::parse('2026-10-05 22:00:00')))->toBeTrue();

        Carbon::setTestNow('2026-10-06 03:00:00');
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => false]);

        $lifted = Pet::findOrFail($pet->id);
        expect($lifted->hunger_zero_since->equalTo(Carbon::parse('2026-10-06 03:00:00')))->toBeTrue();
        expect($lifted->frozen_at)->toBeNull();
    });

    it('applies the recovery when a step sync arrives before the next tick', function () {
        $pet = dwPet(pet: ['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);
        $pet->update(['illness_until' => now()->addHours(12), 'pet_state' => 'sick']);

        Carbon::setTestNow('2026-10-05 22:01:00'); // 00:01 local, ill until 22:00 UTC
        $result = dwSteps($pet, 100);

        expect($result->status)->toBe(ActionResult::ACCEPTED);
        $fresh = $pet->fresh();
        expect($fresh->illness_until)->toBeNull();
        expect($fresh->hygiene_level)->toBe(100.0);
        expect($fresh->hygiene_zero_since)->toBeNull();
    });
});

// ──────────────────────────────────────────────────────────────────────
//  2. Energy = daily walk
// ──────────────────────────────────────────────────────────────────────

describe('Daily walk: day closed at the family-local midnight', function () {
    it('records yesterday\'s walk once (steps, goal, achieved) and resets the day', function () {
        $pet = dwPet();
        dwSteps($pet, 4200);

        dwTick($pet, '2026-10-05 21:59:00');
        expect(PetDailyWalk::count())->toBe(0);

        $after = dwTick($pet, '2026-10-05 22:00:00'); // 00:00 local
        dwTick($pet, '2026-10-05 22:01:00');
        dwTick($pet, '2026-10-06 04:00:00');

        $walks = PetDailyWalk::where('pet_id', $pet->id)->get();
        expect($walks)->toHaveCount(1);
        expect($walks[0]->local_date->toDateString())->toBe('2026-10-05');
        expect($walks[0]->steps)->toBe(4200);
        expect($walks[0]->goal)->toBe(4000);
        expect($walks[0]->achieved)->toBeTrue();
        expect($walks[0]->birth_day)->toBeFalse();
        expect($walks[0]->illness_due_at)->toBeNull();
        expect($after->daily_step_count)->toBe(0);
        expect($after->energy_level)->toBe(0.0);
        expect($after->walk_illness_due_at)->toBeNull();
    });

    it('uses the breed goal (border collie 10,000)', function () {
        $pet = dwPet(breed: 'border_collie');
        dwSteps($pet, 9000);

        dwTick($pet, '2026-10-05 22:00:00');

        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();
        expect($walk->goal)->toBe(10000);
        expect($walk->achieved)->toBeFalse();
    });

    it('only records a missed goal when the child walked some steps below the goal', function () {
        $pet = dwPet();
        dwSteps($pet, 1500);

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 19:55:00', fn () => dwFeed($pet));

        expect($illnesses)->toBe([]);
        $walk = PetDailyWalk::where('pet_id', $pet->id)->where('local_date', '2026-10-05')->sole();
        expect($walk->steps)->toBe(1500);
        expect($walk->achieved)->toBeFalse();
        expect($walk->illness_due_at)->toBeNull();
    });

    it('makes the dog ill at the END of the night\'s quiet hours when there was no walk at all', function (int $steps) {
        $pet = dwPet();
        if ($steps > 0) {
            dwSteps($pet, $steps);
        }

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 06:00:00', fn () => dwFeed($pet));

        // Midnight 22:00 UTC; quiet until 06:00 local = 04:00 UTC.
        expect($illnesses)->toBe(['2026-10-06 04:00']);
        $fresh = $pet->fresh();
        expect($fresh->illness_until->equalTo(Carbon::parse('2026-10-06 16:00:00')))->toBeTrue();
        expect($fresh->walk_illness_due_at)->toBeNull();
        expect($fresh->pet_state)->toBe(PetStateEnum::Sick);

        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();
        expect($walk->illness_due_at->equalTo(Carbon::parse('2026-10-06 04:00:00')))->toBeTrue();
        expect($walk->illness_started_at->equalTo(Carbon::parse('2026-10-06 04:00:00')))->toBeTrue();
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::IgnoredWarning->value)->where('value', -1)->count())->toBe(1);
    })->with([
        'no steps' => [0],
        '10 steps (energy shows 0 %)' => [10],
    ]);

    it('waits for the end of a quiet stretch that continues into the morning', function () {
        $pet = dwPet(quietHours: [
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'school_start' => '06:00', 'school_end' => '13:00',
        ]);

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 12:00:00', fn () => dwFeed($pet));

        expect($illnesses)->toBe(['2026-10-06 11:00']); // 13:00 local
    });

    it('starts the illness at midnight when midnight is not in quiet hours', function () {
        $pet = dwPet(quietHours: []);

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-05 22:30:00', fn () => dwFeed($pet));

        expect($illnesses)->toBe(['2026-10-05 22:00']);
    });

    it('closes the day in a step sync that arrives before the midnight tick', function () {
        $pet = dwPet();
        dwTick($pet, '2026-10-05 21:00:00');

        Carbon::setTestNow('2026-10-05 22:30:00'); // 00:30 local, no tick since 23:00
        expect(dwSteps($pet, 50)->status)->toBe(ActionResult::ACCEPTED);

        $fresh = $pet->fresh();
        expect($fresh->daily_step_count)->toBe(50);   // today's steps don't save yesterday
        expect($fresh->walk_illness_due_at->equalTo(Carbon::parse('2026-10-06 04:00:00')))->toBeTrue();
        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->steps)->toBe(0);

        expect(dwLoop($pet, '2026-10-05 22:35:00', '2026-10-06 04:30:00', fn () => dwFeed($pet)))->toBe(['2026-10-06 04:00']);
    });

    it('never makes the dog ill for its birth day', function () {
        $pet = dwPet(pet: ['born_at' => now(), 'energy_level' => 0]); // born today, no steps

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 06:00:00', fn () => dwFeed($pet));

        expect($illnesses)->toBe([]);
        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();
        expect($walk->birth_day)->toBeTrue();
        expect($walk->illness_due_at)->toBeNull();
    });

    it('does not make the dog ill for a day closed during a hard stop', function () {
        $pet = dwPet();
        dwTick($pet, '2026-10-05 19:00:00');
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);

        dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 02:00:00');
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => false]);

        expect(dwLoop($pet, '2026-10-06 02:05:00', '2026-10-06 08:00:00', fn () => dwFeed($pet)))->toBe([]);
        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();      // still recorded
        expect($walk->steps)->toBe(0);
        expect($walk->illness_due_at)->toBeNull();
        expect($pet->fresh()->daily_step_count)->toBe(0);
    });

    it('drops a walk illness that comes due while the parent has hard-stopped the game', function () {
        $pet = dwPet();
        dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 01:00:00', fn () => dwFeed($pet));
        expect($pet->fresh()->walk_illness_due_at)->not->toBeNull();

        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);
        dwLoop($pet, '2026-10-06 01:05:00', '2026-10-06 05:00:00');
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => false]);

        expect(dwLoop($pet, '2026-10-06 05:05:00', '2026-10-06 08:00:00', fn () => dwFeed($pet)))->toBe([]);
        expect($pet->fresh()->walk_illness_due_at)->toBeNull();
    });

    it('does not add a walk illness to a dog that is already ill at midnight', function () {
        $pet = dwPet();
        $pet->update(['illness_until' => Carbon::parse('2026-10-06 02:00:00'), 'pet_state' => 'sick']);

        $illnesses = dwLoop($pet, '2026-10-05 21:55:00', '2026-10-06 08:00:00', fn () => dwFeed($pet));

        expect($illnesses)->toBe([]);
        expect($pet->fresh()->isIll())->toBeFalse();
        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->illness_due_at)->toBeNull();
    });

    it('does not make the dog ill when more than one midnight passed (scheduler outage)', function () {
        $pet = dwPet();
        dwTick($pet, '2026-10-05 21:00:00');

        $after = dwTick($pet, '2026-10-07 08:00:00'); // first tick two days later

        expect($after->isIll())->toBeFalse();
        expect($after->walk_illness_due_at)->toBeNull();
        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->local_date->toDateString())->toBe('2026-10-05');
    });
});

describe('Daily walk: energy escalation only outside quiet hours', function () {
    it('shows low energy from 06:00 local without touching the phase ladder (walk reminder is separate)', function () {
        Carbon::setTestNow('2026-10-05 21:30:00'); // 23:30 local, walked today
        $pet = dwPet(pet: ['daily_step_count' => 4000, 'energy_level' => 100]);

        $night = dwTick($pet, '2026-10-05 22:00:00'); // 00:00 local: energy → 0
        expect($night->energy_level)->toBe(0.0);
        expect($night->escalation_level)->toBe(0);
        expect($night->pet_state)->toBe(PetStateEnum::Sleeping);

        dwFeed($pet);
        expect(dwTick($pet, '2026-10-06 03:59:00')->escalation_level)->toBe(0);

        dwFeed($pet);
        $morning = dwTick($pet, '2026-10-06 04:00:00'); // 06:00 local
        expect($morning->escalation_level)->toBe(0); // PR #35 re-review: energy is not on the ladder
        expect($morning->pet_state)->toBe(PetStateEnum::LowEnergy);
        expect($morning->isIll())->toBeFalse();
    });

    it('still escalates hunger during quiet hours', function () {
        Carbon::setTestNow('2026-10-05 20:59:00');
        $pet = dwPet(pet: ['hunger_level' => 25, 'energy_level' => 100, 'last_step_reset_at' => now()->subDay(), 'daily_step_count' => 4000]);

        expect(dwTick($pet, '2026-10-05 21:00:00')->escalation_level)->toBe(1); // 23:00 local
    });
});

describe('Parent dashboard counts a walk once per day', function () {
    it('counts many step syncs as one completed walk', function () {
        $pet = dwPet();
        $parent = $pet->user->parent;

        foreach ([[1000, '09:00'], [2500, '09:20'], [4100, '09:40'], [6000, '09:59']] as [$count, $time]) {
            app(PetActivityService::class)->recordSteps(Pet::findOrFail($pet->id), $count, Carbon::parse("2026-10-05 {$time}:00", 'UTC'));
        }

        actingAs($parent, 'sanctum');
        $today = collect(getJson('/api/parent/dashboard')->assertOk()->json('weekly_performance'))
            ->firstWhere('date', '2026-10-05');

        expect($today['completed'])->toBe(1);
    });
});

// ──────────────────────────────────────────────────────────────────────
//  Review follow-ups (PR #10)
// ──────────────────────────────────────────────────────────────────────

describe('Daily walk: review follow-ups', function () {
    it('skips a walk illness whose 12 h are already over when the scheduler comes back (down 06:00 → 19:00 local)', function () {
        $pet = dwPet(pet: ['hunger_level' => 0, 'hunger_zero_since' => Carbon::parse('2026-10-06 03:00:00')]);

        dwTick($pet, '2026-10-05 22:00:00');                       // midnight: no walk → due 04:00 UTC
        dwTick($pet, '2026-10-06 03:55:00');
        expect($pet->fresh()->walk_illness_due_at->equalTo(Carbon::parse('2026-10-06 04:00:00')))->toBeTrue();

        $back = dwTick($pet, '2026-10-06 17:00:00');                // 19:00 local, due + 12 h = 16:00 UTC

        expect($back->illness_until)->toBeNull();
        expect($back->walk_illness_due_at)->toBeNull();
        expect($back->hunger_zero_since->equalTo(Carbon::parse('2026-10-06 03:00:00')))->toBeTrue(); // no fresh start
        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();
        expect($walk->illness_started_at)->toBeNull();
        expect($walk->illness_skipped_at->equalTo(Carbon::parse('2026-10-06 17:00:00')))->toBeTrue();
        expect(ActivityLog::where('pet_id', $pet->id)->where('value', -1)->count())->toBe(0);
    });

    it('keeps the planned start when the tick is late but inside the 12 h', function () {
        $pet = dwPet();
        dwTick($pet, '2026-10-05 22:00:00');

        $late = dwTick($pet, '2026-10-06 10:00:00'); // 6 h late

        expect($late->illness_until->equalTo(Carbon::parse('2026-10-06 16:00:00')))->toBeTrue();
        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->illness_skipped_at)->toBeNull();
    });

    it('records a walk illness dropped during a hard stop as skipped', function () {
        $pet = dwPet();
        dwTick($pet, '2026-10-05 22:00:00');
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);

        dwTick($pet, '2026-10-06 04:05:00');

        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->illness_skipped_at)->not->toBeNull();
    });

    it('clears a planned walk illness on game over and on reactivation', function () {
        $pet = dwPet(pet: [
            'hunger_level' => 0,
            'hunger_zero_since' => now()->subHours(25),
            'walk_illness_due_at' => now()->addHours(3),
        ]);

        app(EscalationService::class)->processPetEscalation($pet);
        expect($pet->fresh()->is_game_over)->toBeTrue();
        expect($pet->fresh()->walk_illness_due_at)->toBeNull();

        Pet::whereKey($pet->id)->update(['walk_illness_due_at' => now()->addHours(3)]);
        Pet::findOrFail($pet->id)->update(['is_game_over' => false, 'is_active' => true]);
        expect($pet->fresh()->walk_illness_due_at)->toBeNull();
    });

    it('broadcasts once when a step sync closed the day but the sync itself was stale', function () {
        $pet = dwPet();
        dwSteps($pet, 4000);

        Event::fake([PetUpdated::class]);
        Carbon::setTestNow('2026-10-05 22:30:00'); // 00:30 local, no tick since the morning
        $result = app(PetActivityService::class)->recordSteps(Pet::findOrFail($pet->id), 4100, Carbon::parse('2026-10-05 21:50:00'));

        expect($result->status)->toBe(ActionResult::STALE);
        expect($pet->fresh()->energy_level)->toBe(0.0);
        expect(PetDailyWalk::where('pet_id', $pet->id)->sole()->achieved)->toBeTrue();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'metric_changed');
    });

    it('does not broadcast an unchanged sync that closed no day', function () {
        $pet = dwPet();
        dwSteps($pet, 1000);

        Event::fake([PetUpdated::class]);
        expect(dwSteps($pet, 1000)->status)->toBe(ActionResult::UNCHANGED);
        Event::assertNotDispatched(PetUpdated::class);
    });
});

describe('Energy backfill migration', function () {
    it('sets energy from today\'s steps for existing pets and keeps birth-day grace', function () {
        $old = dwPet(pet: ['daily_step_count' => 1000, 'energy_level' => 100]);
        $collie = dwPet(pet: ['daily_step_count' => 5000, 'energy_level' => 0], breed: 'border_collie');
        $over = dwPet(pet: ['daily_step_count' => 9000, 'energy_level' => 10]);
        $newborn = dwPet(pet: ['born_at' => now(), 'daily_step_count' => 0, 'energy_level' => 100]);

        (require database_path('migrations/2026_10_03_160100_backfill_pet_energy_from_steps.php'))->up();

        expect($old->fresh()->energy_level)->toEqualWithDelta(25.0, 1e-9);
        expect($collie->fresh()->energy_level)->toEqualWithDelta(50.0, 1e-9);
        expect($over->fresh()->energy_level)->toBe(100.0);
        expect($newborn->fresh()->energy_level)->toBe(100.0);
    });
});
