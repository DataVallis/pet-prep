<?php

use App\Enums\TrainingCommand;
use App\Models\Pet;
use App\Models\PetTrainingSkill;
use App\Models\User;
use App\Services\PetDecayService;
use App\Services\PetTimezoneService;
use App\Services\RoutineLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Bug 2026-11-11 (David): a dog trained to 100 % on every command drops to 98 %
| the next day although the child trains every day. Daily training must
| keep every command where it is (GAMES_SPEC: −2 only for a day whose
| training routine was missed). Pet zone ≠ family zone included (#891).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    seedLifeStageData();
});

function tdmAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

/** @return array{0: User, 1: Pet} */
function tdmFamily(string $bornUtc, bool $frenchie = false): array
{
    tdmAt($bornUtc);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Maja']);
    $pet = ($frenchie ? Pet::factory()->frenchBulldog() : Pet::factory()->mutt())->create([
        'user_id' => $child->id,
        'born_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_decay_at' => Carbon::parse($bornUtc, 'UTC'),
        'last_step_reset_at' => Carbon::parse($bornUtc, 'UTC'),
        'arrival_age_months' => 2,
        'training_enabled' => true,
    ]);
    disableHygieneEvents($pet);

    return [$child, $pet->fresh()];
}

/** Start at $utc, praise every obey, finish at the scheduled end (a full session). */
function tdmSession(User $child, string $utc, string $command = 'sit', ?int $rules = null): TestResponse
{
    tdmAt($utc);
    app('auth')->forgetGuards();
    actingAsRole($child);
    $session = postJson('/api/child/pet/training/start', ['command' => $command] + ($rules === null ? [] : ['rules' => $rules]))->assertOk()->json('session');
    $taps = [];
    foreach ($session['trials'] as $trial) {
        if ($trial['obeys']) {
            $taps[] = $trial['obey_at_ms'] + 400 + 30 * count($taps);
        }
    }
    Carbon::setTestNow(Carbon::parse($session['ends_at'])->utc());
    app('auth')->forgetGuards();
    actingAsRole($child);

    return postJson('/api/child/pet/training/finish', ['session_id' => $session['id'], 'taps' => $taps])->assertOk();
}

/** Ticks every $minutes from $fromUtc (exclusive) to $toUtc. */
function tdmRun(Pet $pet, string $fromUtc, string $toUtc, int $minutes = 30): void
{
    for ($t = Carbon::parse($fromUtc, 'UTC')->addMinutes($minutes); $t->lessThanOrEqualTo(Carbon::parse($toUtc, 'UTC')); $t->addMinutes($minutes)) {
        tdmAt($t->toDateTimeString());
        app(PetDecayService::class)->processPetDecay($pet->fresh());
        app(RoutineLedgerService::class)->closeDueDays(); // the tick's routine stage (stored rows)
    }
}

/** @return array<string, float> */
function tdmAll(Pet $pet): array
{
    return PetTrainingSkill::where('pet_id', $pet->id)->orderBy('command')->pluck('progress', 'command')->map(fn ($v) => (float) $v)->all();
}

dataset('zones', [
    'family zone' => [null],
    'pet in Lisbon, family in Ljubljana' => ['Europe/Lisbon'],
]);

it('keeps a mastered dog at 100 % on days the child trains', function (?string $petZone, string $localTime) {
    [$child, $pet] = tdmFamily('2026-11-01 08:00:00');
    withoutQuietHours($pet);
    if ($petZone !== null) {
        Pet::whereKey($pet->id)->update(['timezone' => $petZone]);
    }
    foreach (TrainingCommand::cases() as $command) {
        PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command->value], ['progress' => 100.0]);
    }
    $pet = $pet->fresh();
    $zone = $pet->effectiveTimezone();

    $cursor = '2026-11-01 08:00:00';
    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
        $at = Carbon::parse($day.' '.$localTime, $zone)->utc()->toDateTimeString();
        tdmRun($pet, $cursor, $at);
        tdmSession($child, $at);
        $cursor = Carbon::now()->utc()->toDateTimeString();
    }
    tdmRun($pet, $cursor, '2026-11-06 12:00:00');

    expect(tdmAll($pet))->each->toBe(100.0);
})->with('zones')->with([
    'morning' => ['08:00:00'],
    'evening' => ['22:30:00'],
    'just before midnight' => ['23:45:00'],
]);

it('keeps a mastered French Bulldog puppy at 100 % with quiet hours, rules v2 and four sessions a day', function (?string $petZone) {
    [$child, $pet] = tdmFamily('2026-11-01 08:00:00', frenchie: true);
    if ($petZone !== null) {
        Pet::whereKey($pet->id)->update(['timezone' => $petZone]);
    }
    foreach (TrainingCommand::cases() as $command) {
        PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command->value], ['progress' => 100.0]);
    }
    $pet = $pet->fresh();
    $zone = $pet->effectiveTimezone();

    $cursor = '2026-11-01 08:00:00';
    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
        $at = Carbon::parse($day.' 17:00:00', $zone)->utc();
        tdmRun($pet, $cursor, $at->toDateTimeString());
        foreach (TrainingCommand::cases() as $i => $command) {
            tdmSession($child, $at->copy()->addMinutes(3 * $i)->toDateTimeString(), $command->value, 2);
        }
        $cursor = Carbon::now()->utc()->toDateTimeString();
    }
    tdmRun($pet, $cursor, '2026-11-06 12:00:00');

    expect(tdmAll($pet))->each->toBe(100.0);
})->with('zones');

it('keeps a mastered dog at 100 % across a pet zone move to Lisbon', function (string $moveUtc, string $localTime) {
    [$child, $pet] = tdmFamily('2026-11-01 08:00:00');
    withoutQuietHours($pet);
    foreach (TrainingCommand::cases() as $command) {
        PetTrainingSkill::updateOrCreate(['pet_id' => $pet->id, 'command' => $command->value], ['progress' => 100.0]);
    }
    $parent = User::findOrFail($child->parent_id);

    $cursor = '2026-11-01 08:00:00';
    $moved = false;
    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $day) {
        $at = Carbon::parse($day.' '.$localTime, $pet->fresh()->effectiveTimezone())->utc();
        if (! $moved && $at->greaterThan(Carbon::parse($moveUtc, 'UTC'))) {
            tdmRun($pet, $cursor, $moveUtc, 5);
            app(PetTimezoneService::class)->change($parent, $pet->fresh(), 'Europe/Lisbon');
            $moved = true;
            $cursor = $moveUtc;
            $at = Carbon::parse($day.' '.$localTime, 'Europe/Lisbon')->utc();
        }
        tdmRun($pet, $cursor, $at->toDateTimeString(), 5);
        tdmSession($child, $at->toDateTimeString());
        $cursor = Carbon::now()->utc()->toDateTimeString();
    }
    tdmRun($pet, $cursor, '2026-11-06 12:00:00', 5);

    expect($pet->fresh()->effectiveTimezone())->toBe('Europe/Lisbon')
        ->and(tdmAll($pet))->each->toBe(100.0);
})->with([
    'move at noon, train late evening' => ['2026-11-03 12:00:00', '23:30:00'],
    'move just after Ljubljana midnight' => ['2026-11-02 23:10:00', '08:00:00'],
    'move late evening after training' => ['2026-11-03 22:40:00', '22:30:00'],
]);
