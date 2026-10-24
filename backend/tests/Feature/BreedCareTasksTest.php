<?php

use App\Enums\BreedType;
use App\Enums\CareTask;
use App\Enums\RoutineStatus;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\RoutineLedgerService;
use App\Services\WalkHeatService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R14 / R15 / R18 / R19 / R20 — breed care tasks (David 2026-10-24)
|--------------------------------------------------------------------------
| Child tasks (teeth, folds, ears, nails) are mini-games on the grooming model:
| a frequency ONLY where the breed's source states one, at most one per day,
| `times` per program period, never while the pet sleeps, ledger slots with a
| fair share per child, missed slots reported — no illness. Parent reminders
| (groomer visit, bath) are confirmed by the parent and never scored. The heat
| warning: flat-faced breeds, summer (hemisphere of the family timezone),
| 11:00–16:00 family time — no weather data, no penalty.
| Europe/Ljubljana, quiet hours 21–07 (DST on 25 Oct 2026).
*/

afterEach(function () {
    Carbon::setTestNow();
});

function ctAt(string $local, string $tz = 'Europe/Ljubljana'): void
{
    Carbon::setTestNow(Carbon::parse($local, $tz)->utc());
}

/**
 * A family with a profiled dog of $breed born at $bornLocal.
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function ctFamily(BreedType $breed, bool $enabled = true, string $bornLocal = '2026-10-20 08:00', string $tz = 'Europe/Ljubljana'): array
{
    seedLifeStageData();
    ctAt($bornLocal, $tz);
    $parent = User::factory()->parent()->create(['timezone' => $tz]);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $dog = disableHygieneEvents(Pet::factory()->create([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
        'care_tasks_enabled' => $enabled,
        'plan' => 'challenge',
        'challenge_paid_at' => now(),
        'challenge_paid_source' => 'purchase',
    ]));

    return [$parent, $child, $dog->fresh()];
}

function ctSibling(User $parent, Pet $pet, string $name = 'Tim'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

    return $child;
}

function ctPost(User $user, string $uri, array $body = [])
{
    test()->actingAs($user, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

function ctGet(User $user, string $uri): array
{
    test()->actingAs($user, 'sanctum');
    $json = test()->getJson($uri)->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

/** Human-like strokes / taps over the whole session. */
function ctStrokes(int $durationMs, int $count): array
{
    $strokes = [];
    for ($i = 0; $i < $count; $i++) {
        $strokes[] = ['t' => min($durationMs, 300 + intdiv($i * ($durationMs - 600), max(1, $count - 1)) + ($i * 137) % 250)];
    }

    return $strokes;
}

/** Start + finish a care task with good input; returns the finish response. */
function ctDo(User $child, string $task, ?int $count = null)
{
    $session = ctPost($child, '/api/child/pet/care-task/start', ['task' => $task])->assertOk()->json('session');
    expect($session['task'])->toBe($task);
    Carbon::setTestNow(now()->addMilliseconds($session['duration_ms']));

    return ctPost($child, '/api/child/pet/care-task/finish', [
        'task' => $task,
        'session_id' => $session['id'],
        'strokes' => ctStrokes($session['duration_ms'], $count ?? $session['min_strokes'] + 4),
    ]);
}

/** @return list<array{0: string, 1: int, 2: string, 3: int|null}> */
function ctRoutines(Pet $dog, string $date, RoutineType $type): array
{
    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$dog->fresh()]), $date, $date)[$dog->id] ?? [];

    return array_values(array_map(
        fn ($r) => [$r->localDate, $r->slot, $r->status->value, $r->actorUserId],
        array_filter($routines, fn ($r) => $r->type === $type),
    ));
}

function ctData(string $path): mixed
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);
    $node = $data;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($node) || ! array_key_exists($segment, $node)) {
            return null;
        }
        $node = $node[$segment];
    }

    return $node;
}

