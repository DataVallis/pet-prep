<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventStatus;
use App\Enums\PetLockReason;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetDailyWalk;
use App\Models\PetHygieneEvent;
use App\Models\QuietHours;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PairingService;
use App\Services\PetActivityService;
use App\Services\PetDecayService;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Contract before birth (M1-07b, David 2026-10-04, PRODUCT_SPEC §3)
|--------------------------------------------------------------------------
|
| "PIN → pogodba → pes se rodi": pairing creates the pet unborn (born_at
| null). Until the child signs, the game loop ignores it and every child
| action except the contract returns 423 contract_required. Signing births
| it (born_at = server time, metrics 100 %, every clock starts). Pets born
| before this change are grandfathered (born, unlocked, no contract needed).
|
| Family in Europe/Ljubljana (CEST = UTC+2 until 2026-10-25), no quiet hours
| unless a test says otherwise. Mutt: hunger −8 %/h, thirst −10 %/h.
*/

const CB_SVG = ['signature_format' => 'svg_path', 'signature' => 'M10 10 L20 20'];

beforeEach(function () {
    seedBreedConfigs();
});

afterEach(function () {
    Carbon::setTestNow();
});

function cbAt(string $utc): Carbon
{
    $at = Carbon::parse($utc, 'UTC');
    Carbon::setTestNow($at);

    return $at;
}

/**
 * A paired child whose pet waits for the contract, acting as that child.
 *
 * @return array{0: User, 1: Pet}
 */
function cbUnborn(string $nowUtc = '2026-10-04 06:00:00', array $pet = [], array $quietHours = []): array
{
    cbAt($nowUtc);

    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);
    if ($quietHours !== []) {
        QuietHours::create(array_merge(['parent_id' => $parent->id, 'is_active' => true], $quietHours));
    }
    $created = Pet::factory()->unborn()->create(array_merge(['user_id' => $child->id], $pet));

    actingAsRole($child);

    return [$child, $created];
}

/** One full game-loop run (decay + escalation) as the scheduler does it. */
function cbLoop(): array
{
    return [
        app(PetDecayService::class)->processAllActivePets(),
        app(EscalationService::class)->processAllActivePets(),
    ];
}

dataset('care actions', [
    'feed' => ['/api/child/pet/feed', []],
    'water' => ['/api/child/pet/water', []],
    'clean' => ['/api/child/pet/clean', []],
    'steps' => ['/api/child/pet/steps', ['steps_today' => 100, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T08:00:00+02:00']],
]);

// ──────────────────────────────────────────────────────────────
//  Pairing creates an unborn pet
// ──────────────────────────────────────────────────────────────

describe('pairing', function () {
    it('creates the pet unborn: born_at null, no clocks, awaiting_contract', function () {
        Queue::fake();
        cbAt('2026-10-04 06:00:00');
        $parent = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => null]);
        $pin = app(PairingService::class)->generatePin($parent)['pin'];
        actingAsRole($child);

        $this->postJson('/api/child/pair', ['pin' => $pin])
            ->assertStatus(201)
            ->assertJsonPath('pet.born_at', null)
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('pet.hunger_level', 100)
            ->assertJsonPath('pet.thirst_level', 100);

        $pet = Pet::where('user_id', $child->id)->sole();
        expect($pet->born_at)->toBeNull()
            ->and($pet->isUnborn())->toBeTrue()
            ->and($pet->last_decay_at)->toBeNull()
            ->and($pet->last_step_reset_at)->toBeNull()
            ->and($pet->actionLockReason())->toBe(PetLockReason::ContractRequired);
    });

    it('shows the unborn pet in GET /api/child/pet with lock reason contract_required', function () {
        [, $pet] = cbUnborn('2026-10-04 06:00:00'); // 08:00 local, inside the 06–10 window

        $this->getJson('/api/child/pet')
            ->assertOk()
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.born_at', null)
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('pet.virtual_age_months', 0)
            ->assertJsonPath('pet.hunger_level', 100)
            ->assertJsonPath('lock.is_locked', true)
            ->assertJsonPath('lock.reason', 'contract_required')
            ->assertJsonPath('lock.until', null)
            ->assertJsonPath('feeding.can_feed', false)
            ->assertJsonPath('water.can_water', false)
            ->assertJsonPath('contract.signed', false);
    });
});

