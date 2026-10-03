<?php

use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\PetDecayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
|--------------------------------------------------------------------------
| Pet Decay & Game Loop Tests (M1-01, M1-02)
|--------------------------------------------------------------------------
|
| Decay is measured from pets.last_decay_at with fractional metrics.
| Spec (PRODUCT_SPEC §5): mutt hunger -8 %/h (0 % after 12.5 h), thirst
| -10 %/h; border collie hunger -12 %/h (8 h 20 min), thirst -15 %/h.
| Hygiene: interim gradual -1.5 %/h outside quiet hours.
*/

const DECAY_SIM_START = '2026-10-05 07:00:00';

function decayPet(array $attributes = [], ?User $child = null): Pet
{
    $child ??= User::factory()->child()->create();

    return Pet::factory()->create(array_merge(['user_id' => $child->id], $attributes));
}

function decayChildWithQuietHours(array $quietHours): User
{
    $parent = User::factory()->parent()->create();
    QuietHours::create(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));

    return User::factory()->child()->create(['parent_id' => $parent->id]);
}

/**
 * Put the pet's decay clock $minutes in the past (relative to now()).
 */
function rewindDecayClock(Pet $pet, int $minutes): Pet
{
    Pet::whereKey($pet->id)->update(['last_decay_at' => now()->subMinutes($minutes)]);

    return $pet->refresh();
}

/**
 * Run scheduler ticks at the given minute offsets (from the current test
 * time) and record, per metric, the first tick at which the precise value
 * reached 0 and the displayed values after every tick.
 *
 * @param  iterable<int>  $tickMinutes
 * @return array{zero: array<string, int|null>, display: array<int, array<string, int>>, changed: array<int, bool>}
 */
function simulateDecay(Pet $pet, iterable $tickMinutes, ?callable $beforeTick = null): array
{
    $service = app(PetDecayService::class);
    $start = now()->copy();
    $zero = array_fill_keys(Pet::METRICS, null);
    $display = [];
    $changed = [];

    foreach ($tickMinutes as $minute) {
        Carbon::setTestNow($start->copy()->addMinutes($minute));

        if ($beforeTick) {
            $beforeTick($minute);
        }

        $fresh = Pet::findOrFail($pet->id);
        $changed[$minute] = $service->processPetDecay($fresh);
        $fresh->refresh();

        foreach (Pet::METRICS as $metric) {
            if ($zero[$metric] === null && $fresh->{$metric} <= 0) {
                $zero[$metric] = $minute;
            }
        }
        $display[$minute] = $fresh->displayMetrics();
    }

    return ['zero' => $zero, 'display' => $display, 'changed' => $changed];
}

beforeEach(function () {
    Carbon::setTestNow(DECAY_SIM_START);
});

