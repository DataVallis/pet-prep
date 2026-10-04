<?php

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedConfig;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\User;
use App\Services\PetActivityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Child API (M1-07, PRODUCT_SPEC §3, §5–§8)
|--------------------------------------------------------------------------
|
| GET /api/child/pet, POST /api/child/pet/{feed,water,clean,steps},
| POST /api/child/contract. The family is in Europe/Ljubljana unless a test
| says otherwise (CEST = UTC+2 until 2026-10-25 01:00 UTC, then CET = UTC+1).
| Default breed numbers (mutt): feed windows 06–10 and 17–21 local, water
| 3× / day with ≥ 180 min between refills.
*/

const CP_PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A paired child with a pet, acting as that child. Time is set first so the
 * pet is born "now" (decay clock, birth-day step grace).
 *
 * @return array{0: User, 1: Pet}
 */
function cpChild(string $nowUtc = '2026-10-04 06:00:00', array $pet = [], string $timezone = 'Europe/Ljubljana'): array
{
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

    $parent = User::factory()->parent()->create(['timezone' => $timezone]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    $created = disableHygieneEvents(Pet::factory()->create(array_merge(['user_id' => $child->id], $pet)));

    Sanctum::actingAs($child);

    return [$child, $created];
}

function cpAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

function cpRows(Pet $pet, ActivityType $type)
{
    return ActivityLog::where('pet_id', $pet->id)->where('activity_type', $type->value)->orderBy('id')->get();
}

dataset('child endpoints', [
    'state' => ['GET', '/api/child/pet', []],
    'feed' => ['POST', '/api/child/pet/feed', []],
    'water' => ['POST', '/api/child/pet/water', []],
    'clean' => ['POST', '/api/child/pet/clean', []],
    'steps' => ['POST', '/api/child/pet/steps', ['steps_today' => 100, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00']],
    'contract' => ['POST', '/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20']],
]);

dataset('child actions', [
    'feed' => ['/api/child/pet/feed', []],
    'water' => ['/api/child/pet/water', []],
    'clean' => ['/api/child/pet/clean', []],
    'steps' => ['/api/child/pet/steps', ['steps_today' => 100, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00']],
    'contract' => ['/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20']],
]);

// ──────────────────────────────────────────────────────────────
//  Authentication and authorization
// ──────────────────────────────────────────────────────────────

describe('auth', function () {
    it('returns 401 for guests', function (string $method, string $uri, array $body) {
        $this->json($method, $uri, $body)->assertStatus(401);
    })->with('child endpoints');

    it('returns 403 for a parent', function (string $method, string $uri, array $body) {
        [, $pet] = cpChild();
        $parent = $pet->user->parent;
        Sanctum::actingAs($parent);

        $this->json($method, $uri, $body)->assertStatus(403);
        expect(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
    })->with('child endpoints');

    it('returns 404 no_pet for a child that is not paired yet', function (string $method, string $uri, array $body) {
        Carbon::setTestNow(Carbon::parse('2026-10-04 06:00:00', 'UTC'));
        Sanctum::actingAs(User::factory()->child()->create());

        $this->json($method, $uri, $body)->assertStatus(404)->assertJsonPath('reason', 'no_pet');
    })->with('child endpoints');

    it('throttles every POST action with the child-actions limiter', function () {
        foreach (['api/child/pet/feed', 'api/child/pet/water', 'api/child/pet/clean', 'api/child/pet/steps', 'api/child/contract'] as $uri) {
            $route = Route::getRoutes()->match(request()->create('/'.$uri, 'POST'));
            expect($route->gatherMiddleware())->toContain('throttle:child-actions', 'auth:sanctum');
        }

        $get = Route::getRoutes()->match(request()->create('/api/child/pet', 'GET'));
        expect($get->gatherMiddleware())->toContain('throttle:api', 'auth:sanctum');
    });
});

// ──────────────────────────────────────────────────────────────
//  GET /api/child/pet
// ──────────────────────────────────────────────────────────────

describe('GET /api/child/pet', function () {
    it('returns the full state with displayed metrics and family-local windows', function () {
        // 06:00 UTC = 08:00 CEST, inside the morning window.
        [, $pet] = cpChild(pet: ['hunger_level' => 55.5, 'thirst_level' => 30.4, 'hygiene_level' => 100]);

        $response = $this->getJson('/api/child/pet')->assertOk();

        $response->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.hunger_level', 56)
            ->assertJsonPath('pet.thirst_level', 30)
            ->assertJsonPath('pet.breed_type', 'mutt')
            ->assertJsonPath('pet.is_hard_stopped', false)
            ->assertJsonPath('pet.is_ill', false)
            ->assertJsonPath('pet.is_game_over', false)
            ->assertJsonPath('pet.needs_cleaning', false)
            ->assertJsonPath('lock.is_locked', false)
            ->assertJsonPath('lock.reason', null)
            ->assertJsonPath('timezone', 'Europe/Ljubljana')
            ->assertJsonPath('server_time', '2026-10-04T08:00:00+02:00')
            ->assertJsonPath('feeding.windows', [['start' => '06:00', 'end' => '10:00'], ['start' => '17:00', 'end' => '21:00']])
            ->assertJsonPath('feeding.current_window.start', '2026-10-04T06:00:00+02:00')
            ->assertJsonPath('feeding.current_window.end', '2026-10-04T10:00:00+02:00')
            ->assertJsonPath('feeding.can_feed', true)
            ->assertJsonPath('feeding.next_feed_window.start', '2026-10-04T06:00:00+02:00')
            ->assertJsonPath('water.times_per_day', 3)
            ->assertJsonPath('water.min_gap_minutes', 180)
            ->assertJsonPath('water.used_today', 0)
            ->assertJsonPath('water.remaining_today', 3)
            ->assertJsonPath('water.can_water', true)
            ->assertJsonPath('water.next_allowed_at', null)
            ->assertJsonPath('steps.goal', 4000)
            ->assertJsonPath('contract.signed', false);
    });

    it('points to the evening window between windows and to tomorrow morning at night', function () {
        cpChild('2026-10-04 10:00:00'); // 12:00 local

        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('feeding.current_window', null)
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('feeding.next_feed_window.start', '2026-10-04T17:00:00+02:00')
            ->assertJsonPath('feeding.next_feed_window.end', '2026-10-04T21:00:00+02:00');

        cpAt('2026-10-04 20:00:00'); // 22:00 local
        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('feeding.next_feed_window.start', '2026-10-05T06:00:00+02:00');
    });

    it('shows the next window after the current one was used', function () {
        cpChild();
        $this->postJson('/api/child/pet/feed')->assertOk();

        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('feeding.fed_in_current_window', true)
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('feeding.last_fed_at', '2026-10-04T08:00:00+02:00')
            ->assertJsonPath('feeding.next_feed_window.start', '2026-10-04T17:00:00+02:00');
    });

    it('reports the lock for each reason', function (array $state, string $reason, bool $hasUntil) {
        [, $pet] = cpChild(pet: $state);

        $response = $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('lock.is_locked', true)
            ->assertJsonPath('lock.reason', $reason)
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('water.can_water', false);

        expect($response->json('lock.until') !== null)->toBe($hasUntil);
    })->with([
        'hard stop' => [['is_hard_stopped' => true], 'hard_stopped', false],
        'ill' => [['illness_until' => '2026-10-04 12:00:00', 'pet_state' => 'sick'], 'ill', true],
        'game over (inactive pet is still shown)' => [['is_game_over' => true, 'is_active' => false], 'game_over', false],
        'inactive' => [['is_active' => false], 'inactive', false],
    ]);

    it('gives the illness end in family time', function () {
        cpChild(pet: ['illness_until' => '2026-10-04 12:00:00']);

        $this->getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('pet.illness_until', '2026-10-04T14:00:00+02:00')
            ->assertJsonPath('lock.until', '2026-10-04T14:00:00+02:00');
    });

    it('does not write anything', function () {
        [, $pet] = cpChild();
        $before = Pet::findOrFail($pet->id)->getAttributes();
        cpAt('2026-10-04 07:00:00');

        $this->getJson('/api/child/pet')->assertOk();

        expect(Pet::findOrFail($pet->id)->getAttributes())->toEqual($before);
    });
});

// ──────────────────────────────────────────────────────────────
//  POST /api/child/pet/feed
// ──────────────────────────────────────────────────────────────

describe('POST /api/child/pet/feed', function () {
    it('feeds inside the window: hunger 100, one activity row, one broadcast', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild(pet: ['hunger_level' => 40, 'hunger_zero_since' => null, 'pet_state' => 'hungry']);

        $this->postJson('/api/child/pet/feed')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hunger_level', 100)
            ->assertJsonPath('state.feeding.fed_in_current_window', true);

        $fresh = Pet::findOrFail($pet->id);
        expect($fresh->hunger_level)->toBe(100.0)
            ->and($fresh->pet_state->value)->not->toBe('hungry');

        $rows = cpRows($pet, ActivityType::FedPet);
        expect($rows)->toHaveCount(1)->and($rows[0]->value)->toBe(40);

        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'fed_pet' && $e->pet->id === $pet->id);
    });

    it('refuses outside the windows with the next window (422)', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild('2026-10-04 10:00:00', ['hunger_level' => 40]); // 12:00 local

        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('status', 'refused')
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-04T17:00:00+02:00')
            ->assertJsonPath('state.feeding.next_feed_window.start', '2026-10-04T17:00:00+02:00');

        expect(Pet::findOrFail($pet->id)->hunger_level)->toBe(40.0)
            ->and(cpRows($pet, ActivityType::FedPet))->toHaveCount(0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('allows one feed per window and two per day', function () {
        [, $pet] = cpChild('2026-10-04 04:30:00'); // 06:30 local

        $this->postJson('/api/child/pet/feed')->assertOk();

        cpAt('2026-10-04 07:45:00'); // 09:45, same window
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'already_fed_this_window')
            ->assertJsonPath('next_allowed_at', '2026-10-04T17:00:00+02:00');

        cpAt('2026-10-04 15:00:00'); // 17:00, evening window opens
        $this->postJson('/api/child/pet/feed')->assertOk();

        cpAt('2026-10-04 18:59:59'); // 20:59:59
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'already_fed_this_window')
            ->assertJsonPath('next_allowed_at', '2026-10-05T06:00:00+02:00');

        expect(cpRows($pet, ActivityType::FedPet))->toHaveCount(2);
    });

    it('treats windows as [start, end)', function () {
        cpChild('2026-10-04 03:59:59'); // 05:59:59
        $this->postJson('/api/child/pet/feed')->assertStatus(422)->assertJsonPath('reason', 'outside_feed_window');

        cpAt('2026-10-04 04:00:00'); // 06:00:00
        $this->postJson('/api/child/pet/feed')->assertOk();

        cpAt('2026-10-04 08:00:00'); // 10:00:00 — window closed
        $this->getJson('/api/child/pet')->assertJsonPath('feeding.current_window', null);
    });

    it('refuses while the mess is not cleaned', function () {
        [, $pet] = cpChild(pet: ['hygiene_level' => 0, 'hunger_level' => 40]);

        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'needs_cleaning')
            ->assertJsonPath('next_allowed_at', null)
            ->assertJsonPath('state.pet.needs_cleaning', true)
            ->assertJsonPath('state.feeding.can_feed', false);

        expect(Pet::findOrFail($pet->id)->hunger_level)->toBe(40.0);
    });

    it('applies decay owed since the last tick to the old value before feeding', function () {
        // Last tick an hour ago (scheduler late): 50 % − 8 %/h = 42 % shown before feeding.
        [, $pet] = cpChild(pet: ['hunger_level' => 50]);
        Pet::whereKey($pet->id)->update(['last_decay_at' => Carbon::parse('2026-10-04 05:00:00', 'UTC')]);

        $this->postJson('/api/child/pet/feed')->assertOk();

        $fresh = Pet::findOrFail($pet->id);
        expect($fresh->hunger_level)->toBe(100.0)
            ->and($fresh->last_decay_at->equalTo(Carbon::parse('2026-10-04 06:00:00', 'UTC')))->toBeTrue()
            ->and((float) $fresh->thirst_level)->toEqualWithDelta(90.0, 0.0001)
            ->and(cpRows($pet, ActivityType::FedPet)[0]->value)->toBe(42);
    });

    it('broadcasts metric_changed once when a refused feed caught up visible decay', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild('2026-10-04 10:00:00', ['hunger_level' => 50]); // 12:00 local
        Pet::whereKey($pet->id)->update(['last_decay_at' => Carbon::parse('2026-10-04 09:00:00', 'UTC')]);

        $this->postJson('/api/child/pet/feed')->assertStatus(422);

        expect(Pet::findOrFail($pet->id)->displayMetric('hunger_level'))->toBe(42);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'metric_changed');
    });

    it('uses the family timezone, not UTC', function () {
        // 12:00 UTC = 08:00 in New York (EDT) but 14:00 in Ljubljana.
        cpChild('2026-10-04 12:00:00', timezone: 'America/New_York');
        $this->postJson('/api/child/pet/feed')->assertOk();

        cpChild('2026-10-04 12:00:00');
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2026-10-04T17:00:00+02:00');
    });

    it('follows the local clock on the fall-back day (2026-10-25)', function () {
        // After 01:00 UTC Ljubljana is UTC+1: 06:00 local = 05:00 UTC (04:00 UTC the day before).
        cpChild('2026-10-25 04:30:00'); // 05:30 CET
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-25T06:00:00+01:00');

        cpAt('2026-10-25 05:00:00'); // 06:00 CET
        $this->postJson('/api/child/pet/feed')->assertOk()
            ->assertJsonPath('state.feeding.current_window.end', '2026-10-25T10:00:00+01:00');
    });

    it('follows the local clock on the spring-forward day (2027-03-28)', function () {
        cpChild('2027-03-28 03:59:00'); // 05:59 CEST
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('next_allowed_at', '2027-03-28T06:00:00+02:00');

        cpAt('2027-03-28 04:00:00'); // 06:00 CEST
        $this->postJson('/api/child/pet/feed')->assertOk();
    });

    it('reads windows from breed_configs, including one over midnight', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['feed_windows' => json_encode([['22:00', '02:00']])]);
        cpChild('2026-10-04 23:00:00'); // 01:00 local on 2026-10-05

        $this->postJson('/api/child/pet/feed')->assertOk()
            ->assertJsonPath('state.feeding.current_window.start', '2026-10-04T22:00:00+02:00')
            ->assertJsonPath('state.feeding.current_window.end', '2026-10-05T02:00:00+02:00')
            ->assertJsonPath('state.feeding.next_feed_window.start', '2026-10-05T22:00:00+02:00');

        cpAt('2026-10-05 00:30:00'); // 02:30 local
        $this->postJson('/api/child/pet/feed')->assertStatus(422)
            ->assertJsonPath('reason', 'outside_feed_window')
            ->assertJsonPath('next_allowed_at', '2026-10-05T22:00:00+02:00');
    });
});