// ──────────────────────────────────────────────────────────────
//  The game loop ignores unborn pets
// ──────────────────────────────────────────────────────────────

describe('game loop while unborn', function () {
    it('does not decay, schedule hygiene, close days or escalate over 24 h of ticks', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cbUnborn('2026-10-04 06:00:00', quietHours: ['bedtime_start' => '22:00', 'bedtime_end' => '06:00']);

        $cursor = Carbon::parse('2026-10-04 06:00:00', 'UTC');
        $end = $cursor->copy()->addHours(26); // crosses the local midnight
        $processed = 0;
        while ($cursor->lessThanOrEqualTo($end)) {
            Carbon::setTestNow($cursor);
            [$decay, $escalation] = cbLoop();
            $processed += $decay['processed'] + $escalation['processed'];
            $cursor->addMinutes(5);
        }

        $pet->refresh();
        expect($processed)->toBe(0) // filtered in SQL, never loaded
            ->and($pet->born_at)->toBeNull()
            ->and($pet->last_decay_at)->toBeNull()
            ->and($pet->last_step_reset_at)->toBeNull()
            ->and($pet->hygiene_scheduled_through)->toBeNull()
            ->and($pet->displayMetrics())->toBe(['hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100])
            ->and($pet->escalation_level)->toBe(0)
            ->and($pet->illness_until)->toBeNull()
            ->and($pet->walk_illness_due_at)->toBeNull()
            ->and(PetHygieneEvent::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(PetDailyWalk::where('pet_id', $pet->id)->count())->toBe(0)
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('writes nothing when the tick or escalation is called for an unborn pet directly', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cbUnborn('2026-10-04 06:00:00');
        $updatedAt = $pet->updated_at->toIso8601String();

        cbAt('2026-10-05 06:00:00');
        expect(app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id)))->toBeFalse()
            ->and(app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id)))->toBeFalse();

        $pet->refresh();
        expect($pet->updated_at->toIso8601String())->toBe($updatedAt)
            ->and($pet->last_decay_at)->toBeNull()
            ->and(PetHygieneEvent::where('pet_id', $pet->id)->count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('never makes an unborn pet ill or takes it away, whatever its stored metrics', function () {
        // Not reachable through the API — proves escalation skips unborn pets.
        [, $pet] = cbUnborn('2026-10-04 06:00:00', [
            'hunger_level' => 0, 'hunger_zero_since' => Carbon::parse('2026-10-03 00:00:00', 'UTC'),
            'hygiene_level' => 0, 'hygiene_zero_since' => Carbon::parse('2026-10-03 00:00:00', 'UTC'),
            'thirst_level' => 5,
        ]);

        cbLoop();

        $pet->refresh();
        expect($pet->escalation_level)->toBe(0)
            ->and($pet->illness_until)->toBeNull()
            ->and($pet->is_game_over)->toBeFalse()
            ->and($pet->is_active)->toBeTrue()
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
    });
});

// ──────────────────────────────────────────────────────────────
//  Locked until the contract is signed
// ──────────────────────────────────────────────────────────────

describe('lock', function () {
    it('refuses every care action with 423 contract_required and writes nothing', function (string $uri, array $body) {
        Event::fake([PetUpdated::class]);
        [, $pet] = cbUnborn('2026-10-04 06:00:00');

        $this->postJson($uri, $body)
            ->assertStatus(423)
            ->assertJsonPath('status', 'locked')
            ->assertJsonPath('reason', 'contract_required')
            ->assertJsonPath('locked_until', null)
            ->assertJsonPath('state.lock.reason', 'contract_required')
            ->assertJsonPath('state.pet.awaiting_contract', true);

        $pet->refresh();
        expect($pet->born_at)->toBeNull()
            ->and($pet->daily_step_count)->toBe(0)
            ->and($pet->last_decay_at)->toBeNull()
            ->and(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    })->with('care actions');

    it('lets a hard stop or an inactive session win over contract_required, also for signing', function (array $state, string $reason) {
        Event::fake([PetUpdated::class]);
        [, $pet] = cbUnborn('2026-10-04 06:00:00', $state);

        $this->getJson('/api/child/pet')->assertJsonPath('lock.reason', $reason);
        $this->postJson('/api/child/pet/feed')->assertStatus(423)->assertJsonPath('reason', $reason);
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(423)->assertJsonPath('reason', $reason);

        expect(Pet::findOrFail($pet->id)->born_at)->toBeNull()
            ->and(PetContract::where('pet_id', $pet->id)->exists())->toBeFalse();
        Event::assertNotDispatched(PetUpdated::class);
    })->with([
        'hard stop' => [['is_hard_stopped' => true], 'hard_stopped'],
        'inactive' => [['is_active' => false], 'inactive'],
    ]);

    it('orders lock reasons game over › inactive › hard stop › contract required › ill', function () {
        $pet = Pet::factory()->unborn()->make(['is_hard_stopped' => true, 'illness_until' => now()->addHour()]);
        expect($pet->actionLockReason())->toBe(PetLockReason::HardStopped);

        $pet->is_hard_stopped = false;
        expect($pet->actionLockReason())->toBe(PetLockReason::ContractRequired);

        $pet->born_at = now();
        expect($pet->actionLockReason())->toBe(PetLockReason::Ill);
    });
});

// ──────────────────────────────────────────────────────────────
//  Signing births the pet
// ──────────────────────────────────────────────────────────────

describe('birth', function () {
    it('births the pet at the moment of signing, not at pairing', function () {
        Event::fake([PetUpdated::class]);
        [, $pet] = cbUnborn('2026-10-02 06:00:00'); // paired two days earlier
        $signedAt = cbAt('2026-10-04 10:15:42');

        $this->postJson('/api/child/contract', CB_SVG)
            ->assertStatus(201)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.pet.born_at', '2026-10-04T12:15:42+02:00')
            ->assertJsonPath('state.pet.awaiting_contract', false)
            ->assertJsonPath('state.pet.virtual_age_months', 0)
            ->assertJsonPath('state.lock.is_locked', false)
            ->assertJsonPath('state.lock.reason', null)
            ->assertJsonPath('state.contract.signed', true)
            ->assertJsonPath('state.contract.signed_at', '2026-10-04T12:15:42+02:00');

        $pet->refresh();
        expect($pet->born_at->equalTo($signedAt))->toBeTrue()
            ->and($pet->last_decay_at->equalTo($signedAt))->toBeTrue()
            ->and($pet->last_step_reset_at->equalTo($signedAt))->toBeTrue()
            ->and($pet->contract->signed_at->equalTo($signedAt))->toBeTrue()
            ->and($pet->displayMetrics())->toBe(['hunger_level' => 100, 'thirst_level' => 100, 'energy_level' => 100, 'hygiene_level' => 100])
            ->and($pet->daily_step_count)->toBe(0)
            ->and($pet->escalation_level)->toBe(0)
            ->and($pet->hygiene_scheduled_through)->toBeNull()
            ->and($pet->frozen_at)->toBeNull()
            ->and($pet->pet_state->value)->toBe('playing');

        expect(ActivityLog::where('pet_id', $pet->id)->pluck('activity_type')->map->value->all())->toBe([ActivityType::SignedContract->value]);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'signed_contract'
            && $e->petId === $pet->id
            && $e->broadcastWith()['awaiting_contract'] === false
            && $e->broadcastWith()['born_at'] === $signedAt->toIso8601String());
    });

    it('queues exactly one PetUpdated on the broadcasts queue for the birth, awaiting_contract false', function () {
        [, $pet] = cbUnborn('2026-10-04 06:00:00');
        Queue::fake();

        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);

        Queue::assertPushed(BroadcastEvent::class, 1);
        Queue::assertPushedOn(PetUpdated::QUEUE, BroadcastEvent::class, function (BroadcastEvent $job) use ($pet) {
            $event = unserialize(serialize($job))->event; // snapshot survives the queue

            return $event instanceof PetUpdated
                && $event->petId === $pet->id
                && $event->eventType === 'signed_contract'
                && $event->payload['awaiting_contract'] === false
                && $event->payload['born_at'] === '2026-10-04T06:00:00+00:00'
                && $event->payload['hunger_level'] === 100;
        });
    });

    it('queues nothing from the tick or escalation while the pet is unborn', function () {
        [, $pet] = cbUnborn('2026-10-04 06:00:00', [
            'hunger_level' => 5, 'hygiene_level' => 0,
            'hygiene_zero_since' => Carbon::parse('2026-10-03 00:00:00', 'UTC'),
        ]);
        Queue::fake();

        cbAt('2026-10-04 12:00:00');
        cbLoop();
        app(PetDecayService::class)->processPetDecay(Pet::findOrFail($pet->id));
        app(EscalationService::class)->processPetEscalation(Pet::findOrFail($pet->id));

        Queue::assertNothingPushed();
    });

    it('broadcasts the birth only after the outer transaction commits', function () {
        Event::fake([PetUpdated::class]);
        [$child, $pet] = cbUnborn('2026-10-04 06:00:00');

        DB::transaction(function () use ($child, $pet) {
            app(PetActivityService::class)->signContract($pet, $child, 'svg_path', 'M1 1 L2 2');
            Event::assertNotDispatched(PetUpdated::class);
        });

        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('unlocks the actions right after birth', function () {
        [, $pet] = cbUnborn('2026-10-04 06:00:00');
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);
        disableHygieneEvents($pet);

        cbAt('2026-10-04 07:00:00'); // 09:00 local, morning window
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/child/pet/water')->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson('/api/child/pet/clean')->assertOk();
        $this->postJson('/api/child/pet/steps', ['steps_today' => 500, 'source' => 'healthkit', 'recorded_at' => '2026-10-04T09:00:00+02:00'])
            ->assertOk()->assertJsonPath('status', 'accepted');
    });

    it('refuses a second signature with 409 and keeps the birth moment', function () {
        [, $pet] = cbUnborn('2026-10-04 06:00:00');
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);

        cbAt('2026-10-05 06:00:00');
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(409)->assertJsonPath('reason', 'contract_already_signed');

        expect(Pet::findOrFail($pet->id)->born_at->equalTo(Carbon::parse('2026-10-04 06:00:00', 'UTC')))->toBeTrue()
            ->and(PetContract::where('pet_id', $pet->id)->count())->toBe(1);
    });

    it('signs under the row lock: a stale model never births a pet the parent paused', function () {
        Event::fake([PetUpdated::class]);
        [$child, $pet] = cbUnborn('2026-10-04 06:00:00');
        $stale = Pet::findOrFail($pet->id); // loaded before the hard stop
        Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]);

        $result = app(PetActivityService::class)->signContract($stale, $child, 'svg_path', 'M1 1 L2 2');

        expect($result->lockReason)->toBe(PetLockReason::HardStopped)
            ->and(Pet::findOrFail($pet->id)->born_at)->toBeNull()
            ->and(PetContract::count())->toBe(0);
        Event::assertNotDispatched(PetUpdated::class);
    });

    it('signs under the row lock: a stale unborn model after birth gets 409, no second birth', function () {
        [$child, $pet] = cbUnborn('2026-10-04 06:00:00');
        $stale = Pet::findOrFail($pet->id); // still unborn in memory
        app(PetActivityService::class)->signContract(Pet::findOrFail($pet->id), $child, 'svg_path', 'M1 1 L2 2');

        cbAt('2026-10-04 08:00:00');
        $result = app(PetActivityService::class)->signContract($stale, $child, 'svg_path', 'M3 3 L4 4');

        expect($result->refusal?->value)->toBe('contract_already_signed')
            ->and(Pet::findOrFail($pet->id)->born_at->equalTo(Carbon::parse('2026-10-04 06:00:00', 'UTC')))->toBeTrue();
    });
});

