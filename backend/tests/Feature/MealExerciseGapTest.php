<?php

use App\Enums\ActivityType;
use App\Enums\BreedType;
use App\Enums\StageParamKey;
use App\Models\ActivityLog;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\User;
use App\Services\LifeStageService;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| M5-R16 — gap between a meal and exercise (bloat / GDV)
|--------------------------------------------------------------------------
| David 2026-10-12, "1 ura, samo opozorilo": for 60 minutes after a meal the
| child app warns "wait with the walk — the dog is digesting". Steps still
| count, no penalty, no score. Only breeds whose A/B source states the advice
| verbatim (PDSA: Cane Corso S199, Dobermann S211) get the
| `meal_exercise_gap_minutes` row; the child state carries
| `feeding.digesting_until` (last meal + gap, null when none / passed).
| Europe/Ljubljana, quiet hours 21–07.
*/

afterEach(function () {
    Carbon::setTestNow();
});

function megAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Europe/Ljubljana')->utc());
}

/**
 * A family with a profiled, young (12 months) dog of $breed born at $bornLocal.
 *
 * @return array{0: User, 1: Pet}
 */
function megFamily(BreedType $breed, string $bornLocal = '2026-10-20 06:00'): array
{
    seedLifeStageData();
    megAt($bornLocal);
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
        'hunger_level' => 50,
        'energy_level' => 0,
        'daily_step_count' => 0,
    ], $attributes)));

    return [$child, $dog->fresh()];
}

/** @return array<string, mixed> */
function megState(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->getJson('/api/child/pet')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

/** Feed inside the first window of the day (07:00–09:00 for a young dog). */
function megFeed(User $child): array
{
    test()->actingAs($child, 'sanctum');
    $json = test()->postJson('/api/child/pet/feed')->assertOk()->json();
    app('auth')->forgetGuards();

    return $json;
}

/** A node of docs/research/dog-data/data.json by dotted path. */
function megData(string $path): mixed
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/dog-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

    $node = $data;
    foreach (explode('.', $path) as $segment) {
        expect($node)->toHaveKey($segment);
        $node = $node[$segment];
    }

    return $node;
}

function megIso(string $local): string
{
    return Carbon::parse($local, 'Europe/Ljubljana')->toIso8601String();
}

describe('seeded value', function () {
    it('seeds 60 minutes for every breed with a verbatim source quote (Cane Corso, Dobermann, Boxer), each citing data.json', function () {
        $rows = collect(BreedStageParamsSeeder::rows())->where('key', StageParamKey::MealExerciseGapMinutes->value);
        $breeds = array_keys(BreedStageParamsSeeder::MEAL_EXERCISE_GAP);

        // Both ways: a data.json gap with a value ⇔ a seeded row (a later breed run adds both).
        $inData = collect(megData('proposed_game_parameters'))
            ->filter(fn ($p) => is_array($p) && ($p['meal_exercise_gap']['value'] ?? null) !== null)->keys()->sort()->values()->all();
        expect($inData)->toBe(collect($breeds)->sort()->values()->all())
            ->and($breeds)->toContain('cane_corso', 'dobermann', 'boxer')
            ->and($rows->pluck('breed_slug')->sort()->values()->all())
            ->toBe(collect($breeds)->map(fn (string $b) => BreedType::from($b)->slug())->sort()->values()->all());
        expect(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['cane_corso'][0])->toBe('S199')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['dobermann'][0])->toBe('S211')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['boxer'][0])->toBe('S219');

        foreach ($breeds as $key) {
            $row = $rows->firstWhere('breed_slug', BreedType::from($key)->slug());
            $entry = megData("proposed_game_parameters.{$key}.meal_exercise_gap");
            expect($row['stage'])->toBe('all')
                ->and($row['value'])->toBe(60)
                ->and($row['value'])->toBe($entry['value'])
                ->and($row['verified'])->toBeTrue()
                ->and($row['source_id'])->toBe($entry['source_id'])
                ->and($row['quote'])->toBe($entry['quote'])
                ->and($row['ref'])->toBe("proposed_game_parameters.{$key}.meal_exercise_gap")
                ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP)
                ->and((string) $entry['decision'])->toStartWith('potrdil David 2026-10-12: 60 min, samo opozorilo')
                ->and(StageParamKey::MealExerciseGapMinutes->validate($row['value']))->toBeNull();
        }
        // The Bernese Mountain Dog's PDSA advice (S154) is only a paraphrase in data.json → no row.
        expect(megData('bernese_mountain_dog.health.gdv.notes'))->toContain('(paraphrase)');
    });

    it('lands in breed_stage_params (insert-only) and validates its shape', function () {
        seedLifeStageData();

        $seeded = BreedStageParam::where('key', StageParamKey::MealExerciseGapMinutes->value)->pluck('value', 'breed_slug')->all();
        expect($seeded)->toHaveKeys(['cane-corso', 'dobermann', 'boxer'])
            ->and(array_unique(array_values($seeded)))->toBe([60])
            ->and(count($seeded))->toBe(count(BreedStageParamsSeeder::MEAL_EXERCISE_GAP))
            ->and(StageParamKey::MealExerciseGapMinutes->isBreedLevel())->toBeTrue()
            ->and(StageParamKey::MealExerciseGapMinutes->validate(0))->not->toBeNull()
            ->and(StageParamKey::MealExerciseGapMinutes->validate(241))->not->toBeNull()
            ->and(StageParamKey::MealExerciseGapMinutes->validate(60.5))->not->toBeNull()
            ->and(StageParamKey::MealExerciseGapMinutes->validate(90))->toBeNull();
    });
});

