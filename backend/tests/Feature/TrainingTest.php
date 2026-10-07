<?php

use App\Enums\ActivityType;
use App\Enums\HygieneEventKind;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Enums\TrainingCommand;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\PetHygieneEvent;
use App\Models\PetStatusPeriod;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\BehaviourEventService;
use App\Services\CareScoreService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\LifeStageService;
use App\Services\PetDecayService;
use App\Services\Results\Routine;
use App\Services\RoutineLedgerService;
use App\Services\TrainingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R03 — training (David 2026-10-06): reward-timing mini-game with a
| server-generated schedule and server-scored taps, progress per command,
| breed multiplier (Border Collie 2×) and per-pet random factor (mixed
| breed ±20 %), daily budget, decay without practice, daily training
| routine in the Care Score, effects on puppy accidents / chewing, gate on
| the apps' `features`, legacy pets → 422 training_not_available.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana (UTC+2 in early October 2026). Quiet hours off
| unless a test turns them on.
*/

beforeEach(function () {
    seedLifeStageData();
});

function trAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/**
 * A born dog with a profile (training ON unless the attributes say
 * otherwise), random messes off.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: User, 2: Pet}
 */
function trFamily(string $bornUtc = '2026-10-07 05:00:00', array $attributes = [], string $breed = 'mutt', int $arrival = 2): array
{
    trAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $factory = $breed === 'mutt' ? Pet::factory()->mutt() : Pet::factory()->borderCollie();
    $pet = $factory->create(array_merge([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => $arrival,
        'training_enabled' => true,
    ], $attributes));
    disableHygieneEvents($pet);

    return [$parent, $child, $pet->fresh()];
}

/** A second child caring for the pet (own contract). */
function trSibling(User $parent, Pet $pet, string $signedUtc): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Luka']);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create([
        'pet_id' => $pet->id, 'user_id' => $child->id,
        'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2',
        'signed_at' => Carbon::parse($signedUtc, 'UTC'),
    ]);

    return $child;
}

function trStart(User $child, string $command = 'sit'): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/start', ['command' => $command]);
}

/**
 * @param  list<int>  $taps
 */
function trFinish(User $child, string $sessionId, array $taps): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $sessionId, 'taps' => $taps]);
}

/**
 * Taps 400–610 ms after every obey instant (a good child with human, varied
 * reaction times — above the 150 ms floor, not "scripted").
 *
 * @param  array<string, mixed>  $session
 * @return list<int>
 */
function trPerfectTaps(array $session): array
{
    $taps = [];
    foreach ($session['trials'] as $trial) {
        if ($trial['obeys']) {
            $taps[] = $trial['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }

    return $taps;
}

/** Start at $utc, finish perfectly at the scheduled end; returns the finish response. */
function trSession(User $child, string $utc, string $command = 'sit'): TestResponse
{
    trAt($utc);
    $session = trStart($child, $command)->assertOk()->json('session');
    Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());

    return trFinish($child, $session['id'], trPerfectTaps($session))->assertOk();
}

function trParam(StageParamKey $key, mixed $value): void
{
    BreedStageParam::where('key', $key->value)->update(['value' => json_encode($value)]);
    foreach (['mutt', 'border-collie'] as $slug) {
        LifeStageService::forgetBreed($slug);
    }
    app()->forgetInstance(LifeStageService::class);
}

function trSkill(Pet $pet, TrainingCommand $command, float $progress): void
{
    PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command->value], ['progress' => $progress]);
}

function trProgress(Pet $pet, TrainingCommand $command): float
{
    return (float) (PetTrainingSkill::where('pet_id', $pet->id)->where('command', $command->value)->value('progress') ?? 0.0);
}

/**
 * @return list<Routine>
 */
function trRoutines(Pet $pet, string $date): array
{
    $all = app(RoutineLedgerService::class)->routinesFor(collect([$pet->fresh()]), $date, $date)[$pet->id];

    return array_values(array_filter($all, fn (Routine $r) => $r->type === RoutineType::Training));
}

/**
 * A 2-month puppy with behaviour events and training; bladder clock at birth.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function trPuppy(string $bornUtc = '2026-10-07 05:00:00'): array
{
    [$parent, $child, $pet] = trFamily($bornUtc, ['behaviour_events_enabled' => true]);
    Pet::whereKey($pet->id)->update(['potty_clock_started_at' => $bornUtc, 'behaviour_scheduled_through' => '2999-12-31']);

    return [$parent, $child, $pet->fresh()];
}

/** Ticks every 5 minutes (a running scheduler). */
function trRun(Pet $pet, string $fromUtc, string $toUtc): Pet
{
    for ($t = Carbon::parse($fromUtc, 'UTC')->addMinutes(5); $t->lessThanOrEqualTo(Carbon::parse($toUtc, 'UTC')); $t->addMinutes(5)) {
        trAt($t->toDateTimeString());
        app(PetDecayService::class)->processPetDecay($pet->fresh());
    }

    return $pet->fresh();
}

/** Parent → generate-pin (with $body) → child pin-login: the pet an app build creates. */
function trCreateViaPin(array $body, ?array $childFeatures): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
    app('auth')->forgetGuards();
    actingAsRole($parent);
    $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk();
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    $login = postJson('/api/child/pin-login', array_merge(
        ['pin' => $pin->json('pin'), 'device_name' => 'Tablet'],
        $childFeatures === null ? [] : ['features' => $childFeatures],
    ))->assertSuccessful();

    return Pet::findOrFail($login->json('pet.id'));
}

// ──────────────────────────────────────────────────────────────
//  Gate: the apps' `features`
// ──────────────────────────────────────────────────────────────