// ──────────────────────────────────────────────────────────────
//  After birth the game loop runs from the birth moment
// ──────────────────────────────────────────────────────────────

describe('after birth', function () {
    it('starts decay at the signing moment (mutt: 6 h → hunger 52, thirst 40)', function () {
        [, $pet] = cbUnborn('2026-10-02 06:00:00'); // two days unborn: no decay owed
        cbAt('2026-10-04 06:00:00');
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);
        disableHygieneEvents($pet);

        $cursor = Carbon::parse('2026-10-04 06:00:00', 'UTC');
        for ($i = 1; $i <= 360; $i++) {
            Carbon::setTestNow($cursor->copy()->addMinutes($i));
            cbLoop();
        }

        $pet->refresh();
        expect($pet->displayMetric('hunger_level'))->toBe(52)
            ->and($pet->displayMetric('thirst_level'))->toBe(40)
            ->and($pet->displayMetric('hygiene_level'))->toBe(100)
            ->and($pet->last_decay_at->equalTo(Carbon::parse('2026-10-04 12:00:00', 'UTC')))->toBeTrue();
    });

    it('schedules hygiene from the birth day on; an event before the birth moment never applies', function () {
        [, $pet] = cbUnborn('2026-10-03 06:00:00');
        cbAt('2026-10-04 10:00:00'); // 12:00 local
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);

        // Birth-day events planted around the birth moment.
        foreach (['2026-10-04 09:00:00', '2026-10-04 10:30:00'] as $utc) {
            PetHygieneEvent::create(['pet_id' => $pet->id, 'local_date' => '2026-10-04', 'scheduled_at' => Carbon::parse($utc, 'UTC'), 'status' => HygieneEventStatus::Pending]);
        }
        Pet::whereKey($pet->id)->update(['hygiene_scheduled_through' => '2026-10-04']);

        cbAt('2026-10-04 10:01:00');
        cbLoop();
        expect(Pet::findOrFail($pet->id)->displayMetric('hygiene_level'))->toBe(100);

        cbAt('2026-10-04 10:31:00');
        cbLoop();
        $pet->refresh();
        expect($pet->displayMetric('hygiene_level'))->toBe(0)
            ->and($pet->hygiene_zero_since->equalTo(Carbon::parse('2026-10-04 10:30:00', 'UTC')))->toBeTrue()
            ->and(PetHygieneEvent::where('pet_id', $pet->id)->where('scheduled_at', Carbon::parse('2026-10-04 09:00:00', 'UTC'))->value('status'))
            ->toBe(HygieneEventStatus::Skipped)
            // nothing scheduled for the days the pet waited unborn
            ->and(PetHygieneEvent::where('pet_id', $pet->id)->where('local_date', '<', '2026-10-04')->count())->toBe(0);
    });

    it('gives the birth day (= signing day, not pairing day) the step grace and no walk illness', function () {
        [, $pet] = cbUnborn('2026-10-02 06:00:00', quietHours: ['bedtime_start' => '22:00', 'bedtime_end' => '06:00']);
        cbAt('2026-10-04 18:00:00'); // 20:00 local
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);
        disableHygieneEvents($pet);

        cbAt('2026-10-04 21:59:00'); // 23:59 local — no steps, energy still 100 (birth-day grace)
        cbLoop();
        expect(Pet::findOrFail($pet->id)->displayMetric('energy_level'))->toBe(100);

        cbAt('2026-10-04 22:01:00'); // 00:01 local on 10-05
        cbLoop();
        cbAt('2026-10-05 04:30:00'); // after the end of the night's quiet hours (06:00 local)
        cbLoop();

        $pet->refresh();
        $walk = PetDailyWalk::where('pet_id', $pet->id)->sole();
        expect($walk->local_date->toDateString())->toBe('2026-10-04')
            ->and($walk->birth_day)->toBeTrue()
            ->and($walk->illness_due_at)->toBeNull()
            ->and($pet->displayMetric('energy_level'))->toBe(0)
            ->and($pet->walk_illness_due_at)->toBeNull()
            ->and($pet->illness_until)->toBeNull();
    });
});

