<?php

use App\Events\PetUpdated;
use App\Models\Family;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\FamilyInviteService;
use App\Services\FamilyService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Changing the family time zone during the day (David 2026-11-08, »kar B«)
|--------------------------------------------------------------------------
|
| November 2026: Europe/Ljubljana = UTC+1, Europe/Lisbon = UTC+0,
| America/New_York = UTC−5. The Ljubljana midnight of 2026-11-08 is
| 2026-11-07 23:00 UTC. All instants below are UTC.
*/

const TZC_BEDTIME = ['bedtime_start' => '22:00', 'bedtime_end' => '06:00'];

/**
 * @return array{0: User, 1: Pet}
 */
function tzcFamily(string $lastReset = '2026-11-07 23:00', int $steps = 0): array
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    setQuietHours(array_merge(['parent_id' => $parent->id, 'is_active' => true], TZC_BEDTIME));

    $pet = disableHygieneEvents(Pet::factory()->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse('2026-11-01 10:00', 'UTC'),
        'hunger_level' => 100,
        'thirst_level' => 100,
        'energy_level' => $steps > 0 ? 100 : 0,
        'daily_step_count' => $steps,
        'last_step_reset_at' => Carbon::parse($lastReset, 'UTC'),
        'last_decay_at' => now(),
    ]));

    return [$parent, $pet];
}

function tzcTick(Pet $pet, string $utc): Pet
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    Pet::whereKey($pet->id)->update(['hunger_level' => 100, 'thirst_level' => 100]);
    app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
    app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

    return Pet::findOrFail($pet->id);
}

function tzcChange(User $parent, string $utc, string $timezone): TestResponse
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    actingAs($parent, 'sanctum');

    return putJson('/api/parent/settings', ['timezone' => $timezone]);
}

/**
 * @return array<string, int>
 */
function tzcWalks(Pet $pet): array
{
    return PetDailyWalk::where('pet_id', $pet->id)->orderBy('local_date')->get()
        ->mapWithKeys(fn (PetDailyWalk $w) => [substr((string) $w->getRawOriginal('local_date'), 0, 10) => (int) $w->steps])
        ->all();
}

beforeEach(function () {
    seedBreedConfigs();
    Carbon::setTestNow(Carbon::parse('2026-11-08 08:00', 'UTC'));
});

describe('Ljubljana → Lisbon in the evening (same date in both zones)', function () {
    it('keeps today\'s steps and closes the day once, at the Lisbon midnight', function () {
        [$parent, $pet] = tzcFamily();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:00', 'UTC'));
        app(PetActivityService::class)->recordSteps($pet->fresh(), 3000, now());

        tzcChange($parent, '2026-11-08 19:30', 'Europe/Lisbon')->assertOk()
            ->assertJsonPath('settings.timezone', 'Europe/Lisbon');

        // Before the fix the 23:00 UTC reset stamp read as »yesterday« in Lisbon:
        // the next tick ran a second midnight and wiped today's steps.
        $after = tzcTick($pet, '2026-11-08 19:35');
        expect($after->daily_step_count)->toBe(3000)
            ->and($after->walk_illness_due_at)->toBeNull()
            ->and(tzcWalks($pet))->not->toHaveKey('2026-11-08');

        // 23:00 UTC = the old Ljubljana midnight: no longer a midnight.
        expect(tzcTick($pet, '2026-11-08 23:05')->daily_step_count)->toBe(3000);

        // 00:00 Lisbon = 00:00 UTC: the day closes with its steps.
        $closed = tzcTick($pet, '2026-11-09 00:01');
        expect($closed->daily_step_count)->toBe(0)
            ->and(tzcWalks($pet)['2026-11-08'] ?? null)->toBe(3000);
    });

    it('never schedules a walk illness at the change when the child has not walked yet', function () {
        [$parent, $pet] = tzcFamily();

        tzcChange($parent, '2026-11-08 19:30', 'Europe/Lisbon')->assertOk();
        $after = tzcTick($pet, '2026-11-08 19:35');

        expect($after->walk_illness_due_at)->toBeNull()
            ->and($after->illness_until)->toBeNull()
            ->and(tzcWalks($pet))->toBe([]);
    });
});