describe('client capability gate', function () {
    it('enables training only when the PIN had a profile and both apps declared `training`', function () {
        $profile = ['origin' => 'bought', 'age_stage' => 'puppy'];

        expect(trCreateViaPin([...$profile, 'features' => ['training']], ['training'])->training_enabled)->toBeTrue()
            ->and(trCreateViaPin([...$profile, 'features' => ['behaviour_events', 'training']], ['behaviour_events'])->training_enabled)->toBeFalse()
            ->and(trCreateViaPin([...$profile, 'features' => ['behaviour_events']], ['training'])->training_enabled)->toBeFalse()
            ->and(trCreateViaPin([...$profile, 'features' => ['training']], null)->training_enabled)->toBeFalse()
            // No profile → legacy pet (pre-M5 rules), features ignored.
            ->and(trCreateViaPin(['features' => ['training']], ['training'])->training_enabled)->toBeFalse();

        $both = trCreateViaPin([...$profile, 'features' => ['teleport', 'training', 'behaviour_events']], ['behaviour_events', 'training', 'x']);
        expect($both->training_enabled)->toBeTrue()->and($both->behaviour_events_enabled)->toBeTrue();
    });

    it('keeps existing pets and legacy pets without training (422 training_not_available, no routine)', function () {
        [, $child, $pet] = trFamily(attributes: ['training_enabled' => false]);
        [, $legacyChild, $legacy] = trFamily(attributes: ['arrival_age_months' => null]); // legacy profile even if the flag were set

        expect($legacy->trainingEnabled())->toBeFalse();
        trAt('2026-10-08 08:00:00');
        foreach ([$child, $legacyChild] as $c) {
            trStart($c)->assertStatus(422)->assertJsonPath('reason', 'training_not_available')
                ->assertJsonPath('state.training.enabled', false)
                ->assertJsonPath('state.training.commands', [])
                ->assertJsonPath('state.training.can_start', false);
            trFinish($c, '7b1c2d3e-0000-4000-8000-000000000000', [])->assertStatus(422)->assertJsonPath('reason', 'training_not_available');
        }

        trAt('2026-10-10 08:00:00');
        expect(trRoutines($pet, '2026-10-09'))->toBe([])
            ->and(trRoutines($legacy, '2026-10-09'))->toBe([]);
    });
});

// ──────────────────────────────────────────────────────────────
//  Session: start, schedule, one at a time, TTL, budget
// ──────────────────────────────────────────────────────────────

