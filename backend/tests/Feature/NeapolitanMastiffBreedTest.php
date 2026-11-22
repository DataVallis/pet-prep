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
| M5-R10-132 — Neapolitan Mastiff (appended after the Black Russian Terrier: AKC 2024 No. 128)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `neapolitan_mastiff` (S993–S1001) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.neapolitan_mastiff` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (not found
| in Coren's ranking), paid breed, Border Collie care rates, brushing 1× a week (RKC, M5-R13), skin-fold cleaning every day
| (PDSA, M5-R14), bath reminder every 4 weeks (PetMD, M5-R20), puppy socialisation with the PetMD quote (M5-R17); no meal
| gap, no ear / nail / tooth / groomer task, no heat warning). The senior boundary / senior arrival 74 (0.75 × the Bell &
| Hesketh 2021 mean 8.17 y) is Claude's suggestion, verified=false, open for David. Suitability: suits family_pet, children,
| large_home; consider not_for_small_children, shorter_lifespan, eyelid_problems (welfare, Breed Watch Category 3), sheds,
| drools; grey portrait, moderate folds; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function nmData(string $path): mixed
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

/** @return array<string, mixed> the Neapolitan Mastiff seeder row of (stage, from, key) */
function nmRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'neapolitan-mastiff'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("neapolitan-mastiff.{$stage}.{$from}.{$key->value}");

    return $row;
}

function nmPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->neapolitanMastiff()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function nmPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function nmLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::NeapolitanMastiff;
        $config = BreedConfig::where('breed_slug', 'neapolitan-mastiff')->sole();

        expect($breed->value)->toBe('neapolitan_mastiff')
            ->and($breed->slug())->toBe('neapolitan-mastiff')
            ->and(BreedType::fromSlug('neapolitan-mastiff'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1230, 'breeds.neapolitan_mastiff'])
            ->and($config->search_keywords)->toBe(['neapolitan mastiff', 'mastino napoletano', 'mastino', 'neo', 'napolitanski mastif', 'neapeljski mastif'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) nmData('proposed_game_parameters.neapolitan_mastiff.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = nmData('proposed_game_parameters.neapolitan_mastiff.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Neapolitan Mastiff pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = nmPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('neapolitan_mastiff');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'mastino_napoletano']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'neo']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_30_120000_add_neapolitan_mastiff_breed.php');
        // down() refuses while a Neapolitan Mastiff exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'neapolitan_mastiff']))->toThrow(QueryException::class);
        // The Black Russian Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'black_russian_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'neapolitan_mastiff']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::NeapolitanMastiff);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'neapolitan-mastiff')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'neapolitan-mastiff')->count());

        BreedConfig::where('breed_slug', 'neapolitan-mastiff')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'neapolitan-mastiff')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'neapolitan-mastiff')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'neapolitan-mastiff');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'neapolitan-mastiff');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'neapolitan-mastiff'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NEAPOLITAN_MASTIFF)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_NEAPOLITAN_MASTIFF_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_NEAPOLITAN_MASTIFF', 'PROPOSED_R10_NEAPOLITAN_MASTIFF_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.neapolitan_mastiff';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(nmRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(nmData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(nmRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(nmData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(nmRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(nmData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 8.17 y (Bell & Hesketh 2021 mean, S1000) = 74 — the closest rule row, Claude's suggestion
            // (RKC / PDSA "Under 10 years" has no lower bound, no McMillan 2024 / VetCompass median) → verified=false.
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(nmData('neapolitan_mastiff.lifespan.senior_from.months'))
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(nmData("{$pgp}.senior_boundary_months.value"))
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * nmRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(nmData('neapolitan_mastiff.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1000'])
            ->and(nmData('neapolitan_mastiff.lifespan.mcmillan_2024.source_id'))->toBeNull()
            // PetMD 7 y → 63 is David's alternative, never seeded.
            ->and(nmData("{$pgp}.stage_boundaries_months.alternatives.senior_petmd_7_y"))->toBe(63)
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S1000')
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(nmRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_NEAPOLITAN_MASTIFF_SENIOR)
            ->and(nmData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_NEAPOLITAN_MASTIFF_SENIOR)
            ->and(nmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(74);

        expect(nmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nmData("{$pgp}.exercise_minutes_adult.value"))
            ->and(nmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(nmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(nmData('neapolitan_mastiff.exercise.adult.quote'))
            ->and(nmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(nmData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(nmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S994')
            ->and(nmRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(nmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nmData("{$pgp}.exercise_minutes_senior.value"))
            ->and(nmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(nmRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(nmData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(nmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(nmData("{$pgp}.learning_multiplier.value"))
            ->and(nmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NEAPOLITAN_MASTIFF) {
                $decided++;
                expect($row['breed_slug'])->toBe('neapolitan-mastiff')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.neapolitan_mastiff.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) nmData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: weekly brushing (RKC, M5-R13), daily skin-fold cleaning (PDSA,
        // M5-R14), the monthly bath reminder (PetMD, M5-R20), puppy socialisation with the PetMD quote (M5-R17). No meal gap
        // (no advice quote), no ear / nail / tooth / groomer task (no frequency), no heat warning (not flat-faced in A/B).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['neapolitan_mastiff'];
        expect(json_encode([nmData('neapolitan_mastiff'), nmData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(BreedStageParamsSeeder::DOG_GROOMING['neapolitan_mastiff'])->toBe([1, 'S994', nmData("{$pgp}.grooming.quote"), 'high', false])
            ->and(nmData("{$pgp}.grooming.quote"))->toBe(nmData('neapolitan_mastiff.suitability.rkc_grooming.quote'))
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('neapolitan_mastiff')
            ->and(nmData('neapolitan_mastiff.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['neapolitan_mastiff'])->toBe(['S996', nmData("{$pgp}.socialisation.quote")])
            ->and(nmData("{$pgp}.socialisation.quote"))->toBe(nmData('neapolitan_mastiff.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['fold_cleaning', 'bath'])
            ->and(array_slice($tasks['fold_cleaning'], 0, 5))->toBe([7, 'day', 'S995', nmData("{$pgp}.face_fold_cleaning.quote"), "{$pgp}.face_fold_cleaning"])
            ->and(nmData("{$pgp}.face_fold_cleaning.quote"))->toBe(nmData('neapolitan_mastiff.suitability.pdsa_skin_folds.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S996', nmData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(nmData("{$pgp}.bathing.quote"))->toBe(nmData('neapolitan_mastiff.suitability.petmd_bath.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('neapolitan_mastiff');
    });

    it('takes the breed-level numbers from the sourced neapolitan_mastiff entries', function () {
        // FCI-Standard N° 197 (S993): males 60–70, females 50–60 kg.
        expect(nmRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([50, 70])
            ->and(nmData('neapolitan_mastiff.adult_weight.fci.value'))->toEqual(['male' => [60, 70], 'female' => [50, 60]])
            ->and(nmRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(nmData('neapolitan_mastiff.adult_weight.fci.source_id'))
            ->and(nmRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(nmData('neapolitan_mastiff.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            ->and(nmRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(nmData('neapolitan_mastiff.growth.adult_weight_reached.quote'))
            // Not found in Coren's ranking → no rank, learning 1.0.
            ->and(nmRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(nmData('neapolitan_mastiff.trainability.coren_rank.value'))->toBeNull()
            ->and(nmRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('neapolitan_mastiff.lifespan.bell_hesketh_2021')
            ->and(nmRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S1000')
            ->and(nmRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(8.17)
            ->and(nmRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(nmData('neapolitan_mastiff.lifespan.bell_hesketh_2021.quote'))
            ->and(nmRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(nmRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S996')
            ->and(nmRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'neapolitan-mastiff');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::GroomingSessionsPerWeek->value)->pluck('value')->all())->toBe([1]);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::FoldCleaningFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S995', 'S996'])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the senior boundary / senior arrival (Claude's suggestion) and the general teething chewing chance.
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing([
                'senior.'.StageParamKey::StartsAtMonths->value,
                'senior.'.StageParamKey::ArrivalAgeMonths->value,
                'puppy.'.StageParamKey::ChewingChancePerDay->value,
            ]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: weekly brushing (RKC S994). M5-R17: the socialisation quote (PetMD S996).
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R20: skin-fold cleaning (PDSA S995), bath reminder (PetMD S996).
            StageParamKey::FoldCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('neapolitan-mastiff'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = nmPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 73 months' => [73, LifeStage::Adult, 60, 6000],
        'senior 74 months' => [74, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 74 months, earlier than the Black Russian Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('neapolitan-mastiff', 73))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('neapolitan-mastiff', 74))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('black-russian-terrier', 74))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (unranked, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = nmPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'neapolitan_mastiff', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = nmPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(nmLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::NeapolitanMastiff)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 74 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'neapolitan_mastiff'] as $breed) {
            $pin = nmPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(nmLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['neapolitan_mastiff'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(74)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('neapolitan_mastiff')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = nmPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Neapolitan Mastiff after the Black Russian Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'neapolitan_mastiff', 'black_russian_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'neapolitan_mastiff', 'slug' => 'neapolitan-mastiff', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.neapolitan_mastiff',
            'search_keywords' => seededBreedConfig('neapolitan_mastiff')['search_keywords'], 'sort_order' => 1230,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'large_home'],
                'consider' => ['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sheds', 'drools'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 197, S993)', function () {
    it('samples DNA in the five standard colours of the appearance block only, with moderate folds and natural ears / tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('neapolitan_mastiff')
            ->and(PetDnaService::hasAppearance('neapolitan_mastiff'))->toBeTrue()
            ->and(config('breed_appearance.neapolitan_mastiff.verified'))->toBeFalse()
            ->and(config('breed_appearance.neapolitan_mastiff.prompt_order'))->toContain('coat_color')->toContain('skin')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('neapolitan_mastiff', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['skin'])->toContain('moderate')
                ->and($traits['eye_color'])->toContain('tight eyelids')
                ->and($traits['muzzle'])->toContain('open nostrils');

            $prompt = $prompts->imagePrompt('neapolitan_mastiff', $traits);
            expect($prompt)->toContain('Neapolitan Mastiff')
                ->not->toMatch('/\b(merle|albino|white markings|cropped|docked|long coat|fluffy|flat-faced|brindle)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.neapolitan_mastiff.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(5)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.neapolitan_mastiff'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.neapolitan_mastiff'), 'key'))->toBe(['grey', 'black', 'mahogany', 'fawn', 'deep-fawn'])
            ->and(nmData('neapolitan_mastiff.appearance.colours.quote'))->toContain('deep fawn (red deer)');
    });

    it('draws the register portrait in grey', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::NeapolitanMastiff);

        expect($traits['coat_color'])->toBe('grey')
            ->and($portrait->prompt(BreedType::NeapolitanMastiff))->toContain('Neapolitan Mastiff')->toContain('tight eyelids')
            ->and(BreedPortraitService::relativeFile(BreedType::NeapolitanMastiff))->toBe('dog/neapolitan-mastiff.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::NeapolitanMastiff);
        expect($v1['prompt_anchor'])->toContain('Neapolitan Mastiff')->toContain('moderate folds')->toContain('open nostrils')
            ->not->toMatch('/\b(merle|albino|docked|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('grey');

        $pet = nmPet(74, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Neapolitan Mastiff')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the eyelid chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.neapolitan_mastiff'),
            config('breed_suitability.breeds.neapolitan_mastiff'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'neapolitan-mastiff')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::NeapolitanMastiff);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Breed Watch Category 3 → the eyelid chip; no A/B source calls the breed flat-faced → no breathing chip.
            ->and($tags['consider'])->toContain('eyelid_problems')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing']))->toBe([])
            ->and(nmData('neapolitan_mastiff.health.breed_watch.quote'))->toBe('Category 3')
            ->and(nmData('proposed_game_parameters.neapolitan_mastiff.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (63 PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'neapolitan-mastiff')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(63)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits family, children and a large home; consider small children, lifespan, eyelids, shedding and drool', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::NeapolitanMastiff);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sheds', 'drools'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming');

        expect(nmData('neapolitan_mastiff.behaviour.children.quote'))->toBe('Neapolitan Mastiffs are great with families and children')
            ->and(nmData('neapolitan_mastiff.behaviour.small_children.quote'))->toContain('small child')
            ->and(nmData('neapolitan_mastiff.suitability.rkc_lifespan.quote'))->toBe('Under 10 years')
            ->and(nmData('neapolitan_mastiff.health.eyelids.quote'))->toContain('excessive skin over the face')
            ->and(nmData('neapolitan_mastiff.behaviour.drooling.quote'))->toBe('They also drool a lot');
    });
});
