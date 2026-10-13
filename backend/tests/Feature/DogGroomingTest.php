<?php

use App\Enums\BreedType;
use App\Enums\RoutineType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetContract;
use App\Models\User;
use App\Services\CareScoreService;
use App\Services\CatChoreService;
use App\Services\ChildProfileService;
use App\Services\FamilyService;
use App\Services\RoutineLedgerService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R13 — dog grooming per breed (David 2026-10-12, "kot pri mački")
|--------------------------------------------------------------------------
| A dog brushes exactly like a brushed cat (M5-R06-05): N sessions per
| program week from the breed's `grooming_sessions_per_week` row, at most
| one per family-local day, not in quiet hours, the 30 s stroke mini-game
| judged on the server, the `grooming` routine slots in the ledger (fair
| share per child), and the matted coat at ceil(N / 2) missed slots — no
| penalty beyond the missed routines; the next ~2× session resolves it.
| Gated per pet (`pets.grooming_enabled`, ClientFeature `dog_grooming`):
| an older app has no "Počeši" button, so its dog never gets the routine.
| Europe/Ljubljana, quiet hours 21–07, DST on 25 Oct 2026.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function dgAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a profiled dog of $breed born at $bornLocal (12 months = young dog).
 *
 * @return array{0: User, 1: User, 2: Pet}
 */
function dgFamily(BreedType $breed = BreedType::Rottweiler, bool $enabled = true, string $bornLocal = '2026-10-20 08:00'): array
{
    seedLifeStageData();
    dgAt($bornLocal);
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => 'Mia']);
    setQuietHours(['parent_id' => $parent->id, 'bedtime_start' => '21:00', 'bedtime_end' => '07:00']);
    $attributes = $breed === BreedType::Mutt ? [] : ['plan' => 'challenge', 'challenge_paid_at' => now(), 'challenge_paid_source' => 'purchase'];
    $dog = disableHygieneEvents(Pet::factory()->create(array_merge([
        'breed_type' => $breed->value,
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => 12,
        'origin' => 'adopted',
        'grooming_enabled' => $enabled,
    ], $attributes)));

    return [$parent, $child, $dog->fresh()];
}

function dgSibling(User $parent, Pet $pet, string $name = 'Tim'): User
{
    $child = User::factory()->child()->create(['parent_id' => $parent->id, 'name' => $name]);
    app(FamilyService::class)->addCaretaker($pet, $child, true);
    PetContract::create(['pet_id' => $pet->id, 'user_id' => $child->id, 'signature_format' => 'svg_path', 'signature' => 'M1 1 L2 2', 'signed_at' => now()]);

    return $child;
}

/** One tick at $local; food, water and the walk are not what these tests are about. */
function dgTick(string $local): void
{
    dgAt($local);
    // No walks in these tests: the missed-walk illness (M1-04) is not what they are about.
    DB::table('pets')->update(['hunger_level' => 100, 'thirst_level' => 100, 'hunger_zero_since' => null, 'thirst_zero_since' => null, 'walk_illness_due_at' => null]);
    Artisan::call('pets:process-decay');
}

function dgTickHourly(string $fromLocal, string $toLocal): void
{
    $end = Carbon::parse($toLocal, 'Europe/Ljubljana');
    for ($t = Carbon::parse($fromLocal, 'Europe/Ljubljana'); $t->lessThanOrEqualTo($end); $t->addHour()) {
        dgTick($t->format('Y-m-d H:i'));
    }
}

function dgPost(User $child, string $uri, array $body = [])
{
    test()->actingAs($child, 'sanctum');
    $response = test()->postJson($uri, $body);
    app('auth')->forgetGuards();

    return $response;
}