describe('session', function () {
    it('returns a server schedule: 8 cues 6 s apart after a 2 s lead-in, obey 0.8–2.5 s after the cue, 1.5 s praise window', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        Event::fake([PetUpdated::class]);

        $res = trStart($child, 'come')->assertOk()->assertJsonPath('status', 'accepted');
        $s = $res->json('session');

        expect($s['command'])->toBe('come')
            ->and($s['duration_ms'])->toBe(50000)
            ->and($s['praise_window_ms'])->toBe(1500)
            ->and($s['started_at'])->toBe('2026-10-08T10:00:00+02:00')
            ->and($s['ends_at'])->toBe('2026-10-08T10:00:50+02:00')
            ->and($s['expires_at'])->toBe('2026-10-08T10:01:50+02:00')
            ->and($s['trials'])->toHaveCount(8)
            ->and(array_column($s['trials'], 'cue_at_ms'))->toBe([2000, 8000, 14000, 20000, 26000, 32000, 38000, 44000])
            ->and(array_column($s['trials'], 'obeys'))->toContain(true, false);
        foreach ($s['trials'] as $t) {
            if ($t['obeys']) {
                expect($t['obey_at_ms'] - $t['cue_at_ms'])->toBeGreaterThanOrEqual(800)->toBeLessThanOrEqual(2500)
                    ->and($t['window_end_ms'])->toBe($t['obey_at_ms'] + 1500);
            } else {
                expect($t['obey_at_ms'])->toBeNull()->and($t['window_end_ms'])->toBeNull();
            }
        }

        $res->assertJsonPath('state.training.session.id', $s['id'])
            ->assertJsonPath('state.training.session.mine', true)
            ->assertJsonPath('state.training.can_start', false)
            ->assertJsonPath('state.training.daily_budget_left_seconds', 250);
        // No routine yet: only a completed session counts.
        expect(ActivityLog::where('pet_id', $pet->id)->count())->toBe(0);
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->petId === $pet->id && $e->eventType === 'training_started');
    });

    it('allows one session per pet at a time — also for a sibling — until it is finished or expires (TTL)', function () {
        [$parent, $child, $pet] = trFamily();
        $sibling = trSibling($parent, $pet, '2026-10-07 06:00:00');
        trAt('2026-10-08 08:00:00');

        $first = trStart($child)->assertOk()->json('session');
        trAt('2026-10-08 08:00:30');
        trStart($child, 'place')->assertStatus(422)
            ->assertJsonPath('reason', 'training_session_active')
            ->assertJsonPath('next_allowed_at', '2026-10-08T10:01:50+02:00');
        trStart($sibling)->assertStatus(422)->assertJsonPath('reason', 'training_session_active')
            ->assertJsonPath('state.training.session.mine', false);
        // The sibling cannot finish someone else's session.
        trAt('2026-10-08 08:00:50');
        trFinish($sibling, $first['id'], [])->assertStatus(422)->assertJsonPath('reason', 'training_session_invalid');

        // After the TTL the slot is free again; the old session expired (no progress, no routine).
        trAt('2026-10-08 08:01:50');
        trStart($sibling)->assertOk();
        expect(PetTrainingSession::where('public_id', $first['id'])->value('status'))->toBe('expired');
        trFinish($child, $first['id'], trPerfectTaps($first))->assertStatus(422)->assertJsonPath('reason', 'training_session_expired');
        expect(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'trained_pet')->count())->toBe(0);
    });

    it('refuses a finish before the schedule has run (2 s tolerance) and after the TTL', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        $s = trStart($child)->assertOk()->json('session');

        trAt('2026-10-08 08:00:47');
        trFinish($child, $s['id'], [])->assertStatus(422)->assertJsonPath('reason', 'training_session_not_over');
        trAt('2026-10-08 08:00:48');
        trFinish($child, $s['id'], [])->assertOk()->assertJsonPath('status', 'accepted');

        $late = trSession($child, '2026-10-08 09:00:00')->json('result.session_id');
        expect($late)->not->toBeNull();
        trAt('2026-10-08 10:00:00');
        $s2 = trStart($child)->assertOk()->json('session');
        trAt('2026-10-08 10:01:51');
        trFinish($child, $s2['id'], trPerfectTaps($s2))->assertStatus(422)->assertJsonPath('reason', 'training_session_expired');
        expect(PetTrainingSession::where('public_id', $s2['id'])->value('status'))->toBe('expired');
    });

    it('refuses impossible tap values and unknown sessions', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        $s = trStart($child)->assertOk()->json('session');
        trAt('2026-10-08 08:00:50');

        trFinish($child, $s['id'], [50001])->assertStatus(422)->assertJsonPath('reason', 'training_invalid_taps');
        trFinish($child, $s['id'], [-1])->assertStatus(422)->assertJsonValidationErrors('taps.0');
        trFinish($child, $s['id'], ['soon'])->assertStatus(422)->assertJsonValidationErrors('taps.0');
        trFinish($child, $s['id'], range(1, 65))->assertStatus(422)->assertJsonValidationErrors('taps');
        trFinish($child, 'not-a-uuid', [])->assertStatus(422)->assertJsonValidationErrors('session_id');
        trFinish($child, '7b1c2d3e-0000-4000-8000-000000000000', [])->assertStatus(422)->assertJsonPath('reason', 'training_session_invalid');
        app('auth')->forgetGuards();
        actingAsRole($child);
        postJson('/api/child/pet/training/finish', ['session_id' => $s['id']])->assertStatus(422)->assertJsonValidationErrors('taps');
        postJson('/api/child/pet/training/start', ['command' => 'roll_over'])->assertStatus(422)->assertJsonValidationErrors('command');

        // Still active and finishable after the refusals.
        trFinish($child, $s['id'], [50000])->assertOk();
    });

    it('refuses a start whose session + TTL would cross the family-local midnight (also on the 25-hour DST day)', function () {
        [, $child, $pet] = trFamily();

        // 23:58:00 local (CEST) + 50 s + 60 s TTL = 23:59:50 → ok; 23:58:11 → would end 00:00:01.
        trAt('2026-10-08 21:58:11');
        trStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_day_ending')
            ->assertJsonPath('next_allowed_at', '2026-10-09T00:00:00+02:00')
            ->assertJsonPath('state.training.can_start', false);
        trAt('2026-10-08 21:58:00');
        trStart($child)->assertOk();

        // 2026-10-25 has 25 hours (CEST → CET at 03:00): its midnight is 23:00 UTC.
        trAt('2026-10-25 22:58:11');
        trStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_day_ending')
            ->assertJsonPath('next_allowed_at', '2026-10-26T00:00:00+01:00');
        trAt('2026-10-25 22:58:00');
        $s = trStart($child)->assertOk()->json('session');
        Carbon::setTestNow(Carbon::parse($s['ends_at'])->utc());
        trFinish($child, $s['id'], trPerfectTaps($s))->assertOk();
        expect(trRoutines($pet, '2026-10-25')[0]->status)->toBe(RoutineStatus::Done);
    });

    it('does not count a session a lock began during: no progress, no routine, its time refunded', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        $s = trStart($child)->assertOk()->json('session');

        // The parent pauses the game during the session, and lifts it again.
        trAt('2026-10-08 08:00:20');
        Pet::find($pet->id)->update(['is_hard_stopped' => true]);
        trAt('2026-10-08 08:00:50');
        trFinish($child, $s['id'], trPerfectTaps($s))->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        trAt('2026-10-08 08:01:00');
        Pet::find($pet->id)->update(['is_hard_stopped' => false]);

        trFinish($child, $s['id'], trPerfectTaps($s))->assertStatus(422)
            ->assertJsonPath('reason', 'training_session_interrupted')
            ->assertJsonPath('state.training.session', null)
            ->assertJsonPath('state.training.daily_budget_left_seconds', 300)
            ->assertJsonPath('state.training.can_start', true);
        expect(PetTrainingSession::where('public_id', $s['id'])->value('status'))->toBe('interrupted')
            ->and(ActivityLog::where('pet_id', $pet->id)->where('activity_type', 'trained_pet')->count())->toBe(0)
            ->and(trProgress($pet, TrainingCommand::Sit))->toBe(0.0);

        // A new session can start right away (slot freed, budget refunded).
        trStart($child)->assertOk()->assertJsonPath('state.training.daily_budget_left_seconds', 250);

        // A lock that began BEFORE the session (and ended) does not interrupt it.
        expect(app(TrainingService::class)->usedSecondsOn($pet->fresh(), '2026-10-08'))->toBe(50);
    });

    it('gives the child their own running session with its schedule (resume after a restart), others only the summary', function () {
        [$parent, $child, $pet] = trFamily();
        $sibling = trSibling($parent, $pet, '2026-10-07 06:00:00');
        trAt('2026-10-08 08:00:00');
        $s = trStart($child, 'come')->assertOk()->json('session');

        trAt('2026-10-08 08:00:10');
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('training.session.id', $s['id'])
            ->assertJsonPath('training.session.mine', true)
            ->assertJsonPath('training.session.duration_ms', 50000)
            ->assertJsonPath('training.session.praise_window_ms', 1500)
            ->assertJsonPath('training.session.min_reaction_ms', 150)
            ->assertJsonPath('training.session.trials', $s['trials']);

        app('auth')->forgetGuards();
        actingAsRole($sibling);
        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('training.session.mine', false)
            ->assertJsonPath('training.session.trials', null)
            ->assertJsonPath('training.session.duration_ms', null);
    });

    it('builds the broadcast / dashboard summary without budget queries', function () {
        [, $child, $pet] = trFamily();
        trSession($child, '2026-10-08 08:00:00');
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $summary = PetUpdated::payloadFor($pet->fresh(), 'trained_pet')['training'];

        expect($summary['today_done'])->toBeTrue()
            ->and(collect($sql)->filter(fn ($q) => str_contains($q, 'sum(') || str_contains($q, 'breed_stage_params'))->all())->toBe([]);
    });

    it('limits the dog to 5 minutes of mini-game per family-local day (6 sessions of 50 s)', function () {
        [, $child, $pet] = trFamily();
        for ($i = 0; $i < 6; $i++) {
            trSession($child, sprintf('2026-10-08 08:%02d:00', $i * 2));
        }

        trAt('2026-10-08 09:00:00');
        trStart($child)->assertStatus(422)
            ->assertJsonPath('reason', 'training_daily_budget_used')
            ->assertJsonPath('next_allowed_at', '2026-10-09T00:00:00+02:00')
            ->assertJsonPath('state.training.daily_budget_left_seconds', 0)
            ->assertJsonPath('state.training.can_start', false);

        // A new family-local day: 00:00 local = 22:00 UTC.
        trAt('2026-10-08 22:00:00');
        trStart($child)->assertOk();
        // An expired session counts in full too (the dog spent the time).
        expect(app(TrainingService::class)->usedSecondsOn($pet, '2026-10-09'))->toBe(50);
    });

    it('locks first (423 by priority), then the training rules', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');

        Pet::whereKey($pet->id)->update(['is_hard_stopped' => true]);
        trStart($child)->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');
        Pet::whereKey($pet->id)->update(['is_hard_stopped' => false, 'illness_until' => '2026-10-08 18:00:00']);
        trStart($child)->assertStatus(423)->assertJsonPath('reason', 'ill');
        Pet::whereKey($pet->id)->update(['illness_until' => null, 'is_game_over' => true]);
        trStart($child)->assertStatus(423)->assertJsonPath('reason', 'game_over');

        // A legacy pet that is hard-stopped: the lock wins over training_not_available.
        [, $legacyChild, $legacy] = trFamily(attributes: ['arrival_age_months' => null, 'is_hard_stopped' => true]);
        trStart($legacyChild)->assertStatus(423)->assertJsonPath('reason', 'hard_stopped');

        // Unborn (contract first).
        [, $unbornChild, $unborn] = trFamily();
        Pet::whereKey($unborn->id)->update(['born_at' => null]);
        DB::table('pet_caretakers')->where('pet_id', $unborn->id)->update(['requires_contract' => true]);
        trStart($unbornChild)->assertStatus(423)->assertJsonPath('reason', 'contract_required');
    });

    it('is a child-only API (parent token 403, no pet 404)', function () {
        [$parent, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        app('auth')->forgetGuards();
        actingAsRole($parent);
        postJson('/api/child/pet/training/start', ['command' => 'sit'])->assertStatus(403);

        $loner = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Ana']);
        trStart($loner)->assertStatus(404)->assertJsonPath('reason', 'no_pet');
    });
});

