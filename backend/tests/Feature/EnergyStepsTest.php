<?php

use App\Enums\ActivityType;
use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use App\Services\Results\ActionResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Energy from steps (M1-04, PRODUCT_SPEC §5 "Gibanje")
|--------------------------------------------------------------------------
|
| energy = min(100, daily_step_count / daily_steps_required × 100), mutt
| 4,000 / border collie 10,000 steps. Not time-decayed; back to 0 at the
| family's local midnight. Anti-cheat: ≤ 200 steps / min since the last
| accepted sync. All instants are UTC; the family is in Europe/Ljubljana
| (CEST = UTC+2 until 2026-10-25).
*/

/**
 * A Ljubljana family whose pet already lived through a midnight, so energy
 * follows the formula (no birth-day grace). Current time: 12:00 local.
 */
function stepsPet(string $breed = 'mutt', array $quietHours = [], array $pet = []): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quietHours !== []) {
        QuietHours::create(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));
    }

    return disableHygieneEvents(Pet::factory()->create(array_merge([
        'user_id' => $child->id,
        'breed_type' => $breed,
        'energy_level' => 0,
        'daily_step_count' => 0,
        'last_step_reset_at' => now()->copy()->subDay(),
    ], $pet)));
}

function steps(Pet $pet, int $stepsToday, ?string $recordedAtUtc = null): ActionResult
{
    $at = $recordedAtUtc ? Carbon::parse($recordedAtUtc, 'UTC') : now();

    return app(PetActivityService::class)->recordSteps($pet, $stepsToday, $at);
}

function walkedRows(Pet $pet)
{
    return ActivityLog::where('pet_id', $pet->id)->where('activity_type', ActivityType::WalkedPet->value)->orderBy('id')->get();
}

/**
 * Run decay + escalation every 5 minutes from $fromUtc to $toUtc, with an
 * optional step sync callback. Returns the UTC time illness started.
 */
function runLoop(Pet $pet, string $fromUtc, string $toUtc, ?callable $beforeTick = null): ?string
{
    $cursor = Carbon::parse($fromUtc, 'UTC');
    $end = Carbon::parse($toUtc, 'UTC');

    while ($cursor->lessThanOrEqualTo($end)) {
        Carbon::setTestNow($cursor);
        if ($beforeTick) {
            $beforeTick($cursor->copy());
        }
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

        $fresh = Pet::findOrFail($pet->id);
        if ($fresh->illness_until !== null) {
            return $cursor->format('Y-m-d H:i');
        }
        $cursor->addMinutes(5);
    }

    return null;
}

beforeEach(function () {
    seedBreedConfigs();
    Carbon::setTestNow('2026-10-05 10:00:00'); // 12:00 CEST
});

describe('Energy formula', function () {
    it('is steps / daily goal for a mutt (4,000) and a border collie (10,000)', function () {
        $mutt = stepsPet('mutt');
        $collie = stepsPet('border_collie');

        $muttResult = steps($mutt, 1000);
        $collieResult = steps($collie, 1000);

        expect($muttResult->status)->toBe(ActionResult::ACCEPTED);
        expect($muttResult->energyLevel)->toBe(25);
        expect($mutt->fresh()->energy_level)->toEqualWithDelta(25.0, 1e-9);
        expect($collieResult->energyLevel)->toBe(10);
        expect($collie->fresh()->daily_step_count)->toBe(1000);
    });

    it('keeps the precise value and rounds only for display', function () {
        $pet = stepsPet();

        steps($pet, 1001);

        expect($pet->fresh()->energy_level)->toEqualWithDelta(25.025, 1e-9);
        expect($pet->fresh()->displayMetric('energy_level'))->toBe(25);
    });

    it('caps energy at 100 % when the goal is exceeded', function () {
        $pet = stepsPet();

        $result = steps($pet, 6000); // 12 h since midnight allow far more

        expect($result->energyLevel)->toBe(100);
        expect($pet->fresh()->energy_level)->toBe(100.0);
        expect($pet->fresh()->daily_step_count)->toBe(6000);
    });

    it('is not time-decayed by the game loop', function () {
        $pet = stepsPet();
        steps($pet, 2000);

        foreach (range(1, 10) as $hour) {
            Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00')->addMinutes(55 * $hour)); // stays before local midnight
            app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        }

        expect($pet->fresh()->energy_level)->toEqualWithDelta(50.0, 1e-9);
    });

    it('has no energy neglect clock: a sync clears a legacy energy_zero_since', function () {
        $pet = stepsPet(pet: ['energy_zero_since' => now()->subHours(3)]);

        steps($pet, 10); // 0.25 % → shows 0 %

        expect($pet->fresh()->energy_zero_since)->toBeNull();
    });
});