describe('PetDecayService - 24 h simulation against the spec', function () {
    it('matches spec rates for a mutt with minute-by-minute ticks', function () {
        seedBreedConfigs();
        $pet = decayPet(['breed_type' => 'mutt']);

        $result = simulateDecay($pet, range(1, 24 * 60));

        // Hunger 0 % after 12.5 h (750 min)
        expect($result['zero']['hunger_level'])->toBeGreaterThanOrEqual(12 * 60 + 20)
            ->toBeLessThanOrEqual(12 * 60 + 40);
        // Thirst 0 % after 10 h (600 min)
        expect($result['zero']['thirst_level'])->toBeGreaterThanOrEqual(595)->toBeLessThanOrEqual(605);
        // After 6 h: hunger 52, thirst 40, hygiene 91 (±1)
        expect($result['display'][360]['hunger_level'])->toBeGreaterThanOrEqual(51)->toBeLessThanOrEqual(53);
        expect($result['display'][360]['thirst_level'])->toBeGreaterThanOrEqual(39)->toBeLessThanOrEqual(41);
        expect($result['display'][360]['hygiene_level'])->toBeGreaterThanOrEqual(90)->toBeLessThanOrEqual(92);
        // After 24 h: hygiene 100 - 36 = 64; energy untouched (M1-04)
        expect($result['display'][1440]['hygiene_level'])->toBeGreaterThanOrEqual(63)->toBeLessThanOrEqual(65);
        expect($result['display'][1440]['energy_level'])->toBe(100);
    });

    it('matches spec rates for a border collie with minute-by-minute ticks', function () {
        seedBreedConfigs();
        $pet = decayPet(['breed_type' => 'border_collie']);

        $result = simulateDecay($pet, range(1, 24 * 60));

        // Hunger 0 % after 8 h 20 min (500 min)
        expect($result['zero']['hunger_level'])->toBeGreaterThanOrEqual(8 * 60 + 10)
            ->toBeLessThanOrEqual(8 * 60 + 30);
        // Thirst 0 % after 6 h 40 min (400 min)
        expect($result['zero']['thirst_level'])->toBeGreaterThanOrEqual(395)->toBeLessThanOrEqual(405);
        expect($result['display'][360]['hunger_level'])->toBeGreaterThanOrEqual(27)->toBeLessThanOrEqual(29);
        expect($result['display'][360]['hygiene_level'])->toBeGreaterThanOrEqual(90)->toBeLessThanOrEqual(92);
    });

    it('gives the same result with 5-minute ticks', function (string $breed) {
        seedBreedConfigs();
        $everyMinute = simulateDecay(decayPet(['breed_type' => $breed]), range(1, 24 * 60));

        Carbon::setTestNow(DECAY_SIM_START);
        $everyFive = simulateDecay(decayPet(['breed_type' => $breed]), range(5, 24 * 60, 5));

        foreach ([60, 180, 360, 600, 1440] as $minute) {
            expect($everyFive['display'][$minute])->toBe($everyMinute['display'][$minute]);
        }
        foreach (Pet::METRICS as $metric) {
            if ($everyMinute['zero'][$metric] !== null) {
                expect(abs($everyFive['zero'][$metric] - $everyMinute['zero'][$metric]))->toBeLessThanOrEqual(5);
            }
        }
    })->with(['mutt', 'border_collie']);

    it('catches up when the scheduler skips 3 hours', function () {
        seedBreedConfigs();
        $everyMinute = simulateDecay(decayPet(['breed_type' => 'mutt']), range(1, 24 * 60));

        Carbon::setTestNow(DECAY_SIM_START);
        // Ticks stop after 3 h and resume at 6 h.
        $withGap = simulateDecay(
            decayPet(['breed_type' => 'mutt']),
            array_merge(range(1, 180), range(360, 24 * 60)),
        );

        expect($withGap['display'][360])->toBe($everyMinute['display'][360]);
        expect($withGap['display'][1440])->toBe($everyMinute['display'][1440]);
        expect($withGap['zero']['hunger_level'])->toBe($everyMinute['zero']['hunger_level']);
    });

    it('does not lose decay to unrelated pet updates', function () {
        seedBreedConfigs();
        $pet = decayPet(['breed_type' => 'mutt']);

        // Every 3 minutes something else writes the pet (and resets updated_at).
        $result = simulateDecay($pet, range(1, 360), function (int $minute) use ($pet) {
            if ($minute % 3 === 0) {
                Pet::findOrFail($pet->id)->update(['current_video_url' => "https://cdn.test/{$minute}.mp4"]);
            }
        });

        expect($result['display'][360]['hunger_level'])->toBe(52);
        expect($result['display'][360]['thirst_level'])->toBe(40);
        expect($result['display'][360]['hygiene_level'])->toBe(91);
    });
});