// ──────────────────────────────────────────────────────────────
//  Scoring taps (server-side, against the stored schedule)
// ──────────────────────────────────────────────────────────────

describe('scoring', function () {
    $trials = [
        ['index' => 0, 'cue_at_ms' => 2000, 'obeys' => true, 'obey_at_ms' => 3000, 'window_end_ms' => 4500],
        ['index' => 1, 'cue_at_ms' => 8000, 'obeys' => true, 'obey_at_ms' => 9000, 'window_end_ms' => 10500],
        ['index' => 2, 'cue_at_ms' => 14000, 'obeys' => true, 'obey_at_ms' => 15000, 'window_end_ms' => 16500],
        ['index' => 3, 'cue_at_ms' => 20000, 'obeys' => true, 'obey_at_ms' => 21000, 'window_end_ms' => 22500],
        ['index' => 4, 'cue_at_ms' => 26000, 'obeys' => false, 'obey_at_ms' => null, 'window_end_ms' => null],
        ['index' => 5, 'cue_at_ms' => 32000, 'obeys' => false, 'obey_at_ms' => null, 'window_end_ms' => null],
        ['index' => 6, 'cue_at_ms' => 38000, 'obeys' => true, 'obey_at_ms' => 39000, 'window_end_ms' => 40500],
        ['index' => 7, 'cue_at_ms' => 44000, 'obeys' => true, 'obey_at_ms' => 45000, 'window_end_ms' => 46500],
    ];

    it('scores the first tap of each cue: in time (window edges included), too early, too late, no praise, waited, praised without obeying', function () use ($trials) {
        $taps = [
            500,          // lead-in: ignored
            3150,         // 0: exactly at the 150 ms reaction floor → in time
            4500, 4600,   // 1: no tap in its slot is in [8000, 14000) … 4500/4600 belong to cue 0 (already decided)
            14999, 15200, // 2: first tap 1 ms early → too early (the second does not rescue it)
            22501,        // 3: 1 ms after the window → too late
            27000,        // 4: praised although the dog did not obey
            // 5: no tap → waited
            40500,        // 6: last ms of the window → in time
            // 7: no tap → no praise
        ];

        $score = TrainingService::score($trials, 1500, $taps);

        expect(array_column($score['trials'], 'outcome'))->toBe([
            'in_time', 'no_praise', 'too_early', 'too_late', 'praised_without_obeying', 'waited', 'in_time', 'no_praise',
        ])->and($score['successes'])->toBe(2)
            ->and($score['obeyed'])->toBe(6)
            ->and($score['trials'][2]['tap_ms'])->toBe(14999);
    });

    it('scores taps in any order and counts nothing for a child who taps all the time', function () use ($trials) {
        expect(TrainingService::score($trials, 1500, [45300, 3200])['successes'])->toBe(2);

        // Tapping every 500 ms from the first cue: every obeying cue is "too early".
        $spam = range(2000, 49500, 500);
        $score = TrainingService::score($trials, 1500, $spam);
        expect($score['successes'])->toBe(0)
            ->and(array_unique(array_column($score['trials'], 'outcome')))->toEqualCanonicalizing(['too_early', 'praised_without_obeying']);
    });

    it('treats a praise faster than a human reaction (< 150 ms after obeying) as too early', function () use ($trials) {
        $score = TrainingService::score($trials, 1500, [3000, 9149, 15150]);

        expect(array_column(array_slice($score['trials'], 0, 3), 'outcome'))->toBe(['too_early', 'too_early', 'in_time'])
            ->and($score['trials'][2]['latency_ms'])->toBe(150)
            ->and($score['successes'])->toBe(1);
    });

    it('logs a session with uniformly perfect latency as suspicious (pet id only) and still scores it', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        $s = trStart($child)->assertOk()->json('session');
        expect($s['min_reaction_ms'])->toBe(150);
        // Pin the stored schedule (a random one may have < 3 obeyed cues): 4 obeyed, 4 ignored.
        $trials = [];
        for ($i = 0; $i < 8; $i++) {
            $cue = 2000 + 6000 * $i;
            $trials[] = ['index' => $i, 'cue_at_ms' => $cue, 'obeys' => $i % 2 === 0,
                'obey_at_ms' => $i % 2 === 0 ? $cue + 1000 : null, 'window_end_ms' => $i % 2 === 0 ? $cue + 2500 : null];
        }
        PetTrainingSession::where('public_id', $s['id'])->update(['schedule' => json_encode(['praise_window_ms' => 1500, 'min_reaction_ms' => 150, 'trials' => $trials])]);
        $robot = [];
        foreach ($trials as $t) {
            if ($t['obeys']) {
                $robot[] = $t['obey_at_ms'] + 200;   // identical reaction every time
            }
        }
        Log::spy();
        trAt('2026-10-08 08:00:50');
        trFinish($child, $s['id'], $robot)->assertOk()->assertJsonPath('result.successes', count($robot));

        Log::shouldHaveReceived('warning')->with('TrainingService: suspicious training session (uniform praise latency)', ['pet_id' => $pet->id])->once();
        expect(PetTrainingSession::where('public_id', $s['id'])->value('result')['suspicious'] ?? null)->toBeTrue();

        // A human-like spread is not suspicious.
        $human = ['trials' => [
            ['outcome' => 'in_time', 'latency_ms' => 310], ['outcome' => 'in_time', 'latency_ms' => 520],
            ['outcome' => 'in_time', 'latency_ms' => 450], ['outcome' => 'too_late', 'latency_ms' => 1700],
        ]];
        $bot = ['trials' => [
            ['outcome' => 'in_time', 'latency_ms' => 300], ['outcome' => 'in_time', 'latency_ms' => 330],
            ['outcome' => 'in_time', 'latency_ms' => 360],
        ]];
        expect(TrainingService::looksScripted($human))->toBeFalse()
            ->and(TrainingService::looksScripted($bot))->toBeTrue()
            ->and(TrainingService::looksScripted(['trials' => array_slice($bot['trials'], 0, 2)]))->toBeFalse();
    });

    it('adds progress for every in-time praise, logs the routine once and returns the result (repeat = unchanged)', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        Event::fake([PetUpdated::class]);
        $s = trStart($child, 'sit')->assertOk()->json('session');
        $factor = (float) $pet->fresh()->training_learning_factor;
        $obeying = array_values(array_filter($s['trials'], fn ($t) => $t['obeys']));

        // Perfect on all obeying cues but the first (too late there).
        $taps = trPerfectTaps($s);
        $taps[0] = $obeying[0]['window_end_ms'] + 1;
        trAt('2026-10-08 08:00:50');
        $res = trFinish($child, $s['id'], $taps)->assertOk()->assertJsonPath('status', 'accepted');

        $successes = count($obeying) - 1;
        $gain = $successes * 1.0 * 1.0 * $factor;   // progress per success × mutt multiplier × factor
        $res->assertJsonPath('result.session_id', $s['id'])
            ->assertJsonPath('result.command', 'sit')
            ->assertJsonPath('result.successes', $successes)
            ->assertJsonPath('result.obeyed', count($obeying))
            ->assertJsonPath('result.progress_before', 0)
            ->assertJsonPath('result.progress_after', Pet::displayValue($gain))
            ->assertJsonPath('result.trials.'.$obeying[0]['index'].'.outcome', 'too_late')
            ->assertJsonPath('state.training.today_done', true)
            ->assertJsonPath('state.training.session', null)
            ->assertJsonPath('state.training.commands.0.command', 'sit')
            ->assertJsonPath('state.training.commands.0.progress', Pet::displayValue($gain))
            ->assertJsonPath('state.training.commands.0.last_practised_at', '2026-10-08T10:00:50+02:00');
        expect(abs($res->json('result.progress_gain') - round($gain, 2)))->toBeLessThan(0.0001)
            ->and(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($gain, 1e-9);

        $log = ActivityLog::where('pet_id', $pet->id)->sole();
        expect($log->activity_type)->toBe(ActivityType::TrainedPet)
            ->and($log->actor_user_id)->toBe($child->id)
            ->and($log->value)->toBe($successes);

        // A retried finish (network) → the same result, nothing written twice.
        trFinish($child, $s['id'], [])->assertOk()->assertJsonPath('status', 'unchanged')
            ->assertJsonPath('result.successes', $successes);
        expect(ActivityLog::where('pet_id', $pet->id)->count())->toBe(1)
            ->and(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($gain, 1e-9);

        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'trained_pet');
        Event::assertDispatchedTimes(PetUpdated::class, 2); // started + trained, not the repeat
    });

    it('caps progress at 100 %', function () {
        [, $child, $pet] = trFamily();
        trSkill($pet, TrainingCommand::Potty, 99.5);
        $res = trSession($child, '2026-10-08 08:00:00', 'potty');

        $res->assertJsonPath('result.progress_after', 100)
            ->assertJsonPath('state.training.commands.3.command', 'potty')
            ->assertJsonPath('state.training.commands.3.learned', true);
        expect(trProgress($pet, TrainingCommand::Potty))->toBe(100.0);
    });

    it('makes a better trained dog obey more often (50 % untrained → 90 % trained)', function () {
        $service = app(TrainingService::class);
        $count = function (float $progress) use ($service): int {
            $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'obey', true)));
            $n = 0;
            for ($i = 0; $i < 200; $i++) {
                $n += count(array_filter($service->schedule($progress, $rng)['trials'], fn ($t) => $t['obeys']));
            }

            return $n;
        };

        // 1,600 cues each: ≈ 50 % vs ≈ 90 % (at least one obeyed / one ignored per session).
        expect($count(0.0))->toBeGreaterThan(700)->toBeLessThan(900)
            ->and($count(100.0))->toBeGreaterThan(1300)->toBeLessThan(1500);
    });
});

