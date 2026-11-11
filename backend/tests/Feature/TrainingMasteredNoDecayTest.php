<?php

use App\Models\Pet;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\PetDecayService;
use App\Services\TrainingService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| David 2026-11-11: »Naučen ukaz ne pada, tudi če en dan ni vaje.« A learned
| command (displayed progress 100 — PRODUCT_SPEC §6, thresholds follow the
| displayed value, half up: 99.5 counts) never loses points on a day whose
| training routine was missed; every command below that still loses
| training_decay_per_missed_day (2) points per missed day.
|--------------------------------------------------------------------------
| Family in Europe/Ljubljana; quiet hours off; dog born 2026-11-01 (the
| birth day is never decayed).
*/

beforeEach(function () {
    seedLifeStageData();
});

function tmnAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** @return array{0: User, 1: Pet} */
function tmnFamily(string $bornUtc = '2026-11-01 08:00:00', ?string $petZone = null): array
{
    tmnAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
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
    withoutQuietHours($pet);
    if ($petZone !== null) {
        Pet::whereKey($pet->id)->update(['timezone' => $petZone]);
    }

    return [$child, $pet->fresh()];
}

/** @param  array<string, float>  $progress  command => progress */
function tmnSkills(Pet $pet, array $progress): void
{
    foreach ($progress as $command => $value) {
        PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command], ['progress' => $value]);
    }
}

/** @return array<string, float> */
function tmnProgress(Pet $pet): array
{
    return PetTrainingSkill::where('pet_id', $pet->id)->orderBy('command')->pluck('progress', 'command')->map(fn ($v) => (float) $v)->all();
}

function tmnTick(Pet $pet, string $utc): void
{
    tmnAt($utc);
    app(PetDecayService::class)->processPetDecay($pet->fresh());
}

/** One full session at $utc; returns [session, taps, finish response]. */
function tmnSession(User $child, string $utc, string $command = 'sit'): array
{
    tmnAt($utc);
    app('auth')->forgetGuards();
    actingAsRole($child);
    $session = postJson('/api/child/pet/training/start', ['command' => $command])->assertOk()->json('session');
    $taps = [];
    foreach ($session['trials'] as $trial) {
        if ($trial['obeys']) {
            $taps[] = $trial['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }
    Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());

    return [$session, $taps, tmnFinish($child, $session['id'], $taps)->assertOk()];
}

/** @param  list<int>  $taps */
function tmnFinish(User $child, string $sessionId, array $taps): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $sessionId, 'taps' => $taps]);
}

it('keeps a 100 % command at 100 after a missed day (tick after midnight)', function () {
    [, $pet] = tmnFamily();
    tmnSkills($pet, ['sit' => 100.0]);

    // 02.11 missed → decayed at the first tick of 03.11 (00:05 Ljubljana = 23:05 UTC).
    tmnTick($pet, '2026-11-02 23:05:00');

    expect(tmnProgress($pet))->toBe(['sit' => 100.0])
        ->and(substr((string) $pet->fresh()->training_decayed_through, 0, 10))->toBe('2026-11-02');
});

it('follows the displayed value: 99.6 and 99.5 show 100 and stay, 99.4 shows 99 and decays', function () {
    [, $pet] = tmnFamily();
    tmnSkills($pet, ['sit' => 99.6, 'come' => 99.5, 'place' => 99.4]);

    tmnTick($pet, '2026-11-02 23:05:00');

    expect(tmnProgress($pet))->toEqualWithDelta(['come' => 99.5, 'place' => 97.4, 'sit' => 99.6], 1e-9)
        ->and(PetTrainingSkill::isMasteredProgress(99.5))->toBeTrue()
        ->and(PetTrainingSkill::isMasteredProgress(99.4999))->toBeFalse();
});