describe('PetDecayService - decay clock', function () {
    it('starts the clock on the first tick when last_decay_at is null', function () {
        seedBreedConfigs();
        $pet = decayPet(['hunger_level' => 80]);
        Pet::whereKey($pet->id)->update(['last_decay_at' => null, 'updated_at' => now()->subHours(5)]);

        $changed = app(PetDecayService::class)->processPetDecay($pet->refresh());

        $pet->refresh();
        expect($changed)->toBeFalse();
        expect($pet->hunger_level)->toBe(80.0);
        expect($pet->last_decay_at->equalTo(now()))->toBeTrue();
    });

    it('sets last_decay_at when a pet is created', function () {
        $pet = decayPet();

        expect($pet->fresh()->last_decay_at->equalTo(now()))->toBeTrue();
    });

    it('measures elapsed time from last_decay_at, not updated_at', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['breed_type' => 'mutt']), 60);
        // updated_at is "now" — the old engine would have decayed ~1 minute.
        Pet::whereKey($pet->id)->update(['updated_at' => now()]);

        app(PetDecayService::class)->processPetDecay($pet->refresh());

        $pet->refresh();
        expect($pet->hunger_level)->toEqualWithDelta(92.0, 0.001);
        expect($pet->thirst_level)->toEqualWithDelta(90.0, 0.001);
        expect($pet->hygiene_level)->toEqualWithDelta(98.5, 0.001);
        expect($pet->last_decay_at->equalTo(now()))->toBeTrue();
    });

    it('decays hunger faster for Border Collie than Mutt', function () {
        seedBreedConfigs();
        $mutt = rewindDecayClock(decayPet(['breed_type' => 'mutt', 'hunger_level' => 50]), 120);
        $collie = rewindDecayClock(decayPet(['breed_type' => 'border_collie', 'hunger_level' => 50]), 120);

        $service = app(PetDecayService::class);
        $service->processPetDecay($mutt);
        $service->processPetDecay($collie);

        expect($mutt->fresh()->hunger_level)->toEqualWithDelta(34.0, 0.001);
        expect($collie->fresh()->hunger_level)->toEqualWithDelta(26.0, 0.001);
    });

    it('clamps decay at 0', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['hunger_level' => 1, 'thirst_level' => 1]), 120);

        app(PetDecayService::class)->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBe(0.0);
        expect($pet->thirst_level)->toBe(0.0);
    });

    it('stores metrics as double precision in PostgreSQL', function () {
        $types = collect(DB::select(
            "select column_name, data_type from information_schema.columns where table_name = 'pets' and column_name in ('hunger_level', 'thirst_level', 'energy_level', 'hygiene_level')"
        ))->pluck('data_type')->unique()->values()->all();

        expect($types)->toBe(['double precision']);
    })->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL only');
});

describe('PetDecayService - quiet hours', function () {
    it('reduces hunger and thirst decay to 10 % and pauses hygiene during quiet hours', function () {
        seedBreedConfigs();
        // DECAY_SIM_START is 07:00 → the whole 5 h run is quiet.
        $child = decayChildWithQuietHours(['school_start' => '06:00', 'school_end' => '13:00']);
        $pet = decayPet(['breed_type' => 'mutt'], $child);

        $result = simulateDecay($pet, range(1, 300));

        $pet->refresh();
        expect($pet->hunger_level)->toEqualWithDelta(100 - 8 * 5 * 0.1, 0.001);   // 96
        expect($pet->thirst_level)->toEqualWithDelta(100 - 10 * 5 * 0.1, 0.001);  // 95
        expect($pet->hygiene_level)->toBe(100.0);
        expect($pet->pet_state)->toBe(PetStateEnum::Sleeping);
        expect($result['display'][300]['hunger_level'])->toBe(96);
    });

    it('does not reduce decay when quiet hours are inactive', function () {
        seedBreedConfigs();
        $child = decayChildWithQuietHours(['school_start' => '00:00', 'school_end' => '23:59', 'is_active' => false]);
        $pet = rewindDecayClock(decayPet(['breed_type' => 'mutt'], $child), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->hunger_level)->toEqualWithDelta(92.0, 0.001);
    });

    it('splits a catch-up gap that crosses a quiet-hours boundary', function () {
        seedBreedConfigs();
        // 07:00 → 15:00 with quiet 08:00–13:00: 3 h normal + 5 h quiet.
        $child = decayChildWithQuietHours(['school_start' => '08:00', 'school_end' => '13:00']);
        $pet = decayPet(['breed_type' => 'mutt'], $child);

        simulateDecay($pet, [8 * 60]);

        $pet->refresh();
        expect($pet->hunger_level)->toEqualWithDelta(100 - 8 * (3 + 5 * 0.1), 0.001);   // 72
        expect($pet->thirst_level)->toEqualWithDelta(100 - 10 * (3 + 5 * 0.1), 0.001);  // 65
        expect($pet->hygiene_level)->toEqualWithDelta(100 - 1.5 * 3, 0.001);           // 95.5
        expect($pet->displayMetric('hygiene_level'))->toBe(96); // half up
    });
});