// ──────────────────────────────────────────────────────────────
//  POST /api/child/pet/water
// ──────────────────────────────────────────────────────────────

describe('POST /api/child/pet/water', function () {
    it('refills: thirst 100, one activity row, one broadcast', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild(pet: ['thirst_level' => 30]);

        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.thirst_level', 100)
            ->assertJsonPath('state.water.used_today', 1)
            ->assertJsonPath('state.water.remaining_today', 2)
            ->assertJsonPath('state.water.can_water', false)
            ->assertJsonPath('state.water.next_allowed_at', '2026-10-04T11:00:00+02:00');

        $rows = cpRows($pet, ActivityType::WateredPet);
        expect(Pet::findOrFail($pet->id)->thirst_level)->toBe(100.0)
            ->and($rows)->toHaveCount(1)
            ->and($rows[0]->value)->toBe(30);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'watered_pet');
    });

    it('enforces the minimum gap with the next allowed time', function () {
        [, $pet] = cpChild('2026-10-04 05:00:00'); // 07:00 local
        $this->postJson('/api/child/pet/water')->assertOk();

        cpAt('2026-10-04 07:59:00'); // 09:59 local
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-04T10:00:00+02:00');

        cpAt('2026-10-04 08:00:00'); // exactly 180 min later
        $this->postJson('/api/child/pet/water')->assertOk();

        expect(cpRows($pet, ActivityType::WateredPet))->toHaveCount(2);
    });

    it('allows water_times_per_day refills per local day, then points to local midnight', function () {
        [, $pet] = cpChild('2026-10-04 05:00:00'); // 07:00
        $this->postJson('/api/child/pet/water')->assertOk();
        cpAt('2026-10-04 08:00:00'); // 10:00
        $this->postJson('/api/child/pet/water')->assertOk();
        cpAt('2026-10-04 11:00:00'); // 13:00
        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('state.water.remaining_today', 0)
            ->assertJsonPath('state.water.next_allowed_at', '2026-10-05T00:00:00+02:00');

        cpAt('2026-10-04 19:00:00'); // 21:00
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_daily_limit')
            ->assertJsonPath('next_allowed_at', '2026-10-05T00:00:00+02:00');

        cpAt('2026-10-04 22:00:00'); // 00:00 local, new day
        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('state.water.used_today', 1);

        expect(cpRows($pet, ActivityType::WateredPet))->toHaveCount(4);
    });

    it('reads the limits from breed_configs', function () {
        BreedConfig::where('breed_slug', 'mutt')->update(['water_times_per_day' => 1, 'water_min_gap_minutes' => 30]);
        cpChild('2026-10-04 05:00:00');

        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('state.water.times_per_day', 1);

        cpAt('2026-10-04 06:00:00');
        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'water_daily_limit');
    });

    it('keeps the gap across midnight', function () {
        cpChild('2026-10-04 21:30:00'); // 23:30 local
        $this->postJson('/api/child/pet/water')->assertOk();

        cpAt('2026-10-04 22:30:00'); // 00:30 local, new day
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-05T02:30:00+02:00')
            ->assertJsonPath('state.water.used_today', 0);
    });

    it('counts real minutes and a 25-hour local day on the fall-back day (2026-10-25)', function () {
        // Local day 2026-10-25 = 2026-10-24 22:00 UTC … 2026-10-25 23:00 UTC.
        [, $pet] = cpChild('2026-10-24 22:30:00'); // 00:30 CEST
        $this->postJson('/api/child/pet/water')->assertOk();

        // 01:29 UTC = 02:29 CET: wall clock +2 h, but only 179 real minutes.
        cpAt('2026-10-25 01:29:00');
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_too_soon')
            ->assertJsonPath('next_allowed_at', '2026-10-25T02:30:00+01:00');

        cpAt('2026-10-25 01:30:00'); // 180 real minutes
        $this->postJson('/api/child/pet/water')->assertOk();

        cpAt('2026-10-25 04:30:00'); // 05:30 CET
        $this->postJson('/api/child/pet/water')->assertOk();

        cpAt('2026-10-25 22:30:00'); // 23:30 CET, still the 25th
        $this->postJson('/api/child/pet/water')->assertStatus(422)
            ->assertJsonPath('reason', 'water_daily_limit')
            ->assertJsonPath('next_allowed_at', '2026-10-26T00:00:00+01:00');

        cpAt('2026-10-25 23:00:00'); // 00:00 CET on the 26th
        $this->postJson('/api/child/pet/water')->assertOk();

        expect(cpRows($pet, ActivityType::WateredPet))->toHaveCount(4);
    });

    it('uses a 23-hour local day on the spring-forward day (2027-03-28)', function () {
        // Local day 2027-03-28 = 2027-03-27 23:00 UTC … 2027-03-28 22:00 UTC.
        cpChild('2027-03-28 05:00:00'); // 07:00 CEST
        $this->postJson('/api/child/pet/water')->assertOk();
        cpAt('2027-03-28 08:00:00');
        $this->postJson('/api/child/pet/water')->assertOk();
        cpAt('2027-03-28 11:00:00');
        $this->postJson('/api/child/pet/water')->assertOk()
            ->assertJsonPath('state.water.next_allowed_at', '2027-03-29T00:00:00+02:00');

        cpAt('2027-03-28 21:59:00'); // 23:59 CEST
        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'water_daily_limit');

        cpAt('2027-03-28 22:00:00'); // 00:00 CEST on the 29th
        $this->postJson('/api/child/pet/water')->assertOk();
    });

    it('refuses while the mess is not cleaned', function () {
        cpChild(pet: ['hygiene_level' => 0]);

        $this->postJson('/api/child/pet/water')->assertStatus(422)->assertJsonPath('reason', 'needs_cleaning');
    });
});

