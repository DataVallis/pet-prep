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
| M5-R10-105 — Bouvier des Flandres (appended after the Beauceron: AKC 2024 No. 101 "Bouvier des Flandres")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bouvier_des_flandres` (S795–S799) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bouvier_des_flandres` (exercise 60 min — RKC "Up to 1 hour
| per day"; PetMD "at least two hours" in alternatives —, senior 45 min, puppy / young 10 min × age capped at 60, stages
| 9 / 36 / 102 (0.75 × McMillan 2024 median 11.3 y, Dogs Trust S123), arrival 2 / 9 / 36 / 102, learning × 1.2 (Coren
| rank 29), paid breed, Border Collie care rates, brushing every day (RKC "Grooming: Every day", M5-R13), ear cleaning
| every 2 weeks and nail trimming every 4 weeks (PetMD S798, M5-R18 / R19), meal / walk gap (PetMD S798, M5-R16), puppy
| socialisation with the PetMD quote (S798, M5-R17). RKC Breed Watch Category 1 → no welfare chip, in marketing; dark
| grey brindle portrait with natural ears and tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bdfData(string $path): mixed
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

/** @return array<string, mixed> the Bouvier des Flandres seeder row of (stage, from, key) */
function bdfRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bouvier-des-flandres'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bouvier_des_flandres.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bdfPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bouvierDesFlandres()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bdfPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bdfLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BouvierDesFlandres;
        $config = BreedConfig::where('breed_slug', 'bouvier-des-flandres')->sole();

        expect($breed->value)->toBe('bouvier_des_flandres')
            ->and($breed->slug())->toBe('bouvier-des-flandres')
            ->and(BreedType::fromSlug('bouvier-des-flandres'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 980, 'breeds.bouvier_des_flandres'])
            ->and($config->search_keywords)->toBe(['bouvier des flandres', 'bouvier', 'flanders cattle dog', 'vlaamse koehond', 'flandrijski govedar'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bdfData('proposed_game_parameters.bouvier_des_flandres.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bdfData('proposed_game_parameters.bouvier_des_flandres.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bouvier des Flandres pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bdfPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bouvier_des_flandres');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bouvier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'flandrijski_govedar']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_26_120000_add_bouvier_des_flandres_breed.php');
        // down() refuses while a Bouvier des Flandres exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bouvier_des_flandres']))->toThrow(QueryException::class);
        // The Beauceron (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'beauceron']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bouvier_des_flandres']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BouvierDesFlandres);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bouvier-des-flandres')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bouvier-des-flandres')->count());

        BreedConfig::where('breed_slug', 'bouvier-des-flandres')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bouvier-des-flandres')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bouvier-des-flandres')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bouvier-des-flandres');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bouvier-des-flandres');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bouvier-des-flandres'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BOUVIER_DES_FLANDRES)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BOUVIER_DES_FLANDRES');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bouvier_des_flandres';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bdfRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdfData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bdfRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bdfData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bdfRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bdfData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 11.3 y × 12) = 102 (McMillan 2024 median via Dogs Trust S123) → runbook rule, verified.
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdfData('bouvier_des_flandres.lifespan.senior_from.months'))
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bdfRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bdfData('bouvier_des_flandres.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', bdfData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BOUVIER_DES_FLANDRES)
            ->and(bdfRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(bdfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(102)
            // The RKC lower bound (90) stays an alternative.
            ->and(bdfData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_rkc_lower_bound_10_y' => 90]);

        expect(bdfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdfData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bdfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bdfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bdfData('bouvier_des_flandres.exercise.adult.quote'))
            ->and(bdfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bdfData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(bdfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S795')
            // PetMD's 120 minutes stays an alternative.
            ->and(bdfData("{$pgp}.exercise_minutes_adult.alternatives.0.value"))->toBe(120)
            ->and(bdfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdfData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bdfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(bdfRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bdfData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bdfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bdfData("{$pgp}.learning_multiplier.value"))
            ->and(bdfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BOUVIER_DES_FLANDRES) {
                $decided++;
                expect($row['breed_slug'])->toBe('bouvier-des-flandres')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bouvier_des_flandres.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bdfData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, RKC), ear cleaning every 2 weeks and nail trimming every
        // 4 weeks (M5-R18 / R19, PetMD), the meal / walk gap (M5-R16, PetMD) and puppy socialisation with the PetMD quote
        // (M5-R17). No bath / groomer / tooth task: no frequency (bath only when dirty, groomer as needed).
        expect(json_encode([bdfData('bouvier_des_flandres'), bdfData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bdfData("{$pgp}.grooming.value"))->toBe(7)
            ->and(bdfData("{$pgp}.grooming.alternatives.0.value"))->toBe(2)
            ->and(bdfData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['bouvier_des_flandres'])->toBe(['S798', bdfData("{$pgp}.meal_exercise_gap.quote")])
            ->and(bdfData("{$pgp}.meal_exercise_gap.quote"))->toBe(bdfData('bouvier_des_flandres.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['bouvier_des_flandres'])->toBe(['S798', bdfData("{$pgp}.socialisation.quote")])
            ->and(bdfData("{$pgp}.socialisation.quote"))->toBe(bdfData('bouvier_des_flandres.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('bouvier_des_flandres')
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']))->toBe(['ear_cleaning', 'nail_trim'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['ear_cleaning'][0])->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['ear_cleaning'][1])->toBe('two_weeks')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['ear_cleaning'][3])->toBe(bdfData("{$pgp}.ear_care.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['nail_trim'][0])->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['nail_trim'][1])->toBe('month')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['bouvier_des_flandres']['nail_trim'][3])->toBe(bdfData("{$pgp}.nail_trimming.quote"))
            ->and(bdfData("{$pgp}.ear_care.quote"))->toBe(bdfData('bouvier_des_flandres.suitability.petmd_ears.quote'))
            ->and(bdfData("{$pgp}.nail_trimming.quote"))->toBe(bdfData('bouvier_des_flandres.suitability.petmd_nails.quote'));
    });

    it('takes the breed-level numbers from the sourced bouvier_des_flandres entries', function () {
        // FCI Standard No 191 (KUSA S797): males approximately 35–40 kg, females approximately 27–35 kg.
        expect(bdfRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([27, 40])
            ->and(bdfData('bouvier_des_flandres.adult_weight.fci.value'))->toEqual(['male' => [35, 40], 'female' => [27, 35]])
            ->and(bdfRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bdfData('bouvier_des_flandres.adult_weight.fci.source_id'))
            ->and(bdfRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bdfData('bouvier_des_flandres.adult_weight.fci.quote'))
            ->and(bdfRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(bdfData('bouvier_des_flandres.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(bdfRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(29)
            ->and(bdfData('bouvier_des_flandres.trainability.coren_rank.value'))->toBe(29)
            ->and(bdfRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bouvier_des_flandres.lifespan.median_uk')
            ->and(bdfRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(bdfRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.3)
            ->and(bdfRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bdfData('bouvier_des_flandres.lifespan.median_uk.quote'));

        // Brushing every day: RKC "Grooming: Every day" → 7 (PetMD "once or twice a week" → 2 in alternatives).
        $grooming = bdfRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S795')
            ->and($grooming['quote'])->toBe(bdfData('bouvier_des_flandres.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bdfData('proposed_game_parameters.bouvier_des_flandres.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(bdfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bdfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S798')
            ->and(bdfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bouvier-des-flandres');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap->count())->toBe(1)
            ->and($gap[0]['value'])->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP_MINUTES)
            ->and($gap[0]['source_id'])->toBe('S798')
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value])->sort()->values()->all())
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: socialisation (PetMD S798 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / walk gap (PetMD S798).
            StageParamKey::MealExerciseGapMinutes,
        ];
        // Care-task frequency rows are breed-specific and skipped (ear cleaning, nail trimming).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bouvier-des-flandres'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bdfPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 101 months' => [101, LifeStage::Adult, 60, 6000],
        'senior 102 months' => [102, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 102 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bouvier-des-flandres', 101))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bouvier-des-flandres', 102))->toBe(LifeStage::Senior);
    });

    it('learns 1.2× faster (Coren rank 29, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bdfPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bouvier_des_flandres', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bdfPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bdfLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BouvierDesFlandres)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 102 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bouvier_des_flandres'] as $breed) {
            $pin = bdfPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bdfLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bouvier_des_flandres'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(102)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('bouvier_des_flandres')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bdfPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bouvier des Flandres after the Beauceron with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bouvier_des_flandres', 'beauceron');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bouvier_des_flandres', 'slug' => 'bouvier-des-flandres', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bouvier_des_flandres',
            'search_keywords' => seededBreedConfig('bouvier_des_flandres')['search_keywords'], 'sort_order' => 980,
            'suitability' => [
                'suits' => ['children', 'large_home'],
                'consider' => ['frequent_grooming', 'needs_company', 'food_motivated_weight', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI Standard No 191 via KUSA S797)', function () {
    it('samples DNA in the four standard colours only, natural ears and tail, never fawn, chocolate or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bouvier_des_flandres')
            ->and(PetDnaService::hasAppearance('bouvier_des_flandres'))->toBeTrue()
            ->and(config('breed_appearance.bouvier_des_flandres.verified'))->toBeFalse()
            ->and(config('breed_appearance.bouvier_des_flandres.prompt_order'))->toContain('tail')->toContain('ear_carriage')->toContain('beard');

        $standard = array_column(config('breed_appearance.bouvier_des_flandres.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('bouvier_des_flandres', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('bouvier_des_flandres', $traits);
            expect($prompt)->toContain('Bouvier des Flandres')
                ->not->toMatch('/\b(merle|fawn|chocolate)\b/i')->toContain('never cropped')->toContain('never docked')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.bouvier_des_flandres');
        expect($standard)->toBe(['dark grey brindle', 'brindle', 'brindle overlaid with black', 'solid black'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['grey-brindle', 'brindle', 'black-brindle', 'black'])
            // Dark grey brindle carries the highest weight → the portrait default.
            ->and(config('breed_appearance.bouvier_des_flandres.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.bouvier_des_flandres.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in dark grey brindle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BouvierDesFlandres);

        expect($traits['coat_color'])->toBe('dark grey brindle')
            ->and($portrait->prompt(BreedType::BouvierDesFlandres))->toContain('Bouvier des Flandres')->toContain('dark grey brindle')
            ->and(BreedPortraitService::relativeFile(BreedType::BouvierDesFlandres))->toBe('dog/bouvier-des-flandres.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BouvierDesFlandres);
        expect($v1['prompt_anchor'])->toContain('Bouvier des Flandres')->toContain('dark grey brindle')
            ->not->toMatch('/\b(merle|fawn|chocolate)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('dark grey brindle');

        $pet = bdfPet(102, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bouvier des Flandres')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and stays in marketing (not a welfare breed)', function () {
        $app = json_encode([
            config('breed_appearance.bouvier_des_flandres'),
            config('breed_suitability.breeds.bouvier_des_flandres'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bouvier-des-flandres')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '50%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BouvierDesFlandres);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S795) → no welfare chip, in marketing.
            ->and($tags['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(bdfData('bouvier_des_flandres.health.breed_watch.quote'))->toStartWith('Currently no points of concern')
            ->and(bdfData('proposed_game_parameters.bouvier_des_flandres.marketing.value'))->toStartWith('in the app: yes; in marketing: yes')
            // The alternative (90, RKC lower bound) is never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bouvier-des-flandres')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([102]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children and a large home; consider daily brushing, company, weight and a strong prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BouvierDesFlandres);
        expect($tags['suits'])->toBe(['children', 'large_home'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company', 'food_motivated_weight', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('family_pet')->not->toContain('active_family')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('sheds')
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('experienced_owner')->not->toContain('not_for_small_children');

        expect(bdfData('bouvier_des_flandres.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(bdfData('bouvier_des_flandres.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(bdfData('bouvier_des_flandres.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(bdfData('bouvier_des_flandres.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years')
            ->and(bdfData('bouvier_des_flandres.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(bdfData('bouvier_des_flandres.behaviour.children.quote'))->toContain('good with young children')
            ->and(bdfData('bouvier_des_flandres.behaviour.time_alone.quote'))->toContain('work from home')
            ->and(bdfData('bouvier_des_flandres.behaviour.food_motivation.quote'))->toContain('prone to being overweight');
    });
});