describe('PetDecayService - frozen states (M1-02)', function () {
    it('freezes metrics while hard-stopped and does not apply the frozen time on resume', function () {
        seedBreedConfigs();
        $pet = decayPet(['breed_type' => 'mutt']);

        simulateDecay($pet, range(1, 120));               // 2 h normal → hunger 84
        $pet->refresh()->update(['is_hard_stopped' => true]);

        $frozen = simulateDecay($pet, range(1, 300));      // 5 h hard stop

        $pet->refresh();
        expect($pet->hunger_level)->toEqualWithDelta(84.0, 0.001);
        expect($frozen['changed'])->not->toContain(true);
        expect($pet->last_decay_at->equalTo(now()))->toBeTrue();

        $pet->update(['is_hard_stopped' => false]);
        simulateDecay($pet, range(1, 60));                 // 1 h after resume

        expect($pet->fresh()->hunger_level)->toEqualWithDelta(76.0, 0.001);
    });

    it('does not burst after a hard stop even when no ticks ran during it', function () {
        seedBreedConfigs();
        $pet = decayPet(['breed_type' => 'mutt']);
        $pet->update(['is_hard_stopped' => true]);

        // Scheduler down for the whole hard stop; parent lifts it 5 h later.
        Carbon::setTestNow(now()->addHours(5));
        $pet->refresh()->update(['is_hard_stopped' => false]);

        simulateDecay($pet, [1]);

        expect($pet->fresh()->hunger_level)->toEqualWithDelta(100 - 8 / 60, 0.001);
    });

    it('freezes metrics while ill and resumes normally after the lockout', function () {
        seedBreedConfigs();
        $pet = decayPet([
            'breed_type' => 'mutt',
            'hunger_level' => 50,
            'illness_until' => now()->addHours(12),
            'pet_state' => 'sick',
        ]);

        // Last tick inside the lockout is at 11 h 55 min.
        $ill = simulateDecay($pet, range(5, 12 * 60 - 5, 5));

        $pet->refresh();
        expect($pet->hunger_level)->toBe(50.0);
        expect($ill['changed'])->not->toContain(true);

        // Next ticks at 12 h 00 … 13 h 00: only the time after illness_until decays.
        simulateDecay($pet, range(5, 65, 5));

        expect($pet->fresh()->hunger_level)->toEqualWithDelta(42.0, 0.001);
    });

    it('does not decay inactive pets', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['is_active' => false, 'hunger_level' => 50]), 120);

        $result = app(PetDecayService::class)->processPetDecay($pet);

        expect($result)->toBeFalse();
        expect($pet->fresh()->hunger_level)->toBe(50.0);
    });
});

