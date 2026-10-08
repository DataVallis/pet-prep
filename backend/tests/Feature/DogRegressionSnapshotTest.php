<?php

use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetDailyRoutine;
use App\Models\PetDailyWalk;
use App\Models\PetHygieneEvent;
use App\Models\PetTrainingSession;
use App\Models\PetTrainingSkill;
use App\Models\PushNotification;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\RoutineLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Dog regression snapshot (M5-R06_PLAN §3, M5-R06-04)
|--------------------------------------------------------------------------
| Two and a half family-local days (incl. the DST change on 25 Oct) of two dogs — a
| profiled mutt puppy (behaviour events + training) and a legacy-profile
| Border Collie — driven through the real decay tick (hourly) and the child
| API (feed, water, steps, clean, take-out, training). At fixed checkpoints
| the whole observable state is captured: precise metrics and clocks, walk
| rows, hygiene events, activities, routines, Care Score / light, push rows,
| training progress, the child state JSON and every action's HTTP answer.
|
| The fixture `tests/Fixtures/dog_regression_snapshot.json` was recorded on
| `main` BEFORE the cat rules (M5-R06-04) touched the shared services. Later
| changes may only ADD keys to payloads (the comparison ignores keys the
| fixture does not have); every recorded value must stay equal.
|
| Re-record ONLY for an intended dog rule change: PETPREP_WRITE_SNAPSHOT=1.
| Randomness is kept out: random poops / chewing are switched off (the
| puppy's bladder clock is deterministic), the mutt challenge shows as free
| and the collie is legacy (no play invitations), training finishes without
| taps (no progress, the routine still counts).
*/

const DRS_FIXTURE = __DIR__.'/../Fixtures/dog_regression_snapshot.json';

beforeEach(function () {
    seedLifeStageData();
    config(['push.enabled' => true]);
    Queue::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Keys whose values are ids / signed URLs that change between runs. */
function drsVolatile(): array
{
    return ['id', 'pet_id', 'user_id', 'actor_user_id', 'completed_by', 'media', 'current_video_url',
        'reference_image_url', 'public_id', 'idempotency_key', 'recipients'];
}

function drsScrub(mixed $value): mixed
{
    if (! is_array($value)) {
        return is_float($value) ? round($value, 6) : $value;
    }
    $out = [];
    foreach ($value as $k => $v) {
        if (is_string($k) && in_array($k, drsVolatile(), true)) {
            continue;
        }
        $out[$k] = drsScrub($v);
    }

    return $out;
}

/** Every key the expected (recorded) array has must exist in $actual with an equal value; extra keys are fine. */
function drsAssertSubset(mixed $expected, mixed $actual, string $path = '$'): void
{
    if (is_array($expected) && ! array_is_list($expected)) {
        expect(is_array($actual))->toBeTrue("{$path} is no longer an object");
        foreach ($expected as $k => $v) {
            expect(array_key_exists($k, $actual))->toBeTrue("{$path}.{$k} disappeared");
            drsAssertSubset($v, $actual[$k], "{$path}.{$k}");
        }

        return;
    }
    if (is_array($expected)) {
        expect(is_array($actual) && array_is_list($actual))->toBeTrue("{$path} is no longer a list");
        expect(count($actual))->toBe(count($expected), "{$path}: list length changed");
        foreach ($expected as $i => $v) {
            drsAssertSubset($v, $actual[$i], "{$path}[{$i}]");
        }

        return;
    }
    expect($actual)->toBe($expected, "{$path} changed");
}

function drsAt(string $localTime): void
{
    Carbon::setTestNow(Carbon::parse($localTime, 'Europe/Ljubljana')->utc());
}

/** @return array<string, mixed> */
function drsPetState(Pet $pet, User $child): array
{
    $pet = $pet->fresh();
    $iso = fn ($at) => $at?->copy()->utc()->toIso8601String();
    $tz = 'Europe/Ljubljana';
    $now = now();
    $from = '2026-10-23';
    $to = $pet->localDate($now);

    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$pet]), $from, $to, $now)[$pet->id];
    $scores = app(CareScoreService::class);
    $board = $scores->board(Pet::whereKey($pet->id)->get(), $tz);

    test()->actingAs($child, 'sanctum');
    $childState = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return drsScrub([
        'pet' => [
            'hunger' => (float) $pet->hunger_level,
            'thirst' => (float) $pet->thirst_level,
            'energy' => (float) $pet->energy_level,
            'hygiene' => (float) $pet->hygiene_level,
            'pet_state' => $pet->pet_state?->value,
            'escalation_level' => (int) $pet->escalation_level,
            'daily_step_count' => (int) $pet->daily_step_count,
            'illness_until' => $iso($pet->illness_until),
            'walk_illness_due_at' => $iso($pet->walk_illness_due_at),
            'hunger_zero_since' => $iso($pet->hunger_zero_since),
            'thirst_zero_since' => $iso($pet->thirst_zero_since),
            'energy_zero_since' => $iso($pet->energy_zero_since),
            'hygiene_zero_since' => $iso($pet->hygiene_zero_since),
            'life_stage' => $pet->life_stage?->value,
            'is_game_over' => (bool) $pet->is_game_over,
            'last_decay_at' => $iso($pet->last_decay_at),
            'last_step_reset_at' => $iso($pet->last_step_reset_at),
            'potty_clock_started_at' => $iso($pet->potty_clock_started_at),
            'routines_closed_through' => $pet->routines_closed_through,
        ],
        'walks' => PetDailyWalk::where('pet_id', $pet->id)->orderBy('local_date')->get()
            ->map(fn ($w) => [substr((string) $w->getRawOriginal('local_date'), 0, 10), (int) $w->steps, (int) $w->goal, (bool) $w->achieved, (bool) $w->birth_day, $iso($w->illness_due_at)])->all(),
        'hygiene_events' => PetHygieneEvent::where('pet_id', $pet->id)->orderBy('scheduled_at')->orderBy('id')->get()
            ->map(fn ($e) => [$e->kind?->value ?? (string) $e->kind, $iso($e->scheduled_at), $e->status?->value ?? (string) $e->status, $iso($e->cleaned_at)])->all(),
        'activities' => ActivityLog::where('pet_id', $pet->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($a) => [$a->activity_type instanceof BackedEnum ? $a->activity_type->value : (string) $a->activity_type, $iso($a->created_at), $a->value, $a->actor_user_id === null ? null : 'child'])->all(),
        'routines' => array_map(fn ($r) => [$r->localDate, $r->type->value, $r->slot, $r->status->value, $iso($r->opensAt), $iso($r->dueAt), $iso($r->doneAt), $r->actorUserId === null ? null : 'child', $r->steps, $r->goal, $r->eventKind?->value], $routines),
        'stored_routines' => PetDailyRoutine::where('pet_id', $pet->id)->orderBy('local_date')->orderBy('routine_type')->orderBy('slot')->get()
            ->map(fn ($r) => [substr((string) $r->getRawOriginal('local_date'), 0, 10), $r->routine_type->value, $r->slot, $r->status->value])->all(),
        'pet_summary' => $scores->petSummary($board, $pet),
        'child_summary' => $scores->childSummary($board, $child->id, $pet),
        'pushes' => PushNotification::where('pet_id', $pet->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($p) => [$p->type->value, $p->metric, $p->status, $p->suppressed_reason, $iso($p->send_after), $iso($p->created_at)])->all(),
        'training' => PetTrainingSkill::where('pet_id', $pet->id)->orderBy('command')->get()
            ->map(fn ($s) => [$s->command->value, (float) $s->progress, (int) $s->sessions_completed])->all(),
        'child_state' => $childState,
    ]);
}