describe('frequencies only where the source states one (data.json)', function () {
    it('seeds the sourced care tasks and nothing for needs without a number', function () {
        $value = function (BreedType $breed, StageParamKey $key): mixed {
            $row = collect(BreedStageParamsSeeder::rows())->first(fn ($r) => $r['breed_slug'] === $breed->slug() && $r['key'] === $key->value);

            return $row['value'] ?? null;
        };

        // Sourced numbers (David 2026-10-24 examples).
        expect($value(BreedType::YorkshireTerrier, StageParamKey::ToothBrushingFrequency))->toBe([7, 7])
            ->and($value(BreedType::Bulldog, StageParamKey::FoldCleaningFrequency))->toBe([7, 7])
            ->and($value(BreedType::Brittany, StageParamKey::NailTrimFrequency))->toBe([1, 28])
            ->and($value(BreedType::AmericanCockerSpaniel, StageParamKey::EarCleaningFrequency))->toBe([1, 7])
            ->and($value(BreedType::BelgianMalinois, StageParamKey::EarCleaningFrequency))->toBe([1, 28])
            ->and($value(BreedType::EnglishSpringerSpaniel, StageParamKey::GroomerVisitFrequency))->toBe([1, 84])
            ->and($value(BreedType::AmericanCockerSpaniel, StageParamKey::GroomerVisitFrequency))->toBe([1, 28])
            // "every four to six weeks" → the lower bound.
            ->and($value(BreedType::Pomeranian, StageParamKey::GroomerVisitFrequency))->toBe([1, 28])
            ->and($value(BreedType::Pug, StageParamKey::NoWalkInHeat))->toBeTrue();

        // A sourced need without a frequency → no task (the fact stays in the care guide / register).
        expect($value(BreedType::MiniatureSchnauzer, StageParamKey::ToothBrushingFrequency))->toBeNull()
            ->and($value(BreedType::ShihTzu, StageParamKey::ToothBrushingFrequency))->toBeNull()
            ->and($value(BreedType::Chihuahua, StageParamKey::ToothBrushingFrequency))->toBeNull()
            ->and($value(BreedType::Chihuahua, StageParamKey::EarCleaningFrequency))->toBeNull()
            ->and($value(BreedType::Chihuahua, StageParamKey::BathFrequency))->toBeNull()
            ->and($value(BreedType::BostonTerrier, StageParamKey::NailTrimFrequency))->toBeNull()
            ->and($value(BreedType::FrenchBulldog, StageParamKey::FoldCleaningFrequency))->toBeNull()
            ->and($value(BreedType::Boxer, StageParamKey::FoldCleaningFrequency))->toBeNull()
            // The Boxer's sources say nothing about heat.
            ->and($value(BreedType::Boxer, StageParamKey::NoWalkInHeat))->toBeNull()
            ->and($value(BreedType::Mutt, StageParamKey::ToothBrushingFrequency))->toBeNull();
    });

    it('traces every care row to its data.json entry (same source, verbatim quote)', function () {
        $rows = collect(BreedStageParamsSeeder::rows())->filter(fn ($r) => StageParamKey::from($r['key'])->isCareTaskFrequency() || $r['key'] === StageParamKey::NoWalkInHeat->value);
        expect($rows)->not->toBeEmpty();
        foreach ($rows as $row) {
            $entry = ctData($row['ref']);
            $label = "{$row['breed_slug']}.{$row['key']}";
            expect($entry)->not->toBeNull($label)
                ->and($entry['source_id'] ?? null)->toBe($row['source_id'], $label)
                ->and(($entry['quote'] ?? '').' '.($entry['notes'] ?? ''))->toContain($row['quote'])
                ->and($row['verified'])->toBeTrue()
                ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
                ->and(StageParamKey::from($row['key'])->validate($row['value']))->toBeNull($label);
        }
    });
});

describe('the gate (ClientFeature care_tasks → pets.care_tasks_enabled)', function () {
    it('enables care tasks only for a profiled dog when both apps declared `care_tasks`', function () {
        seedLifeStageData();
        $create = function (array $body, ?array $childFeatures): Pet {
            $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
            $child = app(ChildProfileService::class)->createChild($parent, 'Maja', null);
            app('auth')->forgetGuards();
            actingAsRole($parent);
            $pin = postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body))->assertOk();
            app('auth')->forgetGuards();
            test()->withHeaders(['Authorization' => '']);
            $login = postJson('/api/child/pin-login', array_merge(['pin' => $pin->json('pin'), 'device_name' => 'Tablet'], $childFeatures === null ? [] : ['features' => $childFeatures]))->assertSuccessful();

            return Pet::findOrFail($login->json('pet.id'));
        };
        $profile = ['origin' => 'bought', 'age_stage' => 'puppy'];

        expect($create([...$profile, 'features' => ['care_tasks']], ['care_tasks'])->care_tasks_enabled)->toBeTrue()
            ->and($create([...$profile, 'features' => ['care_tasks']], ['training'])->care_tasks_enabled)->toBeFalse()
            ->and($create([...$profile, 'features' => ['training']], ['care_tasks'])->care_tasks_enabled)->toBeFalse()
            ->and($create(['features' => ['care_tasks']], ['care_tasks'])->care_tasks_enabled)->toBeFalse();
    });

    it('gives a dog without the gate no care tasks, no reminders and no heat warning', function () {
        [$parent, $child] = ctFamily(BreedType::Pug, enabled: false, bornLocal: '2026-07-10 08:00');
        ctAt('2026-07-11 12:00');
        $state = ctGet($child, '/api/child/pet');
        expect($state['care_tasks'])->toBe([])
            ->and($state['walk_heat'])->toBeNull()
            ->and($state['care_guide']['care_tasks'])->toBe([]);
        ctPost($child, '/api/child/pet/care-task/start', ['task' => 'tooth_brushing'])->assertStatus(422)->assertJsonPath('reason', 'care_task_not_available');
    });
});