// ──────────────────────────────────────────────────────────────
//  POST /api/child/pet/clean
// ──────────────────────────────────────────────────────────────

describe('POST /api/child/pet/clean', function () {
    it('cleans: hygiene 100, state no longer sick, one row, one broadcast', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild(pet: ['hygiene_level' => 0, 'hygiene_zero_since' => '2026-10-04 05:00:00', 'pet_state' => 'sick']);

        $this->postJson('/api/child/pet/clean')->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.hygiene_level', 100)
            ->assertJsonPath('state.pet.needs_cleaning', false);

        $fresh = Pet::findOrFail($pet->id);
        expect($fresh->hygiene_level)->toBe(100.0)
            ->and($fresh->hygiene_zero_since)->toBeNull()
            ->and($fresh->pet_state->value)->not->toBe('sick')
            ->and(cpRows($pet, ActivityType::CleanedPoop))->toHaveCount(1);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'cleaned_poop');
    });

    it('is idempotent when already clean', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild();

        $this->postJson('/api/child/pet/clean')->assertOk()->assertJsonPath('status', 'unchanged');

        expect(cpRows($pet, ActivityType::CleanedPoop))->toHaveCount(0);
        Event::assertNotDispatched(PetUpdated::class);
    });
});

// ──────────────────────────────────────────────────────────────
//  POST /api/child/pet/steps
// ──────────────────────────────────────────────────────────────