describe('Local midnight reset (Europe/Ljubljana)', function () {
    it('drops steps and energy to 0 at 00:00 CEST (22:00 UTC) without a neglect clock', function () {
        $pet = stepsPet(pet: ['last_step_reset_at' => now()]);
        steps($pet, 4000);
        expect($pet->fresh()->energy_level)->toBe(100.0);

        $tick = function (string $utc) use ($pet): Pet {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            app(PetDecayService::class)->processPetDecay($fresh = Pet::findOrFail($pet->id));

            return $fresh->refresh();
        };

        $before = $tick('2026-10-05 21:59:00');
        expect($before->energy_level)->toBe(100.0);
        expect($before->daily_step_count)->toBe(4000);

        $after = $tick('2026-10-05 22:00:00');
        expect($after->daily_step_count)->toBe(0);
        expect($after->energy_level)->toBe(0.0);
        expect($after->energy_zero_since)->toBeNull();
        expect($after->last_step_sync_at)->toBeNull();

        // No second reset at UTC midnight.
        Pet::whereKey($pet->id)->update(['daily_step_count' => 300, 'energy_level' => 7.5]);
        expect($tick('2026-10-06 00:01:00')->energy_level)->toBe(7.5);
    });

    it('resets at 00:00 CET (23:00 UTC) in winter', function () {
        Carbon::setTestNow('2026-12-15 10:00:00');
        $pet = stepsPet(pet: ['last_step_reset_at' => now()]);
        steps($pet, 2000);

        Carbon::setTestNow('2026-12-15 22:59:00');
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        expect($pet->fresh()->energy_level)->toEqualWithDelta(50.0, 1e-9);

        Carbon::setTestNow('2026-12-15 23:00:00');
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        expect($pet->fresh()->energy_level)->toBe(0.0);
    });

    it('applies the reset in the step sync itself when no tick ran since midnight', function () {
        $pet = stepsPet(pet: ['last_step_reset_at' => now()]);
        steps($pet, 4000);

        Carbon::setTestNow('2026-10-05 22:30:00'); // 00:30 local, no tick in between
        $result = steps($pet, 1000);               // today's count from the device

        expect($result->status)->toBe(ActionResult::ACCEPTED);
        expect($result->dailyStepCount)->toBe(1000);
        expect($result->energyLevel)->toBe(25);
    });

    it('lets a newborn pet keep its full energy until its first local midnight', function () {
        $child = User::factory()->child()->create();
        $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id])); // energy 100, born 12:00 local

        $result = steps($pet, 400);

        expect($result->energyLevel)->toBe(100); // a sync never lowers energy
        expect($pet->fresh()->daily_step_count)->toBe(400);

        Carbon::setTestNow('2026-10-05 22:00:00');
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        expect($pet->fresh()->energy_level)->toBe(0.0);

        Carbon::setTestNow('2026-10-06 06:00:00');
        expect(steps($pet, 400)->energyLevel)->toBe(10);
    });
});