it('keeps 2.5 days of two dogs (profiled puppy + legacy collie) identical to the recorded snapshot', function () {
    drsAt('2026-10-24 08:00');

    // Family A: profiled mutt puppy, behaviour + training, quiet hours 21–07 + school 08–13.
    $parentA = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $childA = User::factory()->child()->create(['parent_id' => $parentA->id, 'name' => 'Ana']);
    setQuietHours(['parent_id' => $parentA->id, 'school_start' => '08:00', 'school_end' => '13:00',
        'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $puppy = Pet::factory()->mutt()->create([
        'user_id' => $childA->id, 'born_at' => now(), 'arrival_age_months' => 2, 'origin' => 'bought',
        'behaviour_events_enabled' => true, 'training_enabled' => true,
    ]);
    // No random poops / chewing; the bladder clock (deterministic) runs.
    Pet::whereKey($puppy->id)->update(['hygiene_scheduled_through' => '2999-12-31', 'behaviour_scheduled_through' => '2999-12-31']);

    // Family B: legacy-profile Border Collie (pre-M5 rules), quiet hours 22–06 only,
    // two planted poops (one cleaned in time, one left until the ladder runs).
    $parentB = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $childB = User::factory()->child()->create(['parent_id' => $parentB->id, 'name' => 'Bor']);
    setQuietHours(['parent_id' => $parentB->id, 'bedtime_start' => '22:00', 'bedtime_end' => '06:00']);
    $collie = disableHygieneEvents(Pet::factory()->borderCollie()->create(['user_id' => $childB->id, 'born_at' => now()]));
    foreach (['2026-10-24 12:00', '2026-10-25 15:00'] as $poop) {
        $at = Carbon::parse($poop, 'Europe/Ljubljana')->utc();
        PetHygieneEvent::create(['pet_id' => $collie->id, 'local_date' => $collie->localDate($at), 'scheduled_at' => $at, 'status' => 'pending']);
    }

    $answers = [];
    $act = function (string $label, User $child, string $method, string $uri, array $body = []) use (&$answers): array {
        test()->actingAs($child, 'sanctum');
        $res = test()->json($method, $uri, $body);
        app('auth')->forgetGuards();
        $json = $res->json();
        $answers[] = [$label, now()->copy()->utc()->toIso8601String(), $res->status(), $json['status'] ?? null, $json['reason'] ?? null, $json['next_allowed_at'] ?? null];

        return $json ?? [];
    };
    $steps = fn (string $label, User $child, int $n) => $act($label, $child, 'POST', '/api/child/pet/steps',
        ['steps_today' => $n, 'source' => 'healthkit', 'recorded_at' => now()->toIso8601String()]);
    $train = function (string $label, User $child, Pet $pet, string $command) use ($act): void {
        $act("{$label} training start", $child, 'POST', '/api/child/pet/training/start', ['command' => $command]);
        Carbon::setTestNow(now()->addSeconds(60));
        $id = PetTrainingSession::where('pet_id', $pet->id)->latest('id')->value('public_id');
        $act("{$label} training finish", $child, 'POST', '/api/child/pet/training/finish', ['session_id' => $id, 'taps' => []]);
    };

    // Chronological plan: [local time, action]. The tick runs every 5 minutes.
    $plan = [
        ['2026-10-24 09:30', fn () => $act('A water', $childA, 'POST', '/api/child/pet/water')],
        ['2026-10-24 09:35', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],
        ['2026-10-24 10:00', fn () => $act('A take-out', $childA, 'POST', '/api/child/pet/take-out')],
        ['2026-10-24 11:30', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],
        ['2026-10-24 12:30', fn () => $act('B clean', $childB, 'POST', '/api/child/pet/clean')],
        ['2026-10-24 14:00', fn () => $act('A water', $childA, 'POST', '/api/child/pet/water')],
        ['2026-10-24 14:10', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],
        ['2026-10-24 15:30', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],
        ['2026-10-24 16:00', fn () => $train('A', $childA, $puppy, 'sit')],
        ['2026-10-24 17:30', fn () => $act('B feed', $childB, 'POST', '/api/child/pet/feed')],
        ['2026-10-24 18:00', fn () => $steps('B steps', $childB, 4000)],
        ['2026-10-24 18:30', fn () => $act('A water', $childA, 'POST', '/api/child/pet/water')],
        ['2026-10-24 18:40', fn () => $act('A clean', $childA, 'POST', '/api/child/pet/clean')],
        ['2026-10-24 19:30', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],
        ['2026-10-24 20:00', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],

        // 25 Oct: DST ends at 03:00 local (the day has 25 h).
        ['2026-10-25 07:30', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],
        ['2026-10-25 07:35', fn () => $act('A water', $childA, 'POST', '/api/child/pet/water')],
        ['2026-10-25 07:40', fn () => $act('B feed', $childB, 'POST', '/api/child/pet/feed')],
        ['2026-10-25 07:45', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],
        ['2026-10-25 08:30', fn () => $act('A take-out', $childA, 'POST', '/api/child/pet/take-out')],
        ['2026-10-25 10:00', fn () => $steps('A steps', $childA, 800)],
        ['2026-10-25 12:00', fn () => $act('A clean', $childA, 'POST', '/api/child/pet/clean')],
        ['2026-10-25 12:05', fn () => $act('A water', $childA, 'POST', '/api/child/pet/water')],
        ['2026-10-25 12:10', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],
        ['2026-10-25 13:30', fn () => $act('A take-out', $childA, 'POST', '/api/child/pet/take-out')],
        ['2026-10-25 17:00', fn () => $steps('A steps', $childA, 9000)],
        ['2026-10-25 17:10', fn () => $steps('B steps', $childB, 20000)],
        ['2026-10-25 17:20', fn () => $act('B feed', $childB, 'POST', '/api/child/pet/feed')],
        ['2026-10-25 18:00', fn () => $act('A clean', $childA, 'POST', '/api/child/pet/clean')],
        ['2026-10-25 18:10', fn () => $train('A', $childA, $puppy, 'come')],
        ['2026-10-25 18:20', fn () => $act('B water', $childB, 'POST', '/api/child/pet/water')],
        ['2026-10-25 19:30', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],

        // 26 Oct morning: nobody walked the collie enough yesterday? (it did) — puppy fed late.
        ['2026-10-26 07:30', fn () => $act('B feed', $childB, 'POST', '/api/child/pet/feed')],
        ['2026-10-26 08:10', fn () => $act('A feed', $childA, 'POST', '/api/child/pet/feed')],
    ];
    $checkpoints = ['2026-10-24 23:30', '2026-10-25 23:30', '2026-10-26 09:30'];

    $events = [];
    foreach ($plan as [$at, $fn]) {
        $events[] = [Carbon::parse($at, 'Europe/Ljubljana')->utc(), 1, $fn];
    }
    for ($t = Carbon::parse('2026-10-24 06:05', 'UTC'); $t->lessThan(Carbon::parse('2026-10-26 08:31', 'UTC')); $t->addMinutes(5)) {
        $events[] = [$t->copy(), 0, fn () => Artisan::call('pets:process-decay')];
    }
    $snapshot = ['answers' => &$answers, 'checkpoints' => []];
    foreach ($checkpoints as $cp) {
        $events[] = [Carbon::parse($cp, 'Europe/Ljubljana')->utc(), 2, function () use (&$snapshot, $cp, $puppy, $childA, $collie, $childB) {
            $snapshot['checkpoints'][$cp] = [
                'puppy' => drsPetState($puppy, $childA),
                'collie' => drsPetState($collie, $childB),
            ];
        }];
    }
    usort($events, fn ($a, $b) => [$a[0]->getTimestamp(), $a[1]] <=> [$b[0]->getTimestamp(), $b[1]]);

    foreach ($events as [$at, , $fn]) {
        Carbon::setTestNow($at);
        $fn();
    }

    $actual = json_decode(json_encode($snapshot), true);

    if (getenv('PETPREP_WRITE_SNAPSHOT') === '1') {
        @mkdir(dirname(DRS_FIXTURE), 0777, true);
        file_put_contents(DRS_FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->markTestSkipped('Snapshot written to '.DRS_FIXTURE);
    }

    expect(file_exists(DRS_FIXTURE))->toBeTrue('Record the fixture first: PETPREP_WRITE_SNAPSHOT=1');
    $expected = json_decode((string) file_get_contents(DRS_FIXTURE), true);

    // The scenario really exercised the rules it guards (not a vacuous snapshot).
    $last = $expected['checkpoints']['2026-10-26 09:30'];
    expect($last['puppy']['walks'])->not->toBeEmpty()
        ->and(collect($last['puppy']['hygiene_events'])->pluck(0)->contains('accident'))->toBeTrue()
        ->and(collect($last['puppy']['routines'])->pluck(1)->unique()->values()->all())->toContain('walk', 'training', 'feed', 'water', 'clean')
        ->and(collect($last['collie']['routines'])->pluck(1)->unique()->values()->all())->toContain('walk', 'feed', 'water', 'clean')
        ->and(collect($expected['answers'])->pluck(2)->unique()->values()->all())->toContain(200, 422);

    drsAssertSubset($expected, $actual);
});