describe('the child mini-games', function () {
    it('brushes a Yorkshire Terrier\'s teeth once per day, seven per week, judged on the server', function () {
        [, $child, $dog] = ctFamily(BreedType::YorkshireTerrier);
        ctAt('2026-10-20 10:00');
        $task = collect(ctGet($child, '/api/child/pet')['care_tasks'])->firstWhere('task', 'tooth_brushing');
        expect($task)->toMatchArray(['times' => 7, 'period_days' => 7, 'done_this_period' => 0, 'can_start' => true, 'blocked_reason' => null]);
        // Only the sourced task: no folds, ears or nails for a Yorkie.
        expect(collect(ctGet($child, '/api/child/pet')['care_tasks'])->pluck('task')->all())->toBe(['tooth_brushing']);

        ctDo($child, 'tooth_brushing')->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('result.success', true);
        expect(ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'brushed_teeth')->value('actor_user_id'))->toBe($child->id);

        // Once per family-local day.
        ctPost($child, '/api/child/pet/care-task/start', ['task' => 'tooth_brushing'])->assertStatus(422)->assertJsonPath('reason', 'care_task_done_today');
        // Too few strokes → rejected, nothing counts.
        ctAt('2026-10-21 10:00');
        ctDo($child, 'tooth_brushing', 3)->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('result.reason', 'too_few_strokes');
        ctDo($child, 'tooth_brushing')->assertOk()->assertJsonPath('status', 'accepted');
        expect(collect(ctGet($child, '/api/child/pet')['care_tasks'])->firstWhere('task', 'tooth_brushing')['done_this_period'])->toBe(2);
    });

    it('refuses care while the pet sleeps and a session that would run into quiet hours', function () {
        [, $child] = ctFamily(BreedType::Pug);
        ctAt('2026-10-20 22:30');
        $response = ctPost($child, '/api/child/pet/care-task/start', ['task' => 'fold_cleaning'])->assertStatus(422)->assertJsonPath('reason', 'care_task_quiet_hours');
        expect(Carbon::parse($response->json('next_allowed_at'))->utc()->toIso8601String())
            ->toBe(Carbon::parse('2026-10-21 07:00', 'Europe/Ljubljana')->utc()->toIso8601String());
        $task = collect(ctGet($child, '/api/child/pet')['care_tasks'])->firstWhere('task', 'fold_cleaning');
        expect($task['can_start'])->toBeFalse()->and($task['blocked_reason'])->toBe('care_task_quiet_hours');
        // 30 s game + 60 s grace would touch 21:00.
        ctAt('2026-10-21 20:59');
        ctPost($child, '/api/child/pet/care-task/start', ['task' => 'tooth_brushing'])->assertStatus(422)->assertJsonPath('reason', 'care_task_quiet_hours');
        ctAt('2026-10-21 07:05');
        ctDo($child, 'fold_cleaning')->assertOk()->assertJsonPath('status', 'accepted');
    });

    it('trims nails with one tap per claw (12 s, 8 claws, one step)', function () {
        [, $child] = ctFamily(BreedType::Brittany);
        ctAt('2026-10-20 10:00');
        $session = ctPost($child, '/api/child/pet/care-task/start', ['task' => 'nail_trim'])->assertOk()->json('session');
        expect($session)->toMatchArray(['duration_ms' => 12000, 'min_strokes' => 8, 'segments' => 1]);
        Carbon::setTestNow(now()->addMilliseconds(9000)); // up to 3 s early is accepted
        ctPost($child, '/api/child/pet/care-task/finish', [
            'task' => 'nail_trim', 'session_id' => $session['id'], 'strokes' => array_map(fn ($t) => ['t' => $t], [700, 1300, 2150, 2800, 3700, 4400, 5150, 6000]),
        ])->assertOk()->assertJsonPath('status', 'accepted');
        // Once a month (28 program days): the next one waits for the next period.
        ctAt('2026-10-22 10:00');
        ctPost($child, '/api/child/pet/care-task/start', ['task' => 'nail_trim'])->assertStatus(422)->assertJsonPath('reason', 'care_task_period_done');
        // A finish for the wrong task is never accepted.
        ctPost($child, '/api/child/pet/care-task/start', ['task' => 'tooth_brushing'])->assertStatus(422)->assertJsonPath('reason', 'care_task_not_available');
    });

    it('makes ledger slots per period with a fair share per child; missed slots are reported, never an illness', function () {
        [$parent, $mia, $dog] = ctFamily(BreedType::YorkshireTerrier);
        $tim = ctSibling($parent, $dog);
        ctAt('2026-10-20 10:00');
        ctDo($mia, 'tooth_brushing')->assertJsonPath('status', 'accepted');
        ctAt('2026-10-21 10:00');
        ctDo($tim, 'tooth_brushing')->assertJsonPath('status', 'accepted');

        // The week (Tue 08:00 → Tue 08:00) ends on 27 Oct: 7 slots, 2 done (who did them), 5 missed.
        ctAt('2026-10-27 09:00');
        $slots = ctRoutines($dog, '2026-10-27', RoutineType::ToothBrushing);
        expect($slots)->toHaveCount(7)
            ->and(array_column($slots, 2))->toBe(['done', 'done', 'missed', 'missed', 'missed', 'missed', 'missed'])
            ->and(array_column($slots, 3))->toBe([$mia->id, $tim->id, null, null, null, null, null]);
        expect($dog->fresh()->illness_until)->toBeNull();

        actingAsRole($parent);
        $report = test()->getJson("/api/parent/children/{$mia->id}/report?days=30")->assertOk()->json();
        app('auth')->forgetGuards();
        expect($report['by_type']['tooth_brushing'])->toMatchArray(['expected' => 7, 'done' => 2, 'done_by_child' => 1, 'missed' => 5])
            ->and(collect($report['missed'])->where('type', 'tooth_brushing')->count())->toBe(5);
    });

    it('gives a 28-day task one slot per month, due at its end', function () {
        [, $child, $dog] = ctFamily(BreedType::Brittany);
        ctAt('2026-10-21 10:00');
        ctDo($child, 'nail_trim')->assertJsonPath('status', 'accepted');
        // 20 Oct 08:00 + 28 days = 17 Nov 08:00.
        ctAt('2026-11-17 09:00');
        expect(ctRoutines($dog, '2026-11-17', RoutineType::NailTrim))->toBe([['2026-11-17', 0, 'done', $child->id]])
            ->and(ctRoutines($dog, '2026-10-27', RoutineType::NailTrim))->toBe([]);
    });
});

