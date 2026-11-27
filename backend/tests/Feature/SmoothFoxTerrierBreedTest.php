<?php

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetOrigin;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Enums\StageParamKey;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\User;
use App\Services\BreedSuitability;
use App\Services\ChildProfileService;
use App\Services\FalAiService;
use App\Services\LifeStageService;
use App\Services\Media\BreedPortraitService;
use App\Services\Media\MediaEntitlementService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use App\Services\TrainingService;
use Database\Seeders\BreedConfigsSeeder;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R10-143 — Smooth Fox Terrier (appended after the Smooth Fox Terrier: AKC 2024 No. 139)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `smooth_fox_terrier` (S1070–S1075, S873) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.smooth_fox_terrier` (exercise 60 min — PDSA "1 hour"; the RKC
| page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × the Dogs Trust lower
| bound "typically 12+ years", S1075), arrival 2 / 9 / 36 / 108, learning × 1.0 (Coren rank 40, Average), paid breed, Border
| Collie care rates, brushing once a week (PetMD, M5-R13), puppy socialisation with the PetMD quote (S1071, M5-R17), tooth
| brushing every day + ear cleaning once a week (WebMD S1072); no nail / bath / groomer task, no meal gap, no heat walk ban.
| No welfare chip, in marketing; suits active_family, family_pet, children; consider sheds, chases_small_pets; white with
| black and tan markings portrait (four standard colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function sftData(string $path): mixed
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

/** @return array<string, mixed> the Smooth Fox Terrier seeder row of (stage, from, key) */
function sftRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'smooth-fox-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("smooth-fox-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function sftPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->smoothFoxTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function sftPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function sftLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SmoothFoxTerrier;
        $config = BreedConfig::where('breed_slug', 'smooth-fox-terrier')->sole();

        expect($breed->value)->toBe('smooth_fox_terrier')
            ->and($breed->slug())->toBe('smooth-fox-terrier')
            ->and(BreedType::fromSlug('smooth-fox-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1340, 'breeds.smooth_fox_terrier'])
            ->and($config->search_keywords)->toBe(['smooth fox terrier', 'fox terrier smooth', 'foxie', 'gladkodlaki foksterier', 'foksterier', 'gladkodlaki fox terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) sftData('proposed_game_parameters.smooth_fox_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Smooth Fox Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = sftPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('smooth_fox_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'smooth_fox_terrierr']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_14_120000_add_smooth_fox_terrier_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'smooth_fox_terrier']))->toThrow(QueryException::class);
        // The Welsh Springer Spaniel (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'welsh_springer_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'smooth_fox_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SmoothFoxTerrier)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'smooth-fox-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier')->count());

        BreedConfig::where('breed_slug', 'smooth-fox-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'smooth-fox-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'smooth-fox-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'smooth-fox-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'smooth-fox-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SMOOTH_FOX_TERRIER)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_SMOOTH_FOX_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.smooth_fox_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(sftRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(sftData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(sftRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(sftData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(sftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(sftData('smooth_fox_terrier.lifespan.senior_from.months'))
            ->and(sftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * sftRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(sftData('smooth_fox_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1075'])
            ->and(sftData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(sftRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', sftData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(sftRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SMOOTH_FOX_TERRIER)
            ->and(sftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(sftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sftData("{$pgp}.exercise_minutes_adult.value"))
            ->and(sftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(sftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(sftData('smooth_fox_terrier.exercise.adult.quote'))
            ->and(sftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S873')
            ->and(sftRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(sftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sftData("{$pgp}.exercise_minutes_senior.value"))
            ->and(sftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(sftRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(sftRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SMOOTH_FOX_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('smooth-fox-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.smooth_fox_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) sftData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing once a week (PetMD, M5-R13), puppy socialisation (PetMD, M5-R17),
        // tooth brushing every day + ear cleaning once a week (WebMD). No nail task (no frequency), no bath ("not necessary unless
        // they're dirty"), no groomer task (no source), no meal gap.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['smooth_fox_terrier'];
        expect(json_encode([sftData('smooth_fox_terrier'), sftData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(sftData("{$pgp}.grooming.value"))->toBe(1)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('smooth_fox_terrier')
            ->and(sftData('smooth_fox_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['smooth_fox_terrier'])->toBe(['S1071', sftData("{$pgp}.socialisation.quote")])
            ->and(sftData("{$pgp}.socialisation.quote"))->toBe(sftData('smooth_fox_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1072', sftData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(sftData("{$pgp}.tooth_brushing.quote"))->toBe(sftData('smooth_fox_terrier.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S1072', sftData("{$pgp}.ear_cleaning.quote"), "{$pgp}.ear_cleaning"])
            ->and(sftData("{$pgp}.ear_cleaning.quote"))->toBe(sftData('smooth_fox_terrier.suitability.webmd_ears.quote'))
            ->and(sftData('smooth_fox_terrier.suitability.webmd_nails.quote'))->toBe('Their strong, fast-growing nails should be kept neat and trimmed.')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('smooth_fox_terrier');
    });

    it('takes the breed-level numbers from the sourced smooth_fox_terrier entries', function () {
        expect(sftRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([7.0, 8.0])
            ->and(sftData('smooth_fox_terrier.adult_weight.fci.value'))->toEqual(['male' => [7.5, 8], 'female' => [7, 7.5]])
            ->and(sftRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(sftData('smooth_fox_terrier.adult_weight.fci.quote'))
            ->and(sftRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(sftRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(sftData('smooth_fox_terrier.trainability.coren_rank.value'))
            ->and(sftRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(40)
            ->and(sftRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('smooth_fox_terrier.lifespan.dogs_trust')
            ->and(sftRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(sftRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(sftData('smooth_fox_terrier.lifespan.dogs_trust.quote'));

        $grooming = sftRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S1071')
            ->and($grooming['quote'])->toBe(sftData('smooth_fox_terrier.suitability.petmd_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(sftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1071')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek,
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('smooth-fox-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = sftPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('smooth-fox-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('smooth-fox-terrier', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed breed\'s speed (Coren rank 40, Average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = sftPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'smooth_fox_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = sftPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(sftLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SmoothFoxTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 108 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'smooth_fox_terrier'] as $breed) {
            $pin = sftPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(sftLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['smooth_fox_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('smooth_fox_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = sftPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Smooth Fox Terrier after the Welsh Springer Spaniel with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'smooth_fox_terrier', 'welsh_springer_spaniel');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'smooth_fox_terrier', 'slug' => 'smooth-fox-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.smooth_fox_terrier',
            'search_keywords' => seededBreedConfig('smooth_fox_terrier')['search_keywords'], 'sort_order' => 1340,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 12 S1070)', function () {
    it('samples DNA only in the colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('smooth_fox_terrier')
            ->and(PetDnaService::hasAppearance('smooth_fox_terrier'))->toBeTrue()
            ->and(config('breed_appearance.smooth_fox_terrier.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.smooth_fox_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('smooth_fox_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('smooth_fox_terrier', $traits);
            expect($prompt)->toContain('Smooth Fox Terrier')->toContain('V-shaped')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|liver|wiry|wire|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.smooth_fox_terrier');
        expect($standard)->toBe(['white with black and tan markings', 'white with tan markings', 'white with black markings', 'all white'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['tricolour', 'white-tan', 'white-black', 'white'])
            ->and(sftData('smooth_fox_terrier.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait white with black and tan markings (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SmoothFoxTerrier);

        expect($traits['coat_color'])->toBe('white with black and tan markings')
            ->and($portrait->prompt(BreedType::SmoothFoxTerrier))->toContain('Smooth Fox Terrier')->toContain('white with black and tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::SmoothFoxTerrier))->toBe('dog/smooth-fox-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SmoothFoxTerrier);
        expect($v1['prompt_anchor'])->toContain('Smooth Fox Terrier')->toContain('white with black and tan markings')->toContain('V-shaped')
            ->not->toMatch('/\b(merle|cropped|docked|wiry)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('white with black and tan markings');

        $pet = sftPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Smooth Fox Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.smooth_fox_terrier'),
            config('breed_suitability.breeds.smooth_fox_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::SmoothFoxTerrier);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(sftData('proposed_game_parameters.smooth_fox_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'smooth-fox-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(90);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families, families and children; consider shedding and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::SmoothFoxTerrier);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['sheds', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('needs_company')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming');

        expect(sftData('smooth_fox_terrier.suitability.petmd_shedding.quote'))->toContain('moderate shedders')
            ->and(sftData('smooth_fox_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(sftData('smooth_fox_terrier.behaviour.children.quote'))->toContain('great companions for children');
    });
});