describe('Ljubljana → New York', function () {
    it('keeps the steps of a change during the day (same date) until the New York midnight', function () {
        [$parent, $pet] = tzcFamily();
        Carbon::setTestNow(Carbon::parse('2026-11-08 09:00', 'UTC'));
        app(PetActivityService::class)->recordSteps($pet->fresh(), 3000, now());

        // 15:00 Ljubljana = 09:00 New York.
        tzcChange($parent, '2026-11-08 14:00', 'America/New_York')->assertOk();
        expect(tzcTick($pet, '2026-11-08 14:05')->daily_step_count)->toBe(3000);

        // 00:00 New York = 05:00 UTC.
        expect(tzcTick($pet, '2026-11-09 04:55')->daily_step_count)->toBe(3000);
        $closed = tzcTick($pet, '2026-11-09 05:01');
        expect($closed->daily_step_count)->toBe(0)
            ->and(tzcWalks($pet))->toBe(['2026-11-08' => 3000])
            ->and($closed->walk_illness_due_at)->toBeNull();
    });

    it('repeats the date of a night-time change without a second walk illness', function () {
        // Day 2026-11-07 (Ljubljana) walked in full and closed at 23:00 UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-07 22:00', 'UTC'));
        [$parent, $pet] = tzcFamily(lastReset: '2026-11-06 23:00', steps: 6000);
        $closed = tzcTick($pet, '2026-11-07 23:01');
        expect($closed->daily_step_count)->toBe(0)
            ->and(tzcWalks($pet))->toBe(['2026-11-07' => 6000]);

        // 03:30 Ljubljana 2026-11-08 = 21:30 New York 2026-11-07: the date repeats.
        tzcChange($parent, '2026-11-08 02:30', 'America/New_York')->assertOk();
        expect(tzcTick($pet, '2026-11-08 02:35')->walk_illness_due_at)->toBeNull();

        // New York midnight closes 2026-11-07 a second time: the stored row stays,
        // and 0 steps in the repeated evening is not a day without a walk.
        $again = tzcTick($pet, '2026-11-08 05:01');
        expect(tzcWalks($pet))->toBe(['2026-11-07' => 6000])
            ->and($again->walk_illness_due_at)->toBeNull()
            ->and($again->illness_until)->toBeNull();
    });
});

describe('The change itself', function () {
    it('updates the family and every parent mirror together and broadcasts to every pet', function () {
        Event::fake([PetUpdated::class]);
        [$parent, $pet] = tzcFamily();
        $family = app(FamilyService::class)->familyOf($parent);
        $second = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        app(FamilyInviteService::class)->joinFamily($second, app(FamilyInviteService::class)->createInvite($parent)['code']);

        tzcChange($parent, '2026-11-08 19:30', 'Europe/Lisbon')->assertOk();

        expect($family->fresh()->timezone)->toBe('Europe/Lisbon')
            ->and($family->fresh()->timezone_changed_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-08 19:30')
            ->and($parent->fresh()->timezone)->toBe('Europe/Lisbon')
            ->and($second->fresh()->timezone)->toBe('Europe/Lisbon')
            ->and($pet->fresh()->familyTimezone())->toBe('Europe/Lisbon');
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'family_timezone_changed');
    });

    it('allows one change per 24 hours and answers timezone_change_too_soon in between', function () {
        [$parent] = tzcFamily();
        $family = app(FamilyService::class)->familyOf($parent);

        tzcChange($parent, '2026-11-08 19:30', 'Europe/Lisbon')->assertOk();

        tzcChange($parent, '2026-11-09 19:29', 'America/New_York')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'timezone_change_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-11-09T19:30:00+00:00');
        expect($family->fresh()->timezone)->toBe('Europe/Lisbon');

        // The same zone again is a no-op and never counts.
        tzcChange($parent, '2026-11-09 19:29', 'Europe/Lisbon')->assertOk();
        expect($family->fresh()->timezone_changed_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-08 19:30');

        tzcChange($parent, '2026-11-09 19:30', 'Europe/Ljubljana')->assertOk();
        expect($family->fresh()->timezone)->toBe('Europe/Ljubljana');
    });

    it('applies the same limit to the timezone of PUT /api/parent/quiet-hours and saves nothing', function () {
        [$parent] = tzcFamily();
        tzcChange($parent, '2026-11-08 19:30', 'Europe/Lisbon')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-08 20:00', 'UTC'));
        putJson('/api/parent/quiet-hours', [
            'bedtime_start' => '20:00',
            'bedtime_end' => '06:00',
            'timezone' => 'America/New_York',
        ])->assertStatus(422)->assertJsonPath('reason', 'timezone_change_too_soon');

        expect(QuietHours::where('family_id', app(FamilyService::class)->familyOf($parent)->id)->sole()->bedtime_start)
            ->toStartWith('22:00')
            ->and(Family::find(app(FamilyService::class)->familyOf($parent)->id)->timezone)->toBe('Europe/Lisbon');
    });
});