// ──────────────────────────────────────────────────────────────
//  Breed multiplier and the mixed breed's individual factor
// ──────────────────────────────────────────────────────────────

describe('learning speed', function () {
    it('lets a Border Collie learn 2× as fast as the mixed-breed baseline (factor 1, no individual variation)', function () {
        [, $child, $collie] = trFamily(breed: 'border_collie');
        $res = trSession($child, '2026-10-08 08:00:00');

        $successes = $res->json('result.successes');
        expect((float) $collie->fresh()->training_learning_factor)->toBe(1.0)
            ->and(trProgress($collie, TrainingCommand::Sit))->toEqualWithDelta($successes * 2.0, 1e-9);
    });

    it('draws the mixed breed\'s factor once in [0.8, 1.2], stable per pet and stored', function () {
        $factors = [];
        for ($i = 0; $i < 12; $i++) {
            [, , $pet] = trFamily();
            $service = app(TrainingService::class);
            $first = $service->learningFactor($pet);
            $pet->training_learning_factor = null;
            expect($service->learningFactor($pet))->toBe($first);  // same seed (salt, pet) → same factor
            $factors[] = $first;
        }

        expect(min($factors))->toBeGreaterThanOrEqual(0.8)
            ->and(max($factors))->toBeLessThanOrEqual(1.2)
            ->and(count(array_unique($factors)))->toBeGreaterThan(1);

        // Stored at the first session and never redrawn (e.g. after the admin changes the variation).
        [, $child, $pet] = trFamily();
        trSession($child, '2026-10-08 08:00:00');
        $stored = (float) $pet->fresh()->training_learning_factor;
        trParam(StageParamKey::TrainingIndividualVariation, 0.0);
        trSession($child, '2026-10-08 09:00:00');
        expect((float) $pet->fresh()->training_learning_factor)->toBe($stored)
            ->and(app(TrainingService::class)->gainPerSuccess($pet->fresh(), '2026-10-08'))->toEqualWithDelta($stored, 1e-9);
    });

    it('takes every training number from breed_stage_params (admin edits apply)', function () {
        [, $child, $pet] = trFamily();
        Pet::whereKey($pet->id)->update(['training_learning_factor' => 1.0]);
        trParam(StageParamKey::TrainingProgressPerSuccess, 5.0);
        trParam(StageParamKey::TrainingMinutesPerDay, 1);

        $res = trSession($child, '2026-10-08 08:00:00');
        expect(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($res->json('result.successes') * 5.0, 1e-9);
        trAt('2026-10-08 09:00:00');
        trStart($child)->assertStatus(422)->assertJsonPath('reason', 'training_daily_budget_used');
    });
});

// ──────────────────────────────────────────────────────────────
//  Decay without practice
// ──────────────────────────────────────────────────────────────

describe('decay', function () {
    it('costs every command 2 points per missed training day (tick after midnight), never below 0', function () {
        [, $child, $pet] = trFamily('2026-10-07 05:00:00');
        trSkill($pet, TrainingCommand::Sit, 50.0);
        trSkill($pet, TrainingCommand::Come, 1.0);

        // 08.10 trained, 09.10 and 10.10 not.
        trSession($child, '2026-10-08 08:00:00');
        $afterSession = trProgress($pet, TrainingCommand::Sit);

        trAt('2026-10-09 22:01:00'); // 00:01 local on 10.10
        Pet::whereKey($pet->id)->update(['last_decay_at' => Carbon::parse('2026-10-09 22:00:00', 'UTC')]);
        app(PetDecayService::class)->processPetDecay($pet->fresh());
        expect(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($afterSession - 2.0, 1e-9)  // 09.10 missed
            ->and(trProgress($pet, TrainingCommand::Come))->toBe(0.0)
            ->and(substr((string) $pet->fresh()->training_decayed_through, 0, 10))->toBe('2026-10-09');

        // Same day again: no double decay.
        trAt('2026-10-09 22:02:00');
        app(PetDecayService::class)->processPetDecay($pet->fresh());
        expect(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($afterSession - 2.0, 1e-9);

        // 10.10 missed too — applied at the next start even before a tick ran.
        trAt('2026-10-10 22:30:00');
        trStart($child)->assertOk();
        expect(trProgress($pet, TrainingCommand::Sit))->toEqualWithDelta($afterSession - 4.0, 1e-9);
    });

    it('never decays on the birth day or on a day excused by a long hard stop', function () {
        [, $child, $pet] = trFamily('2026-10-07 05:00:00');
        trSkill($pet, TrainingCommand::Sit, 50.0);
        // 08.10: hard stop all day (≥ 50 % of the day) → the routine is not expected.
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'hard_stop',
            'started_at' => Carbon::parse('2026-10-07 22:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-10-08 21:00:00', 'UTC')]);

        trAt('2026-10-08 22:05:00');
        expect(app(TrainingService::class)->applyDecay($pet->fresh(), now()))->toBe(0)
            ->and(trProgress($pet, TrainingCommand::Sit))->toBe(50.0);
    });
});

// ──────────────────────────────────────────────────────────────
//  Routine & Care Score
// ──────────────────────────────────────────────────────────────

describe('training routine', function () {
    it('is one whole-day routine: pending today, done by the training child, missed at day end, none on the birth day', function () {
        [, $child, $pet] = trFamily('2026-10-07 05:00:00');

        trAt('2026-10-07 12:00:00');
        expect(trRoutines($pet, '2026-10-07'))->toBe([]);

        trAt('2026-10-08 06:00:00');
        $today = trRoutines($pet, '2026-10-08');
        expect($today)->toHaveCount(1)->and($today[0]->status)->toBe(RoutineStatus::Pending)
            ->and($today[0]->dueAt->toDateTimeString())->toBe('2026-10-08 22:00:00');

        trSession($child, '2026-10-08 15:00:00');
        $done = trRoutines($pet, '2026-10-08')[0];
        expect($done->status)->toBe(RoutineStatus::Done)->and($done->actorUserId)->toBe($child->id)
            ->and($done->doneAt->toDateTimeString())->toBe('2026-10-08 15:00:50');

        trAt('2026-10-09 23:00:00');
        expect(trRoutines($pet, '2026-10-09')[0]->status)->toBe(RoutineStatus::Missed);

        // Closed days are stored with the training type.
        app(RoutineLedgerService::class)->closePet($pet->id, now());
        expect(DB::table('pet_daily_routines')->where('pet_id', $pet->id)->where('routine_type', 'training')->orderBy('local_date')->pluck('status')->all())
            ->toBe(['done', 'missed']);
    });

    it('is excused like the walk: only a freeze covering ≥ 50 % of the day; a short one is not an excuse', function () {
        [, $child, $pet] = trFamily('2026-10-07 05:00:00');
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'hard_stop',
            'started_at' => Carbon::parse('2026-10-08 06:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-10-08 09:00:00', 'UTC')]);
        PetStatusPeriod::create(['pet_id' => $pet->id, 'kind' => 'illness',
            'started_at' => Carbon::parse('2026-10-09 04:00:00', 'UTC'), 'ended_at' => Carbon::parse('2026-10-09 16:00:00', 'UTC')]);

        trAt('2026-10-10 12:00:00');
        expect(trRoutines($pet, '2026-10-08')[0]->status)->toBe(RoutineStatus::Missed)
            ->and(trRoutines($pet, '2026-10-09'))->toBe([]);
    });

    it('counts for the child who trained (fair share) and in the parent dashboard', function () {
        [$parent, $child, $pet] = trFamily('2026-10-07 05:00:00');
        $sibling = trSibling($parent, $pet, '2026-10-07 06:00:00');
        trSession($sibling, '2026-10-08 15:00:00', 'place');

        trAt('2026-10-08 18:00:00');
        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$pet->fresh()]), 'Europe/Ljubljana');
        $routine = trRoutines($pet, '2026-10-08')[0];
        expect($scores->credited($board, $pet, $routine, $sibling->id, 2))->toBeTrue()
            ->and($scores->credited($board, $pet, $routine, $child->id, 2))->toBeFalse();

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dash = getJson('/api/parent/dashboard')->assertOk();
        $petJson = collect($dash->json('family.pets'))->firstWhere('id', $pet->id);
        expect($petJson['training']['enabled'])->toBeTrue()
            ->and($petJson['training']['today_done'])->toBeTrue()
            ->and($petJson['training']['session_active'])->toBeFalse()
            ->and($petJson['training']['commands'][2]['command'])->toBe('place')
            ->and($petJson['training']['commands'][2]['progress'])->toBe(Pet::displayValue(trProgress($pet, TrainingCommand::Place)));
        $timeline = collect($petJson['timeline'])->firstWhere('activity_type', 'trained_pet');
        expect($timeline)->not->toBeNull();
        $report = getJson("/api/parent/children/{$sibling->id}/report")->assertOk();
        expect($report->json('by_type.training.done_by_child'))->toBe(1)
            ->and($report->json('by_type.training.expected'))->toBe(1);
    });
});