describe('the parent reminders (groomer visit, bath)', function () {
    it('shows the groomer reminder to the parent, confirms it once per period and never scores it', function () {
        [$parent, $child, $dog] = ctFamily(BreedType::Pomeranian);
        ctAt('2026-10-21 10:00');
        $reminders = ctGet($parent, '/api/parent/dashboard')['family']['pets'][0]['care_reminders'];
        expect($reminders)->toHaveCount(1)
            ->and($reminders[0])->toMatchArray(['task' => 'groomer_visit', 'period_days' => 28, 'done' => false]);
        // The child has no groomer task (it is the parent's errand).
        expect(ctGet($child, '/api/child/pet')['care_tasks'])->toBe([]);

        $scoreBefore = app(CareScoreService::class)->board(collect([$dog->fresh()]), 'Europe/Ljubljana');
        ctPost($parent, "/api/parent/pets/{$dog->id}/care-reminders", ['task' => 'groomer_visit'])->assertOk()
            ->assertJsonPath('status', 'accepted')->assertJsonPath('care_reminders.0.done', true);
        ctPost($parent, "/api/parent/pets/{$dog->id}/care-reminders", ['task' => 'groomer_visit'])->assertOk()->assertJsonPath('status', 'unchanged');
        expect(ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'parent_groomer_visit')->value('actor_user_id'))->toBe($parent->id);
        // A reminder is never a routine: the board is unchanged.
        $scoreAfter = app(CareScoreService::class)->board(collect([$dog->fresh()]), 'Europe/Ljubljana');
        expect(count($scoreAfter['routines'][$dog->id] ?? []))->toBe(count($scoreBefore['routines'][$dog->id] ?? []));

        // No bath for a Pomeranian (no sourced frequency); a child cannot confirm.
        ctPost($parent, "/api/parent/pets/{$dog->id}/care-reminders", ['task' => 'bath'])->assertStatus(422)->assertJsonPath('reason', 'care_task_not_available');
        ctPost($child, "/api/parent/pets/{$dog->id}/care-reminders", ['task' => 'groomer_visit'])->assertForbidden();
        // Another family's pet looks missing.
        $stranger = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        ctPost($stranger, "/api/parent/pets/{$dog->id}/care-reminders", ['task' => 'groomer_visit'])->assertNotFound();

        // The report lists it (done), outside the scored types.
        actingAsRole($parent);
        $report = test()->getJson("/api/parent/children/{$child->id}/report?days=7")->assertOk()->json();
        app('auth')->forgetGuards();
        expect($report['care_reminders'])->toHaveCount(1)
            ->and($report['care_reminders'][0])->toMatchArray(['task' => 'groomer_visit', 'status' => 'done'])
            ->and($report['by_type'])->not->toHaveKey('groomer_visit');

        // The next period opens a new reminder.
        ctAt('2026-11-18 10:00');
        expect(ctGet($parent, '/api/parent/dashboard')['family']['pets'][0]['care_reminders'][0]['done'])->toBeFalse();
    });
});

