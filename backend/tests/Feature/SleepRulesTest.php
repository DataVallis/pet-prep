<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\PetSleepService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-T02 — "kaj je dovoljeno kdaj" (APP_REFINEMENT_SPEC §8, David
| 2026-10-20): while the pet sleeps (the family's quiet hours) play AND
| school are off, and the apps say until when ("💤 Kuža spi do 7:00").
| `sleep: {asleep, until}` in the child state and PetUpdated; training start
| → 422 training_pet_sleeping (next_allowed_at = wake time).
|--------------------------------------------------------------------------
| Default quiet hours 21:00–07:00 family-local (QuietHours::DEFAULTS).
| Europe/Ljubljana is UTC+2 until 25. 10. 2026.
*/

beforeEach(function () {
    seedLifeStageData();
});

function slAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born dog with a profile and training, random messes off.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function slFamily(string $timezone = 'Europe/Ljubljana', string $bornUtc = '2026-10-07 05:00:00'): array
{
    slAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => $timezone]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = Pet::factory()->mutt()->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 2,
        'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);

    return [$parent, $child, $pet->fresh()];
}

function slState(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return getJson('/api/child/pet')->assertOk();
}

function slStartTraining(User $child): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/start', ['command' => 'sit']);
}

describe('sleep in the child state', function () {
    it('is awake during the day: sleep {asleep: false, until: null}, school may start', function () {
        [, $child] = slFamily();
        slAt('2026-10-08 10:00:00'); // 12:00 local

        slState($child)
            ->assertJsonPath('sleep', ['asleep' => false, 'until' => null])
            ->assertJsonPath('training.can_start', true)
            ->assertJsonPath('training.blocked_reason', null)
            ->assertJsonPath('training.next_allowed_at', null);
    });

    it('sleeps in the night quiet hours before AND after midnight, waking at 07:00 family time', function () {
        [, $child] = slFamily();

        slAt('2026-10-08 21:30:00'); // 23:30 local on 08.10 → wakes 09.10 07:00
        slState($child)
            ->assertJsonPath('sleep.asleep', true)
            ->assertJsonPath('sleep.until', '2026-10-09T07:00:00+02:00')
            ->assertJsonPath('pet.pet_state', 'idle'); // the stored state waits for the tick; `sleep` is live

        slAt('2026-10-08 23:30:00'); // 01:30 local on 09.10 → the same wake time
        slState($child)
            ->assertJsonPath('sleep.asleep', true)
            ->assertJsonPath('sleep.until', '2026-10-09T07:00:00+02:00');

        slAt('2026-10-09 05:00:00'); // 07:00 local — awake (the window is [21:00, 07:00))
        slState($child)->assertJsonPath('sleep', ['asleep' => false, 'until' => null]);
    });

    it('reads quiet hours on the family wall clock (America/New_York)', function () {
        [, $child] = slFamily('America/New_York');

        slAt('2026-10-09 03:00:00'); // 23:00 EDT on 08.10
        slState($child)
            ->assertJsonPath('timezone', 'America/New_York')
            ->assertJsonPath('sleep.asleep', true)
            ->assertJsonPath('sleep.until', '2026-10-09T07:00:00-04:00');

        // 21:00 UTC = 17:00 EDT: awake there (it would be 23:00 in Ljubljana).
        slAt('2026-10-08 21:00:00');
        slState($child)->assertJsonPath('sleep.asleep', false);
    });

    it('wakes at the end of a school window that directly follows the night', function () {
        [, $child, $pet] = slFamily();
        setQuietHours(['family_id' => $pet->family_id, 'school_start' => '07:00', 'school_end' => '13:00', 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);

        slAt('2026-10-09 03:00:00'); // 05:00 local, night → school → awake at 13:00
        slState($child)->assertJsonPath('sleep.until', '2026-10-09T13:00:00+02:00');
    });

    it('never sleeps when the parent switched quiet hours off', function () {
        [, $child, $pet] = slFamily();
        withoutQuietHours($pet);

        slAt('2026-10-08 22:00:00'); // midnight local
        slState($child)->assertJsonPath('sleep', ['asleep' => false, 'until' => null]);
        expect(app(PetSleepService::class)->wakesAt($pet->fresh(), now()))->toBeNull();
    });

    it('is in the PetUpdated broadcast with the family offset', function () {
        [, , $pet] = slFamily();
        slAt('2026-10-08 20:00:00'); // 22:00 local

        expect(PetUpdated::payloadFor($pet->fresh(), 'metric_changed')['sleep'])
            ->toBe(['asleep' => true, 'until' => '2026-10-09T07:00:00+02:00']);

        slAt('2026-10-08 12:00:00');
        expect(PetUpdated::payloadFor($pet->fresh(), 'metric_changed')['sleep'])
            ->toBe(['asleep' => false, 'until' => null]);
    });
});

describe('school while the pet sleeps', function () {
    it('refuses a start with 422 training_pet_sleeping until the wake time; can_start is false with the reason', function () {
        [, $child, $pet] = slFamily();
        slAt('2026-10-08 19:30:00'); // 21:30 local

        slStartTraining($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_pet_sleeping')
            ->assertJsonPath('next_allowed_at', '2026-10-09T07:00:00+02:00')
            ->assertJsonPath('state.training.can_start', false)
            ->assertJsonPath('state.training.blocked_reason', 'training_pet_sleeping')
            ->assertJsonPath('state.training.next_allowed_at', '2026-10-09T07:00:00+02:00')
            ->assertJsonPath('state.sleep.until', '2026-10-09T07:00:00+02:00');
        expect($pet->trainingSessions()->count())->toBe(0);

        // After midnight the same night: still asleep, same wake time.
        slAt('2026-10-08 23:00:00'); // 01:00 local on 09.10
        slStartTraining($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_pet_sleeping')
            ->assertJsonPath('next_allowed_at', '2026-10-09T07:00:00+02:00');

        // Awake: school works again.
        slAt('2026-10-09 05:00:00'); // 07:00 local
        slStartTraining($child)->assertOk()
            ->assertJsonPath('state.sleep.asleep', false)
            // The session just started is now the block (a second start waits for it).
            ->assertJsonPath('state.training.blocked_reason', 'training_session_active');
    });

    it('wakes at the family-local 07:00 in New York too', function () {
        [, $child] = slFamily('America/New_York');
        slAt('2026-10-09 10:00:00'); // 06:00 EDT

        slStartTraining($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_pet_sleeping')
            ->assertJsonPath('next_allowed_at', '2026-10-09T07:00:00-04:00');

        slAt('2026-10-09 11:00:00'); // 07:00 EDT
        slStartTraining($child)->assertOk();
    });

    it('tells a sleeping pet before a running session or the day ending (one reason, the earliest wake)', function () {
        [, $child] = slFamily();
        slAt('2026-10-08 21:59:00'); // 23:59 local: asleep AND the day is ending

        slState($child)
            ->assertJsonPath('training.blocked_reason', 'training_pet_sleeping')
            ->assertJsonPath('training.next_allowed_at', '2026-10-09T07:00:00+02:00');
    });

    it('explains other blocks too (day ending without quiet hours)', function () {
        [, $child, $pet] = slFamily();
        withoutQuietHours($pet);
        slAt('2026-10-08 21:59:00'); // 23:59 local

        slState($child)
            ->assertJsonPath('training.can_start', false)
            ->assertJsonPath('training.blocked_reason', 'training_day_ending')
            ->assertJsonPath('training.next_allowed_at', '2026-10-09T00:00:00+02:00');
    });
});

describe('play while the pet sleeps', function () {
    it('uses the same sleep definition: no play, 422 next_allowed_at = the wake time', function () {
        slAt('2026-10-07 05:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
        $pet = Pet::factory()->borderCollie()->purchased()->create([
            'user_id' => $child->id,
            'born_at' => now(), 'last_decay_at' => now(), 'last_step_reset_at' => now(),
            'arrival_age_months' => 4,
        ]);
        disableHygieneEvents($pet);
        Pet::whereKey($pet->id)->update(['play_scheduled_through' => '2999-12-31']);

        slAt('2026-10-08 10:00:00'); // 12:00 local — awake, play possible
        slState($child)->assertJsonPath('play.can_play', true)->assertJsonPath('sleep.asleep', false);

        slAt('2026-10-08 20:00:00'); // 22:00 local
        slState($child)->assertJsonPath('play.can_play', false)->assertJsonPath('sleep.asleep', true);

        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson('/api/child/pet/play', ['kind' => 'cuddle'])->assertStatus(422)
            ->assertJsonPath('reason', 'play_pet_sleeping')
            ->assertJsonPath('next_allowed_at', '2026-10-09T07:00:00+02:00');
    });
});