describe('PetDecayService - broadcasts', function () {
    it('does not broadcast when no displayed value changed', function () {
        seedBreedConfigs();
        Event::fake([PetUpdated::class]);
        // State already matches what the engine computes (full metrics → playing).
        $pet = decayPet(['breed_type' => 'mutt', 'pet_state' => 'playing']);

        // 1 minute: hunger 99.87, thirst 99.83, hygiene 99.98 → all display 100.
        $result = simulateDecay($pet, [1]);

        expect($result['changed'][1])->toBeFalse();
        expect($pet->fresh()->hunger_level)->toBeLessThan(100.0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('broadcasts at most once per tick and only on displayed changes', function () {
        seedBreedConfigs();
        Event::fake([PetUpdated::class]);
        $pet = decayPet(['breed_type' => 'mutt']);

        $perTick = [];
        $service = app(PetDecayService::class);
        for ($minute = 1; $minute <= 120; $minute++) {
            Carbon::setTestNow(Carbon::parse(DECAY_SIM_START)->addMinutes($minute));
            $before = Event::dispatched(PetUpdated::class)->count();
            $changed = $service->processPetDecay(Pet::findOrFail($pet->id));
            $perTick[] = Event::dispatched(PetUpdated::class)->count() - $before;

            expect(end($perTick))->toBe($changed ? 1 : 0);
        }

        $total = array_sum($perTick);
        // 2 h: hunger 16 steps, thirst 20, hygiene 3 — far fewer than 120 ticks.
        expect($total)->toBeGreaterThan(0)->toBeLessThan(45);
        expect(max($perTick))->toBe(1);
    });
});

describe('PetDecayService - pet state determination', function () {
    it('sets pet state to hungry when hunger is low', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['hunger_level' => 25]), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->pet_state)->toBe(PetStateEnum::Hungry);
    });

    it('sets pet state to sick when hygiene is 0', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['hygiene_level' => 0]), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->pet_state)->toBe(PetStateEnum::Sick);
    });

    it('sets pet state to playing when metrics are high', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet([
            'hunger_level' => 80,
            'thirst_level' => 80,
            'energy_level' => 90,
        ]), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->pet_state)->toBe(PetStateEnum::Playing);
    });

    it('uses the precise value for thresholds (30.4 displays 30 but is not <= 30)', function () {
        seedBreedConfigs();
        $pet = decayPet(['hunger_level' => 30.45, 'pet_state' => 'idle']);
        Pet::whereKey($pet->id)->update(['last_decay_at' => now()->subSeconds(10)]);

        app(PetDecayService::class)->processPetDecay($pet->refresh());

        $pet->refresh();
        expect($pet->displayMetric('hunger_level'))->toBe(30);
        expect($pet->pet_state)->toBe(PetStateEnum::Idle);
    });
});

describe('PetDecayService - zero metric tracking', function () {
    it('tracks when hunger first hits 0', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet(['hunger_level' => 5]), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        $pet->refresh();
        expect($pet->hunger_level)->toBe(0.0);
        expect($pet->hunger_zero_since)->not->toBeNull();
    });

    it('does not mark a metric as zero while the precise value is above 0', function () {
        seedBreedConfigs();
        // 0.4 displays as 0 but is not zero yet.
        $pet = decayPet(['hunger_level' => 0.4]);
        Pet::whereKey($pet->id)->update(['last_decay_at' => now()->subSeconds(30)]);

        app(PetDecayService::class)->processPetDecay($pet->refresh());

        $pet->refresh();
        expect($pet->displayMetric('hunger_level'))->toBe(0);
        expect($pet->hunger_zero_since)->toBeNull();
    });

    it('clears zero-since tracking when metric recovers above 0', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet([
            'hunger_level' => 50,
            'hunger_zero_since' => now()->subHour(),
        ]), 1);

        app(PetDecayService::class)->processPetDecay($pet);

        expect($pet->fresh()->hunger_zero_since)->toBeNull();
    });
});

describe('PetDecayService - step count reset', function () {
    it('resets daily step count at midnight', function () {
        seedBreedConfigs();
        $pet = rewindDecayClock(decayPet([
            'daily_step_count' => 5000,
            'last_step_reset_at' => now()->subDay(),
        ]), 60);

        app(PetDecayService::class)->processPetDecay($pet);

        $pet->refresh();
        expect($pet->daily_step_count)->toBe(0);
        expect($pet->last_step_reset_at)->not->toBeNull();
    });
});