describe('POST /api/child/pet/steps', function () {
    it('accepts a plausible sync and reports energy', function () {
        // 12:00 local; the pet lived through a midnight (no birth-day grace).
        [, $pet] = cpChild('2026-10-04 10:00:00', ['energy_level' => 0, 'last_step_reset_at' => '2026-10-03 22:00:00']);

        $this->postJson('/api/child/pet/steps', [
            'steps_today' => 2000, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T12:00:00+02:00',
        ])->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('accepted_steps', 2000)
            ->assertJsonPath('steps_today', 2000)
            ->assertJsonPath('energy_level', 50)
            ->assertJsonPath('state.steps.steps_today', 2000)
            ->assertJsonPath('state.steps.goal', 4000);
    });

    it('caps an implausible increment (anti-cheat) and logs the goal once', function () {
        [, $pet] = cpChild('2026-10-04 10:00:00', [
            'energy_level' => 0, 'daily_step_count' => 3000,
            'last_step_reset_at' => '2026-10-03 22:00:00', 'last_step_sync_at' => '2026-10-04 09:55:00',
        ]);

        // +5,000 in 5 minutes: only 1,000 plausible → 4,000 = goal.
        $this->postJson('/api/child/pet/steps', [
            'steps_today' => 8000, 'source' => 'health_connect', 'recorded_at' => '2026-10-04T10:00:00Z',
        ])->assertOk()
            ->assertJsonPath('status', 'capped')
            ->assertJsonPath('accepted_steps', 1000)
            ->assertJsonPath('steps_today', 4000)
            ->assertJsonPath('energy_level', 100);

        expect(cpRows($pet, ActivityType::WalkedPet))->toHaveCount(1);
    });

    it('ignores a sync from an earlier local day', function () {
        cpChild('2026-10-04 10:00:00', ['last_step_reset_at' => '2026-10-03 22:00:00']);

        $this->postJson('/api/child/pet/steps', [
            'steps_today' => 500, 'source' => 'pedometer', 'recorded_at' => '2026-10-03T23:30:00+02:00',
        ])->assertOk()->assertJsonPath('status', 'stale');
    });

    it('validates the body', function (array $body, string $field) {
        cpChild();

        $this->postJson('/api/child/pet/steps', $body)->assertStatus(422)->assertJsonValidationErrors($field);
    })->with([
        'missing steps' => [['source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'steps_today'],
        'negative steps' => [['steps_today' => -1, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'steps_today'],
        'too many steps' => [['steps_today' => 100001, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'steps_today'],
        'not an integer' => [['steps_today' => 'lots', 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'steps_today'],
        'unknown source' => [['steps_today' => 10, 'source' => 'manual', 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'source'],
        'missing source' => [['steps_today' => 10, 'recorded_at' => '2026-10-04T08:00:00+02:00'], 'source'],
        'bad date' => [['steps_today' => 10, 'source' => 'healthkit', 'recorded_at' => 'yesterday-ish'], 'recorded_at'],
        'missing date' => [['steps_today' => 10, 'source' => 'healthkit'], 'recorded_at'],
    ]);
});

// ──────────────────────────────────────────────────────────────
//  POST /api/child/contract
// ──────────────────────────────────────────────────────────────

describe('POST /api/child/contract', function () {
    it('stores an SVG path signature once with server time', function () {
        Event::fake([PetUpdated::class]);
        [$child, $pet] = cpChild();

        $this->postJson('/api/child/contract', [
            'signature_format' => 'svg_path',
            'signature' => '  M10,10 L20.5,-3 C1 2 3 4 5 6 Z  ',
        ])->assertStatus(201)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.contract.signed', true)
            ->assertJsonPath('state.contract.signed_at', '2026-10-04T08:00:00+02:00')
            ->assertJsonMissingPath('state.contract.signature');

        $contract = PetContract::where('pet_id', $pet->id)->sole();
        expect($contract->signature)->toBe('M10,10 L20.5,-3 C1 2 3 4 5 6 Z')
            ->and($contract->signature_format)->toBe('svg_path')
            ->and($contract->user_id)->toBe($child->id)
            ->and($contract->signed_at->equalTo(Carbon::parse('2026-10-04 06:00:00', 'UTC')))->toBeTrue()
            ->and(cpRows($pet, ActivityType::SignedContract))->toHaveCount(1);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'signed_contract');

        $this->getJson('/api/child/pet')->assertJsonPath('contract.signed', true);
    });

    it('stores a PNG signature without the data-URI prefix', function () {
        [, $pet] = cpChild();

        $this->postJson('/api/child/contract', [
            'signature_format' => 'png',
            'signature' => 'data:image/png;base64,'.CP_PNG_1X1,
        ])->assertStatus(201);

        expect(PetContract::where('pet_id', $pet->id)->sole()->signature)->toBe(CP_PNG_1X1);
    });

    it('rejects a second signature with 409 and keeps the first', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild();
        $this->postJson('/api/child/contract', ['signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2'])->assertStatus(201);

        cpAt('2026-10-04 07:00:00');
        $this->postJson('/api/child/contract', ['signature_format' => 'png', 'signature' => CP_PNG_1X1])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'contract_already_signed')
            ->assertJsonPath('state.contract.signed_at', '2026-10-04T08:00:00+02:00');

        $contract = PetContract::where('pet_id', $pet->id)->sole();
        expect($contract->signature)->toBe('M1 1 L2 2')
            ->and(cpRows($pet, ActivityType::SignedContract))->toHaveCount(1);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('validates the signature', function (array $body) {
        cpChild();

        $this->postJson('/api/child/contract', $body)->assertStatus(422)->assertJsonStructure(['errors']);
        expect(PetContract::count())->toBe(0);
    })->with([
        'missing format' => [['signature' => 'M1 1']],
        'unknown format' => [['signature_format' => 'jpeg', 'signature' => 'M1 1']],
        'missing signature' => [['signature_format' => 'svg_path']],
        'svg with markup' => [['signature_format' => 'svg_path', 'signature' => 'M1 1 <script>alert(1)</script>']],
        'svg without a move first' => [['signature_format' => 'svg_path', 'signature' => 'L1 1 L2 2']],
        'svg too long' => [['signature_format' => 'svg_path', 'signature' => 'M'.str_repeat(' 1', 10000)]],
        'png not base64' => [['signature_format' => 'png', 'signature' => '***not base64***']],
        'png not a png' => [['signature_format' => 'png', 'signature' => base64_encode('GIF89a this is a gif')]],
        'png too large' => [['signature_format' => 'png', 'signature' => base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 102400))]],
    ]);
});

// ──────────────────────────────────────────────────────────────
//  423 Locked
// ──────────────────────────────────────────────────────────────

describe('423 Locked', function () {
    it('refuses every action with the reason and changes nothing', function (string $uri, array $body, array $state, string $reason) {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild(pet: array_merge(['hunger_level' => 40, 'thirst_level' => 40, 'hygiene_level' => 0], $state));
        $before = Pet::findOrFail($pet->id)->only(['hunger_level', 'thirst_level', 'hygiene_level', 'daily_step_count']);

        $this->postJson($uri, $body)->assertStatus(423)
            ->assertJsonPath('status', 'locked')
            ->assertJsonPath('reason', $reason)
            ->assertJsonPath('state.lock.reason', $reason);

        expect(Pet::findOrFail($pet->id)->only(['hunger_level', 'thirst_level', 'hygiene_level', 'daily_step_count']))->toEqual($before)
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(PetContract::count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    })->with('child actions')->with([
        'hard stop' => [['is_hard_stopped' => true], 'hard_stopped'],
        'ill' => [['illness_until' => '2026-10-04 12:00:00'], 'ill'],
        'game over' => [['is_game_over' => true, 'is_active' => false], 'game_over'],
    ]);

    it('gives locked_until for an illness', function () {
        cpChild(pet: ['illness_until' => '2026-10-04 12:00:00']);

        $this->postJson('/api/child/pet/feed')->assertStatus(423)
            ->assertJsonPath('locked_until', '2026-10-04T14:00:00+02:00');
    });

    it('reports a hard stop before an illness', function () {
        cpChild(pet: ['illness_until' => '2026-10-04 12:00:00', 'is_hard_stopped' => true]);

        $this->postJson('/api/child/pet/water')->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
    });

    it('lets the child act again once the illness is over (fresh start)', function () {
        [, $pet] = cpChild(pet: ['illness_until' => '2026-10-04 05:00:00', 'hygiene_level' => 0, 'hunger_level' => 40]);
        Pet::whereKey($pet->id)->update(['illness_until' => Carbon::parse('2026-10-04 05:30:00', 'UTC')]);

        $this->postJson('/api/child/pet/feed')->assertOk();

        $fresh = Pet::findOrFail($pet->id);
        expect($fresh->illness_until)->toBeNull()
            ->and($fresh->hygiene_level)->toBe(100.0)
            ->and($fresh->hunger_level)->toBe(100.0);
    });
});

// ──────────────────────────────────────────────────────────────
//  Concurrency / transactions
// ──────────────────────────────────────────────────────────────

describe('broadcast after commit', function () {
    it('broadcasts only once the surrounding transaction commits', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cpChild(pet: ['hunger_level' => 40]);

        DB::transaction(function () use ($pet) {
            app(PetActivityService::class)->feed(Pet::findOrFail($pet->id));
            app(PetActivityService::class)->water(Pet::findOrFail($pet->id));

            Event::assertNotDispatched(PetUpdated::class);
        });

        Event::assertDispatchedTimes(PetUpdated::class, 2);
    });

    it('acts on the locked row, not on a stale model', function () {
        [, $pet] = cpChild(pet: ['hunger_level' => 40]);
        $stale = Pet::findOrFail($pet->id);

        // A hard stop lands between loading the model and the action.
        Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);

        $result = app(PetActivityService::class)->feed($stale);

        expect($result->status)->toBe('locked')
            ->and($result->lockReason?->value)->toBe('hard_stopped')
            ->and(Pet::findOrFail($pet->id)->hunger_level)->toBe(40.0)
            ->and($stale->is_hard_stopped)->toBeTrue();
    });
});