// ──────────────────────────────────────────────────────────────
//  Effects: puppy accidents and chewing (proposals)
// ──────────────────────────────────────────────────────────────

describe('effects', function () {
    it('lets a fully potty-trained puppy ask to go out instead of having the accident (reduction 1.0 for the test)', function () {
        trParam(StageParamKey::PottyTrainingAccidentReduction, 1.0);
        [, , $trained] = trPuppy();
        trSkill($trained, TrainingCommand::Potty, 100.0);
        [, , $untrained] = trPuppy();

        // 2 months → holds 2 h: due 07:00 UTC.
        $trained = trRun($trained, '2026-10-07 05:00:00', '2026-10-07 07:30:00');
        $untrained = trRun($untrained, '2026-10-07 05:00:00', '2026-10-07 07:30:00');

        expect(PetHygieneEvent::where('pet_id', $untrained->id)->where('kind', HygieneEventKind::Accident->value)->count())->toBe(1)
            ->and(PetHygieneEvent::where('pet_id', $trained->id)->where('kind', HygieneEventKind::Accident->value)->count())->toBe(0)
            // The clock restarted when the puppy asked to go out.
            ->and($trained->potty_clock_started_at->toDateTimeString())->toBe('2026-10-07 07:00:00')
            ->and((int) $trained->hygiene_level)->toBe(100);
    });

    it('avoids accidents in proportion to potty progress with a seeded roll (0 % → never, default 0.75 × progress)', function () {
        [, , $pet] = trPuppy();
        $service = app(TrainingService::class);
        trSkill($pet, TrainingCommand::Potty, 0.0);
        expect($service->accidentAvoidanceChance($pet, '2026-10-07'))->toBe(0.0);
        trSkill($pet, TrainingCommand::Potty, 60.0);
        expect($service->accidentAvoidanceChance($pet, '2026-10-07'))->toEqualWithDelta(0.45, 1e-9);

        $at = Carbon::parse('2026-10-07 07:00:00', 'UTC');
        expect($service->accidentSignalRoll($pet, $at))->toBe($service->accidentSignalRoll($pet, $at))
            ->and($service->accidentSignalRoll($pet, $at))->not->toBe($service->accidentSignalRoll($pet, $at->copy()->addHour()));

        // Training off for the pet → no effect even with skill rows.
        Pet::whereKey($pet->id)->update(['training_enabled' => false]);
        expect($service->accidentAvoidanceChance($pet->fresh(), '2026-10-07'))->toBe(0.0);
    });

    it('lowers the teething chewing chance with place training; chewing after a missed walk stays certain', function () {
        trParam(StageParamKey::ChewingChancePerDay, 1.0);
        trParam(StageParamKey::PlaceTrainingChewingReduction, 1.0);
        // 3 months = teething (S32).
        [, , $trained] = trFamily('2026-10-07 05:00:00', ['behaviour_events_enabled' => true], arrival: 3);
        [, , $untrained] = trFamily('2026-10-07 05:00:00', ['behaviour_events_enabled' => true], arrival: 3);
        trSkill($trained, TrainingCommand::Place, 100.0);
        $behaviour = app(BehaviourEventService::class);

        trAt('2026-10-08 10:00:00');
        expect($behaviour->chewingReasonOn($untrained->fresh(), '2026-10-08', now()))->toBe('teething')
            ->and($behaviour->chewingReasonOn($trained->fresh(), '2026-10-08', now()))->toBeNull()
            ->and(app(TrainingService::class)->chewingChanceFactor($trained->fresh(), '2026-10-08'))->toBe(0.0);

        // Missed walk on 08.10 (goal not reached, nothing excused) → chews on 09.10 anyway.
        DB::table('pet_daily_walks')->insert(['pet_id' => $trained->id, 'local_date' => '2026-10-08', 'steps' => 0, 'goal' => 3000,
            'achieved' => false, 'birth_day' => false, 'created_at' => now(), 'updated_at' => now()]);
        trAt('2026-10-09 10:00:00');
        expect($behaviour->chewingReasonOn($trained->fresh(), '2026-10-09', now()))->toBe('walk_missed');
    });
});