describe('Idempotency and anti-cheat', function () {
    it('takes the max of today\'s count: repeats and lower counts change nothing', function () {
        $pet = stepsPet();
        Event::fake([PetUpdated::class]);

        expect(steps($pet, 1000)->status)->toBe(ActionResult::ACCEPTED);
        expect(steps($pet, 1000)->status)->toBe(ActionResult::UNCHANGED);
        expect(steps($pet, 800)->status)->toBe(ActionResult::UNCHANGED);

        expect($pet->fresh()->daily_step_count)->toBe(1000);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('logs one walked_pet activity per day, when a sync first reaches the goal', function () {
        $pet = stepsPet(); // mutt, goal 4,000

        steps($pet, 1000, '2026-10-05 09:00:00');
        steps($pet, 3000, '2026-10-05 09:30:00');
        expect(walkedRows($pet))->toHaveCount(0);

        steps($pet, 4200, '2026-10-05 09:50:00');
        steps($pet, 5000, '2026-10-05 09:58:00');

        expect(walkedRows($pet)->pluck('value')->all())->toBe([4200]);
    });

    it('broadcasts one PetUpdated(walked_pet) per accepted sync, none from the activity observer', function () {
        $pet = stepsPet();
        Event::fake([PetUpdated::class]);

        steps($pet, 1000);

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'walked_pet'
            && $e->petId === $pet->id);
    });

    it('caps an increment above 200 steps per minute since the last sync', function () {
        $pet = stepsPet();
        steps($pet, 1000, '2026-10-05 09:55:00');

        // 5 minutes later the device claims +2,000 → only 5 × 200 = 1,000 accepted.
        $capped = steps($pet, 3000, '2026-10-05 10:00:00');

        expect($capped->status)->toBe(ActionResult::CAPPED);
        expect($capped->acceptedSteps)->toBe(1000);
        expect($capped->dailyStepCount)->toBe(2000);
        expect($pet->fresh()->last_step_sync_at->format('H:i'))->toBe('10:00');
    });

    it('rejects an increment when no time passed since the last accepted sync', function () {
        $pet = stepsPet();
        steps($pet, 1000, '2026-10-05 10:00:00');

        $rejected = steps($pet, 1500, '2026-10-05 10:00:00');

        expect($rejected->status)->toBe(ActionResult::REJECTED);
        expect($pet->fresh()->daily_step_count)->toBe(1000);
    });

    it('accepts the refused remainder later once enough time has passed', function () {
        $pet = stepsPet();
        steps($pet, 1000, '2026-10-05 09:55:00');
        steps($pet, 3000, '2026-10-05 10:00:00'); // capped at 2,000

        Carbon::setTestNow('2026-10-05 10:10:00');
        $later = steps($pet, 3000);

        expect($later->status)->toBe(ActionResult::ACCEPTED);
        expect($later->acceptedSteps)->toBe(1000);
        expect($later->dailyStepCount)->toBe(3000);
    });

    it('measures the first sync of the day from local midnight', function () {
        Carbon::setTestNow('2026-10-05 22:10:00'); // 00:10 CEST on 6 Oct
        $pet = stepsPet();

        $result = steps($pet, 3000);

        expect($result->status)->toBe(ActionResult::CAPPED);
        expect($result->dailyStepCount)->toBe(2000); // 10 min × 200
    });

    it('treats a recorded_at in the future as now', function () {
        $pet = stepsPet();
        steps($pet, 1000, '2026-10-05 09:59:00');

        $result = steps($pet, 5000, '2026-10-05 12:00:00'); // device clock 2 h ahead

        expect($result->dailyStepCount)->toBe(1200); // 1 real minute → +200
        expect($pet->fresh()->last_step_sync_at->format('H:i'))->toBe('10:00');
    });

    it('ignores a sync recorded on a local day that is already over', function () {
        Carbon::setTestNow('2026-10-05 22:30:00'); // 00:30 local on 6 Oct
        $pet = stepsPet(pet: ['last_step_reset_at' => now()]);

        $result = steps($pet, 3000, '2026-10-05 21:30:00'); // 23:30 local on 5 Oct

        expect($result->status)->toBe(ActionResult::STALE);
        expect($pet->fresh()->daily_step_count)->toBe(0);
        expect(walkedRows($pet))->toHaveCount(0);
    });

    it('computes from the locked row, not from a stale model', function () {
        $pet = stepsPet();
        $stale = Pet::findOrFail($pet->id);
        steps($pet, 2000, '2026-10-05 09:00:00');

        $result = steps($stale, 1500);

        expect($result->status)->toBe(ActionResult::UNCHANGED);
        expect($pet->fresh()->daily_step_count)->toBe(2000);
        expect($stale->daily_step_count)->toBe(2000); // synced back from the locked row
    });

    it('rejects a negative count', function () {
        steps(stepsPet(), -1);
    })->throws(InvalidArgumentException::class);
});

describe('Locked pets', function () {
    it('changes nothing while hard-stopped, ill or game over', function (array $state) {
        $pet = stepsPet(pet: $state);
        Event::fake([PetUpdated::class]);

        $result = steps($pet, 1000);

        expect($result->status)->toBe(ActionResult::LOCKED);
        expect($pet->fresh()->daily_step_count)->toBe(0);
        expect(walkedRows($pet))->toHaveCount(0);
        Event::assertNotDispatched(PetUpdated::class);
    })->with([
        'hard stop' => [['is_hard_stopped' => true]],
        'ill' => [['illness_until' => Carbon::parse('2026-10-05 20:00:00')]],
        'game over' => [['is_game_over' => true, 'is_active' => false]],
    ]);
});

describe('Energy is not an hourly neglect metric (daily walk rule, David 2026-10-03)', function () {
    it('never makes a pet ill, alarms the parent or ends the game from energy 0 % during the day', function () {
        Carbon::setTestNow('2026-10-05 18:00:00');
        $pet = stepsPet(quietHours: [
            'bedtime_start' => '22:00', 'bedtime_end' => '06:00',
            'school_start' => '08:00', 'school_end' => '13:00',
        ], pet: ['energy_level' => 100, 'last_step_reset_at' => now(), 'daily_step_count' => 4000]);

        // Walked yesterday; today fed and watered but no steps until 21:55 local.
        $illAt = runLoop($pet, '2026-10-05 18:00:00', '2026-10-06 19:55:00', function () use ($pet) {
            Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100]);
        });

        $fresh = $pet->fresh();
        expect($illAt)->toBeNull();
        expect($fresh->energy_level)->toBe(0.0);
        expect($fresh->energy_zero_since)->toBeNull();
        expect($fresh->escalation_level)->toBe(0);      // energy is off the phase ladder (walk reminder only, PR #35)
        expect($fresh->is_game_over)->toBeFalse();
        expect($fresh->pet_state)->toBe(PetStateEnum::LowEnergy);
    });
});