describe('child state', function () {
    it('warns for 60 minutes after a Cane Corso meal, then clears', function () {
        [$child] = megFamily(BreedType::CaneCorso);

        megAt('2026-10-20 07:00');
        expect(megState($child)['feeding']['digesting_until'])->toBeNull();

        megAt('2026-10-20 07:30');
        expect(megFeed($child)['state']['feeding']['digesting_until'])->toBe(megIso('2026-10-20 08:30'));

        megAt('2026-10-20 08:29:59');
        expect(megState($child)['feeding']['digesting_until'])->toBe(megIso('2026-10-20 08:30'));

        megAt('2026-10-20 08:30');
        expect(megState($child)['feeding']['digesting_until'])->toBeNull();
    });

    it('warns after a Dobermann meal too', function () {
        [$child] = megFamily(BreedType::Dobermann);

        megAt('2026-10-20 07:10');
        expect(megFeed($child)['state']['feeding']['digesting_until'])->toBe(megIso('2026-10-20 08:10'));
    });

    it('never warns for a breed without the sourced advice', function (BreedType $breed) {
        [$child] = megFamily($breed);

        megAt('2026-10-20 07:30');
        expect(megFeed($child)['state']['feeding']['digesting_until'])->toBeNull();
    })->with([
        'mutt' => BreedType::Mutt,
        'Labrador' => BreedType::LabradorRetriever,
        'Bernese Mountain Dog (paraphrase only)' => BreedType::BerneseMountainDog,
        'Rottweiler' => BreedType::Rottweiler,
    ]);

    it('also counts a meal the parent covered in quiet hours (the dog digests either way)', function () {
        [$child, $dog] = megFamily(BreedType::CaneCorso);

        megAt('2026-10-20 07:00');
        ActivityLog::create(['pet_id' => $dog->id, 'actor_user_id' => null, 'activity_type' => ActivityType::ParentFedPet->value, 'metadata' => []]);

        megAt('2026-10-20 07:20');
        expect(megState($child)['feeding']['digesting_until'])->toBe(megIso('2026-10-20 08:00'));
    });

    it('follows an admin edit of the row (Filament wins)', function () {
        [$child] = megFamily(BreedType::CaneCorso);
        BreedStageParam::where('breed_slug', 'cane-corso')->where('key', StageParamKey::MealExerciseGapMinutes->value)->update(['value' => json_encode(90)]);
        LifeStageService::forgetBreed('cane-corso');

        megAt('2026-10-20 07:30');
        expect(megFeed($child)['state']['feeding']['digesting_until'])->toBe(megIso('2026-10-20 09:00'));
    });
});

describe('only a warning', function () {
    it('still counts steps while the dog digests: same energy, no refusal, no extra activity row', function () {
        [$child, $dog] = megFamily(BreedType::CaneCorso);

        megAt('2026-10-20 07:30');
        megFeed($child);

        megAt('2026-10-20 07:45');
        test()->actingAs($child, 'sanctum');
        $response = test()->postJson('/api/child/pet/steps', [
            'steps_today' => 1200, 'source' => 'healthkit', 'recorded_at' => megIso('2026-10-20 07:45'),
        ])->assertOk()->json();
        app('auth')->forgetGuards();

        $goal = $response['state']['steps']['goal'];
        expect($response['state']['steps']['steps_today'])->toBe(1200)
            ->and($response['state']['steps']['energy_level'])->toBe((int) round(min(100, 1200 / $goal * 100)))
            ->and($response['state']['feeding']['digesting_until'])->toBe(megIso('2026-10-20 08:30'))
            ->and(ActivityLog::where('pet_id', $dog->id)->pluck('activity_type')->map(fn ($t) => $t instanceof BackedEnum ? $t->value : $t)->all())
            ->toBe([ActivityType::FedPet->value]);
    });
});