// ──────────────────────────────────────────────────────────────
//  Grandfathered pets (born before M1-07b, no contract)
// ──────────────────────────────────────────────────────────────

describe('grandfathered pets', function () {
    it('keeps a born pet without a contract unlocked and ticking', function () {
        cbAt('2026-10-04 06:00:00');
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = User::factory()->child()->create(['parent_id' => $parent->id]);
        $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'born_at' => now()->subDays(3)]));
        actingAsRole($child);

        $this->getJson('/api/child/pet')
            ->assertJsonPath('lock.is_locked', false)
            ->assertJsonPath('pet.awaiting_contract', false)
            ->assertJsonPath('contract.signed', false);

        cbAt('2026-10-04 07:00:00');
        cbLoop();
        expect(Pet::findOrFail($pet->id)->displayMetric('hunger_level'))->toBe(92);
        $this->postJson('/api/child/pet/feed')->assertOk()->assertJsonPath('status', 'accepted');
    });

    it('lets a grandfathered pet sign later without a rebirth', function () {
        cbAt('2026-10-04 06:00:00');
        $child = User::factory()->child()->create(['parent_id' => User::factory()->parent()->create()->id]);
        $bornAt = Carbon::parse('2026-10-01 08:00:00', 'UTC');
        $pet = disableHygieneEvents(Pet::factory()->create(['user_id' => $child->id, 'born_at' => $bornAt, 'hunger_level' => 40.5]));
        actingAsRole($child);

        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);

        $pet->refresh();
        expect($pet->born_at->equalTo($bornAt))->toBeTrue()
            ->and((float) $pet->hunger_level)->toBe(40.5);
    });

    it('migration keeps every existing pet born and makes born_at nullable without default', function () {
        $migration = require database_path('migrations/2026_10_04_130000_make_pets_born_at_nullable.php');
        cbAt('2026-10-04 06:00:00');
        $child = User::factory()->child()->create(['parent_id' => User::factory()->parent()->create()->id]);

        // Before M1-07b: born_at NOT NULL DEFAULT now() — an old pairing row.
        $migration->down();
        $oldId = DB::table('pets')->insertGetId([
            'user_id' => $child->id, 'family_id' => $child->family->id, 'breed_type' => 'mutt', 'is_active' => true,
            'created_at' => '2026-09-20 10:00:00', 'updated_at' => '2026-09-20 10:00:00',
        ]);
        $oldBornAt = DB::table('pets')->where('id', $oldId)->value('born_at');
        expect($oldBornAt)->not->toBeNull();

        $migration->up();

        expect(DB::table('pets')->where('id', $oldId)->value('born_at'))->toBe($oldBornAt);
        $old = Pet::findOrFail($oldId);
        expect($old->isUnborn())->toBeFalse()
            ->and($old->actionLockReason())->toBeNull();

        // New rows: nullable, no default (unborn unless born_at is given).
        $newId = DB::table('pets')->insertGetId(['user_id' => $child->id, 'family_id' => $child->family->id, 'breed_type' => 'mutt', 'is_active' => true]);
        expect(DB::table('pets')->where('id', $newId)->value('born_at'))->toBeNull();

        // Rolling back births unborn pets at their creation.
        $migration->down();
        expect(DB::table('pets')->whereNull('born_at')->count())->toBe(0);
        $migration->up();
        expect(Schema::hasColumn('pets', 'born_at'))->toBeTrue();
    });
});

// ──────────────────────────────────────────────────────────────
//  Parent dashboard
// ──────────────────────────────────────────────────────────────

describe('parent dashboard', function () {
    it('shows the pet as awaiting the contract until the child signs', function () {
        [$child, $pet] = cbUnborn('2026-10-04 06:00:00');
        $parent = $child->parent;

        actingAsRole($parent);
        $this->getJson('/api/parent/dashboard')
            ->assertOk()
            ->assertJsonPath('pet.id', $pet->id)
            ->assertJsonPath('pet.awaiting_contract', true)
            ->assertJsonPath('pet.born_at', null)
            ->assertJsonPath('pet.virtual_age_months', 0)
            ->assertJsonPath('pet.hunger_level', 100)
            ->assertJsonPath('traffic_light', 'green');

        actingAsRole($child);
        cbAt('2026-10-04 07:30:00');
        $this->postJson('/api/child/contract', CB_SVG)->assertStatus(201);

        actingAsRole($parent);
        $this->getJson('/api/parent/dashboard')
            ->assertJsonPath('pet.awaiting_contract', false)
            ->assertJsonPath('pet.born_at', '2026-10-04T07:30:00+00:00')
            ->assertJsonPath('recent_activities.0.activity_type', 'signed_contract');
    });
});