describe('the summer heat warning (flat-faced breeds, no weather data)', function () {
    it('warns 11:00–16:00 family time in June–August for a Pug', function () {
        [, $child] = ctFamily(BreedType::Pug, bornLocal: '2026-07-10 08:00');
        ctAt('2026-07-11 12:00');
        $heat = ctGet($child, '/api/child/pet')['walk_heat'];
        expect($heat['active'])->toBeTrue()
            ->and(Carbon::parse($heat['starts_at'])->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('11:00')
            ->and(Carbon::parse($heat['ends_at'])->setTimezone('Europe/Ljubljana')->format('H:i'))->toBe('16:00');
        ctAt('2026-07-11 10:59');
        expect(ctGet($child, '/api/child/pet')['walk_heat']['active'])->toBeFalse();
        ctAt('2026-07-11 16:00');
        expect(ctGet($child, '/api/child/pet')['walk_heat']['active'])->toBeFalse();
        $guide = ctGet($child, '/api/child/pet')['care_guide']['walk_heat'];
        expect($guide)->toBe(['months' => [6, 7, 8], 'from' => '11:00', 'to' => '16:00']);
        // Not in autumn.
        ctAt('2026-10-15 12:00');
        expect(ctGet($child, '/api/child/pet')['walk_heat'])->toBeNull();
    });

    it('uses the southern summer for a family in Australia and no rule for a Boxer', function () {
        expect(WalkHeatService::isSouthern('Australia/Sydney'))->toBeTrue()
            ->and(WalkHeatService::isSouthern('Europe/Ljubljana'))->toBeFalse()
            ->and(WalkHeatService::isSouthern('UTC'))->toBeFalse()
            ->and(WalkHeatService::isSummerMonth(1, 'Australia/Sydney'))->toBeTrue()
            ->and(WalkHeatService::isSummerMonth(7, 'Australia/Sydney'))->toBeFalse()
            ->and(WalkHeatService::isSummerMonth(7, 'UTC'))->toBeTrue();

        [, $child] = ctFamily(BreedType::Bulldog, bornLocal: '2027-01-10 08:00', tz: 'Australia/Sydney');
        ctAt('2027-01-11 13:00', 'Australia/Sydney');
        expect(ctGet($child, '/api/child/pet')['walk_heat']['active'])->toBeTrue();

        [, $boxerChild] = ctFamily(BreedType::Boxer, bornLocal: '2026-07-10 08:00');
        ctAt('2026-07-11 12:00');
        expect(ctGet($boxerChild, '/api/child/pet')['walk_heat'])->toBeNull();
    });
});

describe('the care guide', function () {
    it('lists who does which sourced task and how often', function () {
        [, $child] = ctFamily(BreedType::AmericanCockerSpaniel);
        ctAt('2026-10-21 10:00');
        expect(ctGet($child, '/api/child/pet')['care_guide']['care_tasks'])->toBe([
            ['task' => 'ear_cleaning', 'actor' => 'child', 'times' => 1, 'period_days' => 7],
            ['task' => 'groomer_visit', 'actor' => 'parent', 'times' => 1, 'period_days' => 28],
        ]);
        expect(CareTask::GroomerVisit->routineType())->toBeNull()
            ->and(RoutineStatus::Missed->value)->toBe('missed');
    });
});