it('decays only the commands below learned, and the app\'s »naučeno« matches the exemption', function () {
    [$child, $pet] = tmnFamily();
    tmnSkills($pet, ['sit' => 100.0, 'come' => 50.0, 'place' => 99.5, 'potty' => 1.0]);

    tmnTick($pet, '2026-11-02 23:05:00');

    expect(tmnProgress($pet))->toEqualWithDelta(['come' => 48.0, 'place' => 99.5, 'potty' => 0.0, 'sit' => 100.0], 1e-9);

    app('auth')->forgetGuards();
    actingAsRole($child);
    $commands = collect(getJson('/api/child/pet')->assertOk()->json('training.commands'))->keyBy('command');
    expect($commands['sit']['learned'])->toBeTrue()
        ->and($commands['place']['learned'])->toBeTrue()
        ->and($commands['place']['progress'])->toBe(100)
        ->and($commands['come']['learned'])->toBeFalse()
        ->and($commands['come']['progress'])->toBe(48);
});

it('catches up several missed days at once (capped at MAX_DECAY_DAYS): learned stays, the rest decays per day', function () {
    [, $pet] = tmnFamily('2026-10-20 08:00:00');
    tmnSkills($pet, ['sit' => 100.0, 'come' => 50.0, 'place' => 99.4]);

    // Nothing ran since the birth day; on 01.11 (06:00 local) the missed days are
    // 21.10 … 31.10, but at most MAX_DECAY_DAYS (7) are applied.
    tmnAt('2026-11-01 05:00:00');
    $applied = app(TrainingService::class)->applyDecay($fresh = $pet->fresh(), now());
    $fresh->saveQuietly();

    expect($applied)->toBe(TrainingService::MAX_DECAY_DAYS)
        ->and(tmnProgress($pet))->toEqualWithDelta([
            'come' => 50.0 - 2.0 * TrainingService::MAX_DECAY_DAYS,
            'place' => 99.4 - 2.0 * TrainingService::MAX_DECAY_DAYS,
            'sit' => 100.0,
        ], 1e-9);
});

it('uses the pet\'s own zone for the missed day (pet in Lisbon, family in Ljubljana)', function () {
    [, $pet] = tmnFamily('2026-11-01 08:00:00', 'Europe/Lisbon');
    tmnSkills($pet, ['sit' => 100.0, 'come' => 60.0]);

    // 23:30 UTC on 02.11 = 00:30 03.11 in Ljubljana, but 23:30 02.11 in Lisbon: no day closed yet.
    tmnTick($pet, '2026-11-02 23:30:00');
    expect(tmnProgress($pet))->toEqualWithDelta(['come' => 60.0, 'sit' => 100.0], 1e-9);

    // 00:30 03.11 in Lisbon: 02.11 missed → only the unlearned command loses 2.
    tmnTick($pet, '2026-11-03 00:30:00');
    expect(tmnProgress($pet))->toEqualWithDelta(['come' => 58.0, 'sit' => 100.0], 1e-9)
        ->and(substr((string) $pet->fresh()->training_decayed_through, 0, 10))->toBe('2026-11-02');
});

it('a repeated finish that catches up a missed day keeps the decay pointer (no double decay)', function () {
    [$child, $pet] = tmnFamily();
    tmnSkills($pet, ['come' => 50.0, 'place' => 100.0]);

    // 02.11 trained (sit), 03.11 missed; on 04.11 the app retries the old finish before any tick ran.
    [$session, $taps] = tmnSession($child, '2026-11-02 08:00:00');
    tmnAt('2026-11-04 06:00:00');
    tmnFinish($child, $session['id'], $taps)->assertOk()->assertJsonPath('status', 'unchanged');

    expect(substr((string) $pet->fresh()->training_decayed_through, 0, 10))->toBe('2026-11-03')
        ->and(tmnProgress($pet)['come'])->toEqualWithDelta(48.0, 1e-9)
        ->and(tmnProgress($pet)['place'])->toBe(100.0);

    // The next tick the same day must not decay 03.11 again.
    tmnTick($pet, '2026-11-04 06:05:00');
    expect(tmnProgress($pet)['come'])->toEqualWithDelta(48.0, 1e-9);
});