function dgState(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

/** Strokes of a child who really brushed: human gaps over the whole session. */
function dgStrokes(int $durationMs = 30000, int $count = 18): array
{
    $strokes = [];
    for ($i = 0; $i < $count; $i++) {
        $strokes[] = ['t' => min($durationMs, 300 + intdiv($i * ($durationMs - 600), max(1, $count - 1)) + ($i * 137) % 250)];
    }

    return $strokes;
}

/** Start + finish a brushing with good strokes ($strokes overrides); returns the finish response. */
function dgBrush(User $child, ?array $strokes = null)
{
    $session = dgPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session');
    Carbon::setTestNow(now()->addMilliseconds($session['duration_ms']));

    return dgPost($child, '/api/child/pet/grooming/finish', ['session_id' => $session['id'], 'strokes' => $strokes ?? dgStrokes($session['duration_ms'], $session['min_strokes'] + 6)]);
}

/** @return list<array{0: string, 1: string, 2: int, 3: string, 4: int|null}> */
function dgRoutines(Pet $dog, string $from, string $to): array
{
    $routines = app(RoutineLedgerService::class)->routinesFor(collect([$dog->fresh()]), $from, $to)[$dog->id] ?? [];

    return array_values(array_map(
        fn ($r) => [$r->localDate, $r->type->value, $r->slot, $r->status->value, $r->actorUserId],
        array_filter($routines, fn ($r) => $r->type === RoutineType::Grooming),
    ));
}

function dgData(string $path): mixed
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

/** Create a pet through generate-pin + pin-login with the given features (like TrainingTest). */
function dgCreateViaPin(array $body, ?array $childFeatures): Pet
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

describe('values per breed (runbook grooming rule, data.json)', function () {
    it('seeds one sourced row per brushed dog breed, traced to data.json; mutt and Border Collie have none', function () {
        $expected = [
            'labrador_retriever' => 1, 'golden_retriever' => 3, 'french_bulldog' => 1, 'german_shepherd' => 2,
            'cavalier_king_charles_spaniel' => 4, 'beagle' => 1, 'standard_poodle' => 7, 'dachshund' => 1,
            'australian_shepherd' => 2, 'havanese' => 7, 'west_highland_white_terrier' => 2, 'bernese_mountain_dog' => 2,
            'siberian_husky' => 2, 'rottweiler' => 1, 'bulldog' => 1, 'german_shorthaired_pointer' => 2,
            'yorkshire_terrier' => 7, 'cane_corso' => 1, 'pembroke_welsh_corgi' => 3, 'dobermann' => 1, 'boxer' => 1, 'miniature_schnauzer' => 3, 'shih_tzu' => 7, 'great_dane' => 1, 'pomeranian' => 3,
        ];
        // David 2026-10-12: his own decisions (no longer Claude's proposals — every row verified).
        $twice = ['german_shepherd', 'australian_shepherd', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'];
        $david = array_fill_keys($twice, BreedStageParamsSeeder::CONFIRMED_DAVID_2026_10_12_TWICE) + [
            'cavalier_king_charles_spaniel' => BreedStageParamsSeeder::CONFIRMED_DAVID_2026_10_12_CAVALIER,
            'german_shorthaired_pointer' => BreedStageParamsSeeder::CONFIRMED_DAVID_2026_10_12,
        ];
        $rows = collect(BreedStageParamsSeeder::rows())->where('key', StageParamKey::GroomingSessionsPerWeek->value);

        expect($rows->pluck('value', 'breed_slug')->all())
            ->toBe(collect($expected)->mapWithKeys(fn (int $n, string $breed) => [BreedType::from($breed)->slug() => $n])->all());

        foreach ($expected as $breed => $value) {
            $row = $rows->firstWhere('breed_slug', BreedType::from($breed)->slug());
            $entry = dgData("proposed_game_parameters.{$breed}.grooming");
            $decision = $david[$breed] ?? null;
            expect($row)->toMatchArray(['stage' => 'all', 'age_from_months' => 0, 'ref' => "proposed_game_parameters.{$breed}.grooming"])
                ->and($entry['value'])->toBe($value)
                ->and($row['source_id'])->toBe($entry['source_id'])
                ->and($row['quote'])->toBe($entry['quote'])
                ->and($row['verified'])->toBeTrue()
                ->and($row['decision'])->toBe($decision ?? BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
                // The breed runs of 2026-10-11 recorded David's value already; M5-R13 adds the rest.
                ->and((string) $entry['decision'])->toMatch($decision !== null ? '/^potrdil David 2026-10-12/' : '/^potrdil David 2026-10-1[12] /')
                ->and((string) $entry['decision'])->toContain('M5-R13')
                ->and((string) $entry['decision'])->not->toContain('čaka Davida');
            if (in_array($breed, $twice, true)) {
                expect($entry['decision'])->toStartWith('potrdil David 2026-10-12: 2× na teden')->and($value)->toBe(2);
            }
            if ($breed === 'cavalier_king_charles_spaniel') {
                // 4 is David's choice; the source (S108, its hedged "daily" quote) stays.
                expect($entry['decision'])->toStartWith('potrdil David 2026-10-12: 4× na teden')
                    ->and($entry['source_id'])->toBe('S108')
                    ->and($entry['alternatives'])->toBe(['pdsa_daily' => 7, 'rkc_more_than_once_a_week' => 2])
                    ->and($entry['notes'])->toContain("4 is David's choice, not the source's number");
            }
            // Every quote is the source's own words, recorded under the breed's suitability or notes.
            expect(str_contains((string) json_encode(dgData($breed), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), trim((string) json_encode($entry['quote'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '"')))->toBeTrue("{$breed}: quote not under {$breed}");
        }

        // No grooming statement in the research → no row, no routine.
        expect(dgData('border_collie.suitability'))->toBeNull()
            ->and(json_encode(dgData('medium_mixed_breed')))->not->toContain('groom')
            ->and(BreedStageParamsSeeder::dogGrooming(BreedType::Mutt))->toBeNull()
            ->and(BreedStageParamsSeeder::dogGrooming(BreedType::BorderCollie))->toBeNull();
        // Every seeded dog breed has a decision: a row or "no statement".
        expect(array_keys(BreedStageParamsSeeder::DOG_GROOMING))->toEqualCanonicalizing(array_values(array_diff(
            array_map(fn (BreedType $b) => $b->value, BreedStageParamsSeeder::DOG_BREEDS), ['mutt', 'border_collie'],
        )));
        // The matted coat rule of the cat (ceil(goal / 2)) — 1 → 1, 2 → 1, 3 → 2, 7 → 4.
        expect(array_map(fn (int $n) => CatChoreService::mattedAfterMissed($n), [1, 2, 3, 7]))->toBe([1, 1, 2, 4]);
    });
});

describe('the gate (ClientFeature dog_grooming → pets.grooming_enabled)', function () {
    it('enables dog grooming only for a profiled dog when both apps declared `dog_grooming`', function () {
        seedLifeStageData();
        $profile = ['origin' => 'bought', 'age_stage' => 'puppy'];

        expect(dgCreateViaPin([...$profile, 'features' => ['dog_grooming']], ['dog_grooming'])->grooming_enabled)->toBeTrue()
            ->and(dgCreateViaPin([...$profile, 'features' => ['dog_grooming']], ['training'])->grooming_enabled)->toBeFalse()
            ->and(dgCreateViaPin([...$profile, 'features' => ['training']], ['dog_grooming'])->grooming_enabled)->toBeFalse()
            ->and(dgCreateViaPin([...$profile, 'features' => ['dog_grooming']], null)->grooming_enabled)->toBeFalse()
            // No profile → legacy pet (pre-M5 rules), features ignored.
            ->and(dgCreateViaPin(['features' => ['dog_grooming']], ['dog_grooming'])->grooming_enabled)->toBeFalse();
    });

    it('gives an existing dog (and a dog of an older app) no grooming: no payload, 422, no routine', function () {
        [, $child, $dog] = dgFamily(BreedType::Rottweiler, enabled: false);
        dgAt('2026-10-21 10:00');
        expect(dgState($child)['grooming'])->toBeNull();
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_not_available');
        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(dgRoutines($dog, '2026-10-20', '2026-10-27'))->toBe([])
            ->and($dog->fresh()->coat_matted_at)->toBeNull()
            ->and($dog->fresh()->grooming_weeks_checked)->toBeNull();
    });

    it('gives a dog breed without a grooming row (mutt) no grooming even when enabled', function () {
        [, $child, $dog] = dgFamily(BreedType::Mutt);
        dgAt('2026-10-21 10:00');
        expect(dgState($child)['grooming'])->toBeNull();
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_not_available');
        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(dgRoutines($dog, '2026-10-20', '2026-10-27'))->toBe([])->and($dog->fresh()->coat_matted_at)->toBeNull();
    });
});

describe('brushing a dog (like the cat, M5-R06-05)', function () {
    it('Rottweiler 1× per week: the payload, the mini-game, one per week, the routine on the weekly birthday', function () {
        [, $child, $dog] = dgFamily(BreedType::Rottweiler);
        dgAt('2026-10-21 10:00');
        expect(dgState($child)['grooming'])->toMatchArray([
            'goal_per_week' => 1, 'done_this_week' => 0, 'week_started_at' => '2026-10-20T08:00:00+02:00',
            'week_ends_at' => '2026-10-27T08:00:00+01:00', 'matted' => false, 'session_seconds' => 30, 'can_start' => true,
            'blocked_reason' => null, 'session' => null,
        ]);

        // Too few strokes → rejected, nothing counts, try again at once (no penalty).
        dgBrush($child, [['t' => 500]])->assertOk()->assertJsonPath('status', 'rejected');
        dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.grooming.done_this_week', 1)
            ->assertJsonPath('state.grooming.blocked_reason', 'grooming_week_done')
            ->assertJsonPath('state.grooming.next_allowed_at', '2026-10-27T08:00:00+01:00');
        $groomed = ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'groomed_pet')->sole();
        expect((int) $groomed->value)->toBe(1)->and($groomed->actor_user_id)->toBe($child->id);

        dgAt('2026-10-22 10:00');
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_week_done');

        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(dgRoutines($dog, '2026-10-20', '2026-10-27'))->toBe([['2026-10-27', 'grooming', 0, 'done', $child->id]])
            ->and($dog->fresh()->coat_matted_at)->toBeNull();
    });

    it('one per family-local day and never in quiet hours (the dog sleeps)', function () {
        [, $child] = dgFamily(BreedType::PembrokeWelshCorgi);
        dgAt('2026-10-21 10:00');
        expect(dgState($child)['grooming']['goal_per_week'])->toBe(3);
        dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted')
            ->assertJsonPath('state.grooming.blocked_reason', 'grooming_done_today')
            ->assertJsonPath('state.grooming.next_allowed_at', '2026-10-22T07:00:00+02:00');
        dgAt('2026-10-21 15:00');
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_done_today');
        dgAt('2026-10-22 20:59');
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)
            ->assertJsonPath('reason', 'grooming_quiet_hours')->assertJsonPath('next_allowed_at', '2026-10-23T07:00:00+02:00');
    });

    it('Yorkshire Terrier every day: 7 slots per week, one per day', function () {
        [, $child, $dog] = dgFamily(BreedType::YorkshireTerrier);
        foreach (['2026-10-20 10:00', '2026-10-21 10:00', '2026-10-22 10:00', '2026-10-23 10:00', '2026-10-24 10:00', '2026-10-25 10:00', '2026-10-26 10:00'] as $i => $at) {
            dgAt($at);
            dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('state.grooming.done_this_week', $i + 1);
        }
        dgAt('2026-10-27 07:30');
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_week_done');
        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(collect(dgRoutines($dog, '2026-10-27', '2026-10-27'))->pluck(3)->all())->toBe(array_fill(0, 7, 'done'))
            ->and($dog->fresh()->coat_matted_at)->toBeNull();
    });

    it('mats the coat at ceil(3 / 2) = 2 missed (Corgi) — no penalty; the next ~2× brushing resolves it', function () {
        [, $child, $dog] = dgFamily(BreedType::PembrokeWelshCorgi);
        dgTick('2026-10-20 09:00');
        dgAt('2026-10-21 10:00');
        dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted');

        dgTickHourly('2026-10-26 22:00', '2026-10-27 07:00');
        expect($dog->fresh()->coat_matted_at)->toBeNull();
        dgTick('2026-10-27 08:01');
        $dog->refresh();
        expect($dog->coat_matted_at?->copy()->setTimezone('Europe/Ljubljana')->format('Y-m-d H:i'))->toBe('2026-10-27 08:00')
            ->and(ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'pet_coat_matted')->whereNull('actor_user_id')->count())->toBe(1)
            // Like the cat: no illness, no metric / mood effect — only the missed routines count.
            ->and($dog->isIll())->toBeFalse()
            ->and($dog->displayMetric('hygiene_level'))->toBe(100)
            ->and((int) $dog->escalation_level)->toBe(0)
            ->and(dgRoutines($dog, '2026-10-27', '2026-10-27'))->toBe([
                ['2026-10-27', 'grooming', 0, 'done', $child->id],
                ['2026-10-27', 'grooming', 1, 'missed', null],
                ['2026-10-27', 'grooming', 2, 'missed', null],
            ]);

        dgAt('2026-10-27 10:00');
        expect(dgState($child)['grooming'])->toMatchArray(['matted' => true, 'matted_since' => '2026-10-27T08:00:00+01:00', 'session_seconds' => 60]);
        $session = dgPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session');
        expect($session)->toMatchArray(['kind' => 'grooming', 'matted' => true, 'duration_ms' => 60000, 'min_strokes' => 20]);
        Carbon::setTestNow(now()->addSeconds(60));
        dgPost($child, '/api/child/pet/grooming/finish', ['session_id' => $session['id'], 'strokes' => dgStrokes(60000, 26)])
            ->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('result.matted', true)
            ->assertJsonPath('state.grooming.matted', false)
            ->assertJsonPath('state.grooming.session_seconds', 30);
        expect($dog->fresh()->coat_matted_at)->toBeNull();
    });

    it('a 1× breed mats when the week is missed, the matted coat is broadcast-visible and the weeks are checked once', function () {
        [, $child, $dog] = dgFamily(BreedType::Dobermann);
        dgTick('2026-10-20 09:00');
        expect($dog->fresh()->grooming_weeks_checked)->toBe(0);
        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect($dog->fresh()->coat_matted_at)->not->toBeNull()
            ->and($dog->fresh()->grooming_weeks_checked)->toBe(1)
            ->and(dgRoutines($dog, '2026-10-27', '2026-10-27'))->toBe([['2026-10-27', 'grooming', 0, 'missed', null]]);
        dgAt('2026-10-27 10:00');
        expect(dgState($child)['grooming']['matted'])->toBeTrue();
    });

    it('shares the slots fairly: each brushing counts for the child who did it', function () {
        [$parent, $child, $dog] = dgFamily(BreedType::PembrokeWelshCorgi);
        $sibling = dgSibling($parent, $dog);
        dgAt('2026-10-21 10:00');
        dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted');
        dgAt('2026-10-22 10:00');
        // Another child's running brushing blocks a start; the own one does not.
        $running = dgPost($sibling, '/api/child/pet/grooming/start')->assertOk()->json('session');
        dgPost($child, '/api/child/pet/grooming/start')->assertStatus(422)->assertJsonPath('reason', 'grooming_session_active');
        Carbon::setTestNow(now()->addMilliseconds($running['duration_ms']));
        dgPost($sibling, '/api/child/pet/grooming/finish', ['session_id' => $running['id'], 'strokes' => dgStrokes($running['duration_ms'], 20)])
            ->assertOk()->assertJsonPath('status', 'accepted');

        dgTickHourly('2026-10-26 22:00', '2026-10-27 09:00');
        expect(dgRoutines($dog, '2026-10-27', '2026-10-27'))->toBe([
            ['2026-10-27', 'grooming', 0, 'done', $child->id],
            ['2026-10-27', 'grooming', 1, 'done', $sibling->id],
            ['2026-10-27', 'grooming', 2, 'missed', null],
        ]);

        $scores = app(CareScoreService::class);
        $board = $scores->board(collect([$dog->fresh()]), 'Europe/Ljubljana');
        $slots = array_values(array_filter($board['routines'][$dog->id], fn ($r) => $r->type === RoutineType::Grooming));
        expect($scores->credited($board, $dog, $slots[0], $child->id, 2))->toBeTrue()
            ->and($scores->credited($board, $dog, $slots[0], $sibling->id, 2))->toBeFalse()
            ->and($scores->credited($board, $dog, $slots[1], $sibling->id, 2))->toBeTrue()
            ->and($scores->credited($board, $dog, $slots[1], $child->id, 2))->toBeFalse();
    });

    it('settles a brushing that ran past its TTL on the tick (expired, no consequence)', function () {
        [, $child, $dog] = dgFamily(BreedType::Beagle);
        dgAt('2026-10-21 10:00');
        $id = dgPost($child, '/api/child/pet/grooming/start')->assertOk()->json('session.id');
        dgTick('2026-10-21 10:05');
        dgPost($child, '/api/child/pet/grooming/finish', ['session_id' => $id, 'strokes' => dgStrokes()])
            ->assertStatus(422)->assertJsonPath('reason', 'care_session_expired');
        dgPost($child, '/api/child/pet/grooming/start')->assertOk();
        expect(ActivityLog::where('pet_id', $dog->id)->where('activity_type', 'groomed_pet')->count())->toBe(0);
    });

    it('keeps the litter, wand and scratching cat-only for a brushed dog', function () {
        [, $child] = dgFamily(BreedType::Rottweiler);
        dgAt('2026-10-21 10:00');
        $state = dgState($child);
        expect($state['litter'])->toBeNull()->and($state['wand'])->toBeNull()->and($state['scratching'])->toBeNull()
            ->and($state['grooming'])->not->toBeNull();
        dgPost($child, '/api/child/pet/litter-change/start')->assertStatus(422)->assertJsonPath('reason', 'litter_not_available');
    });
});

describe('excused weeks and the payment lock (David 2026-10-12, »ne štej dvakrat«)', function () {
    // Rottweiler 1× per week. Program week 2 = 27 Oct 08:00 + 7 program days; an 8-day
    // payment lock (28 Oct – 5 Nov) lengthens it to 11 Nov 08:00 — the lock is neither
    // blocked time nor week time (before the fix it alone excused the slot).
    function dgPeriod(Pet $dog, string $kind, string $fromLocal, string $toLocal): void
    {
        DB::table('pet_status_periods')->insert([
            'pet_id' => $dog->id, 'kind' => $kind,
            'started_at' => Carbon::parse($fromLocal, 'Europe/Ljubljana')->utc(),
            'ended_at' => Carbon::parse($toLocal, 'Europe/Ljubljana')->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('keeps a weekly brushing slot with a payment lock inside expected — missed if skipped, done in the days left', function () {
        [, $child, $dog] = dgFamily(BreedType::Rottweiler);
        dgPeriod($dog, 'payment_lock', '2026-10-28 08:00', '2026-11-05 08:00');

        dgAt('2026-11-11 10:00');
        expect(dgRoutines($dog, '2026-11-11', '2026-11-11'))->toBe([['2026-11-11', 'grooming', 0, 'missed', null]]);

        dgAt('2026-11-06 10:00');
        dgBrush($child)->assertOk()->assertJsonPath('status', 'accepted');
        dgAt('2026-11-11 10:00');
        expect(dgRoutines($dog, '2026-11-11', '2026-11-11'))->toBe([['2026-11-11', 'grooming', 0, 'done', $child->id]]);
    });

    it('measures the slot\'s excused share in program time: lock + 2 days ill → missed, lock + 4 days ill → excused', function () {
        foreach ([['2026-11-08 08:00', [['2026-11-11', 'grooming', 0, 'missed', null]]], ['2026-11-10 08:00', []]] as [$illUntil, $expected]) {
            [, , $dog] = dgFamily(BreedType::Rottweiler);
            dgPeriod($dog, 'payment_lock', '2026-10-28 08:00', '2026-11-05 08:00');
            dgPeriod($dog, 'illness', '2026-11-06 08:00', $illUntil);

            dgAt('2026-11-11 10:00');
            // Excused = not expected (no routine row), like the cat's mostly hard-stopped week.
            expect(dgRoutines($dog, '2026-11-11', '2026-11-11'))->toBe($expected, $illUntil);
        }
    });
});

describe('migration 2026_11_28_120000_add_dog_grooming', function () {
    it('adds pets.grooming_enabled (default false); down() drops it, up() adds it again', function () {
        $migration = require database_path('migrations/2026_11_28_120000_add_dog_grooming.php');
        [, , $dog] = dgFamily(BreedType::Rottweiler, enabled: false);
        expect(Schema::hasColumn('pets', 'grooming_enabled'))->toBeTrue()
            ->and(DB::table('pets')->where('id', $dog->id)->value('grooming_enabled'))->toBeFalse();

        $migration->down();
        expect(Schema::hasColumn('pets', 'grooming_enabled'))->toBeFalse();
        $migration->up();
        expect(Schema::hasColumn('pets', 'grooming_enabled'))->toBeTrue()
            ->and(DB::table('pets')->where('id', $dog->id)->value('grooming_enabled'))->toBeFalse();
    });
});