// ──────────────────────────────────────────────────────────────
//  Data provenance (breed_stage_params ← data.json)
// ──────────────────────────────────────────────────────────────

describe('data', function () {
    it('stores David\'s decisions as verified rows with their evidence and the rest as unverified proposals', function () {
        $data = json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);
        $row = fn (string $breed, StageParamKey $key) => BreedStageParam::where(['breed_slug' => $breed, 'stage' => 'all', 'key' => $key->value])->first();

        $collie = $row('border-collie', StageParamKey::TrainingLearningMultiplier);
        $mutt = $row('mutt', StageParamKey::TrainingLearningMultiplier);
        $variation = $row('mutt', StageParamKey::TrainingIndividualVariation);
        expect($collie->value)->toEqual(2.0)->and($collie->verified)->toBeTrue()->and($collie->source_id)->toBe('S34,S35')
            ->and($collie->notes)->toStartWith('Decision: potrdil David 2026-10-06.')
            ->and($mutt->value)->toEqual(1.0)->and($mutt->verified)->toBeTrue()
            ->and($variation->value)->toEqual(0.2)->and($variation->verified)->toBeTrue()->and($variation->source_id)->toBe('S42')
            ->and($row('border-collie', StageParamKey::TrainingIndividualVariation))->toBeNull()
            ->and($data['border_collie']['trainability']['learning_multiplier']['decision'])->toStartWith('potrdil David 2026-10-06')
            ->and($data['medium_mixed_breed']['trainability']['individual_variation']['decision'])->toStartWith('potrdil David 2026-10-06')
            ->and($data['general_by_size']['house_training']['learns_to_signal']['source_id'])->toBe('S47');

        // M5-R03b: David confirmed the three numbers on 2026-10-06 and the two effects on 2026-10-07.
        foreach ([StageParamKey::TrainingMinutesPerDay, StageParamKey::TrainingProgressPerSuccess, StageParamKey::TrainingDecayPerMissedDay] as $key) {
            foreach (['mutt', 'border-collie'] as $breed) {
                expect($row($breed, $key)->verified)->toBeTrue()
                    ->and($row($breed, $key)->notes)->toStartWith('Decision: potrdil David 2026-10-06.');
            }
        }
        foreach ([StageParamKey::PottyTrainingAccidentReduction, StageParamKey::PlaceTrainingChewingReduction] as $key) {
            foreach (['mutt', 'border-collie'] as $breed) {
                expect($row($breed, $key)->verified)->toBeTrue()
                    ->and($row($breed, $key)->notes)->toStartWith('Decision: potrdil David 2026-10-07.');
            }
        }
        expect($row('mutt', StageParamKey::TrainingMinutesPerDay)->value)->toBe($data['proposed_game_parameters']['training_minigame_minutes']['value'])
            ->and($row('mutt', StageParamKey::TrainingDecayPerMissedDay)->value)->toEqual($data['proposed_game_parameters']['training_decay_per_missed_day']['value'])
            ->and($row('mutt', StageParamKey::PottyTrainingAccidentReduction)->value)->toBe($data['proposed_game_parameters']['potty_training_accident_reduction']['value']);
    });
});