describe('Pet metric display contract', function () {
    it('rounds half up and clamps to 0–100', function () {
        expect(Pet::displayValue(52.5))->toBe(53);
        expect(Pet::displayValue(52.49))->toBe(52);
        expect(Pet::displayValue(0.4))->toBe(0);
        expect(Pet::displayValue(99.5))->toBe(100);
        expect(Pet::displayValue(-0.0))->toBe(0);
    });

    it('returns integer metrics from GET /api/parent/dashboard', function () {
        seedBreedConfigs();
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        decayPet([
            'hunger_level' => 52.5,
            'thirst_level' => 40.2,
            'energy_level' => 100,
            'hygiene_level' => 90.97,
        ], $child);

        actingAs($parent, 'sanctum');

        $pet = getJson('/api/parent/dashboard')->assertOk()->json('pet');

        expect($pet['hunger_level'])->toBe(53);
        expect($pet['thirst_level'])->toBe(40);
        expect($pet['energy_level'])->toBe(100);
        expect($pet['hygiene_level'])->toBe(91);
    });

    it('returns integer metrics from GET /api/user (serialized model)', function () {
        $child = User::factory()->child()->create();
        decayPet(['hunger_level' => 33.6, 'thirst_level' => 12.2], $child);

        actingAs($child, 'sanctum');

        $pet = getJson('/api/user')->assertOk()->json('pet');

        expect($pet['hunger_level'])->toBe(34);
        expect($pet['thirst_level'])->toBe(12);
    });

    it('broadcasts integer metrics in PetUpdated', function () {
        $pet = decayPet(['hunger_level' => 33.6]);

        $payload = (new PetUpdated($pet, 'metric_changed'))->broadcastWith();

        expect($payload['hunger_level'])->toBe(34);
        expect($payload['hygiene_level'])->toBe(100);
    });
});

describe('Quiet Hours API', function () {
    it('allows parent to set quiet hours', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
            'bedtime_start' => '22:00',
            'bedtime_end' => '06:00',
            'is_active' => true,
        ])
            ->assertStatus(200)
            ->assertJson([
                'message' => 'Quiet hours updated successfully.',
                'quiet_hours' => [
                    'school_start' => '08:00',
                    'school_end' => '13:00',
                    'bedtime_start' => '22:00',
                    'bedtime_end' => '06:00',
                ],
            ]);

        assertDatabaseHas('quiet_hours', [
            'parent_id' => $parent->id,
            'school_start' => '08:00',
            'school_end' => '13:00',
        ]);
    });

    it('allows parent to get their quiet hours', function () {
        $parent = User::factory()->parent()->create();
        QuietHours::create([
            'parent_id' => $parent->id,
            'school_start' => '08:00',
            'school_end' => '13:00',
            'bedtime_start' => '22:00',
            'bedtime_end' => '06:00',
        ]);

        actingAs($parent, 'sanctum');

        getJson('/api/parent/quiet-hours')
            ->assertStatus(200)
            ->assertJsonPath('quiet_hours.school_start', '08:00');
    });

    it('rejects quiet hours management from child profile', function () {
        $child = User::factory()->child()->create();

        actingAs($child, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
        ])->assertStatus(403);
    });

    it('validates time format', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        putJson('/api/parent/quiet-hours', [
            'school_start' => 'invalid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_start']);
    });

    it('updates existing quiet hours instead of creating duplicates', function () {
        $parent = User::factory()->parent()->create();

        actingAs($parent, 'sanctum');

        // First update
        putJson('/api/parent/quiet-hours', [
            'school_start' => '08:00',
            'school_end' => '13:00',
        ])->assertStatus(200);

        // Second update (should update, not create new)
        putJson('/api/parent/quiet-hours', [
            'school_start' => '09:00',
            'school_end' => '14:00',
        ])->assertStatus(200);

        expect(QuietHours::where('parent_id', $parent->id)->count())->toBe(1);
        expect(QuietHours::where('parent_id', $parent->id)->first()->school_start)->toBe('09:00:00');
    });
});