// ──────────────────────────────────────────────────────────────
//  Payload: child state, broadcast
// ──────────────────────────────────────────────────────────────

describe('payload', function () {
    it('shows the training block in the child state and a summary in the broadcast', function () {
        [, $child, $pet] = trFamily();
        trAt('2026-10-08 08:00:00');
        app('auth')->forgetGuards();
        actingAsRole($child);

        getJson('/api/child/pet')->assertOk()
            ->assertJsonPath('training.enabled', true)
            ->assertJsonPath('training.commands', [
                ['command' => 'sit', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'come', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'place', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'potty', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
            ])
            ->assertJsonPath('training.today_done', false)
            ->assertJsonPath('training.session', null)
            ->assertJsonPath('training.session_seconds', 50)
            ->assertJsonPath('training.daily_budget_seconds', 300)
            ->assertJsonPath('training.daily_budget_left_seconds', 300)
            ->assertJsonPath('training.can_start', true);

        trSkill($pet, TrainingCommand::Come, 60.4);
        $payload = PetUpdated::payloadFor($pet->fresh(), 'trained_pet');
        expect($payload['training'])->toBe([
            'enabled' => true,
            'commands' => [
                ['command' => 'sit', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'come', 'progress' => 60, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'place', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
                ['command' => 'potty', 'progress' => 0, 'learned' => false, 'last_practised_at' => null],
            ],
            'today_done' => false,
            'session_active' => false,
        ]);
    });
});
