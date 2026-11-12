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
| M5-R10-107 — Japanese Chin (appended after the Borzoi: AKC 2024 No. 103 "Japanese Chin")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `japanese_chin` (S805–S810) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.japanese_chin` (exercise 30 min — RKC "Up to 30 minutes
| per day" —, senior 23 min, puppy / young 10 min × age capped at 30, stages 9 / 36 / 113 (0.75 × McMillan 2024 median
| 12.5 y, Dogs Trust S123), arrival 2 / 9 / 36 / 113, learning × 0.7 (Coren rank 62, Fair), paid breed, Border Collie
| care rates, brushing 2× a week (RKC "More than once a week" → PetMD "once or twice a week", M5-R13), ear cleaning every
| 2 weeks and a bath reminder every 4 weeks (PetMD S809, M5-R18), the summer-midday walk warning (PetMD S809, M5-R14),
| puppy socialisation with the PetMD quote (S809, M5-R17), no meal gap. Flat-faced welfare breed → brachycephalic_breathing,
| not in marketing (BVA S92); black and white portrait with a moderate face.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function jchData(string $path): mixed
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

/** @return array<string, mixed> the Japanese Chin seeder row of (stage, from, key) */
function jchRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'japanese-chin'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("japanese_chin.{$stage}.{$from}.{$key->value}");

    return $row;
}

function jchPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->japaneseChin()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function jchPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function jchLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::JapaneseChin;
        $config = BreedConfig::where('breed_slug', 'japanese-chin')->sole();

        expect($breed->value)->toBe('japanese_chin')
            ->and($breed->slug())->toBe('japanese-chin')
            ->and(BreedType::fromSlug('japanese-chin'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1000, 'breeds.japanese_chin'])
            ->and($config->search_keywords)->toBe(['japanese chin', 'japanese spaniel', 'japonski čin', 'japonski cin'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) jchData('proposed_game_parameters.japanese_chin.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = jchData('proposed_game_parameters.japanese_chin.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Japanese Chin pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = jchPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('japanese_chin');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'chin']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'japonski_cin']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_28_120000_add_japanese_chin_breed.php');
        // down() refuses while a Japanese Chin exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'japanese_chin']))->toThrow(QueryException::class);
        // The Borzoi (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'borzoi']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'japanese_chin']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::JapaneseChin);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'japanese-chin')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'japanese-chin')->count());

        BreedConfig::where('breed_slug', 'japanese-chin')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'japanese-chin')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'japanese-chin')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'japanese-chin');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'japanese-chin');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'japanese-chin'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_JAPANESE_CHIN)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_JAPANESE_CHIN');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.japanese_chin';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(jchRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(jchData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(jchRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(jchData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(jchRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(jchData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 12.5 y × 12) = 113 (McMillan 2024 median via Dogs Trust S123) → runbook rule, verified.
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(jchData('japanese_chin.lifespan.senior_from.months'))
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * jchRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(jchData('japanese_chin.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', jchData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_JAPANESE_CHIN)
            ->and(jchRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(jchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(113)
            // The RKC lower bound (90) stays an alternative.
            ->and(jchData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_rkc_lower_bound_10_y' => 90]);

        expect(jchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(jchData("{$pgp}.exercise_minutes_adult.value"))
            ->and(jchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(jchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(jchData('japanese_chin.exercise.adult.quote'))
            ->and(jchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(jchData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(jchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S805')
            ->and(jchRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(jchData("{$pgp}.exercise_minutes_senior.value"))
            ->and(jchRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            ->and(jchRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(jchData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(jchRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(jchData("{$pgp}.learning_multiplier.value"))
            ->and(jchRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_JAPANESE_CHIN) {
                $decided++;
                expect($row['breed_slug'])->toBe('japanese-chin')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.japanese_chin.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) jchData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 2× a week (M5-R13), ear cleaning every 2 weeks (M5-R18, child) and a bath
        // reminder every 4 weeks (M5-R18, parent), the summer-midday walk warning (M5-R14) and puppy socialisation with the
        // PetMD quote (M5-R17). No meal gap (no quote), no nail / tooth / groomer task (no frequency).
        expect(json_encode([jchData('japanese_chin'), jchData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(jchData("{$pgp}.grooming.value"))->toBe(2)
            ->and(jchData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('japanese_chin')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['japanese_chin'])->toBe(['S809', jchData("{$pgp}.socialisation.quote")])
            ->and(jchData("{$pgp}.socialisation.quote"))->toBe(jchData('japanese_chin.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT['japanese_chin'])->toBe(['S809', jchData("{$pgp}.no_exercise_in_heat.quote"), "{$pgp}.no_exercise_in_heat"])
            ->and(jchData("{$pgp}.no_exercise_in_heat.quote"))->toBe(jchData('japanese_chin.exercise.heat_and_walk_timing.quote'))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']))->toBe(['ear_cleaning', 'bath'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['ear_cleaning'][0])->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['ear_cleaning'][1])->toBe('two_weeks')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['ear_cleaning'][3])->toBe(jchData("{$pgp}.ear_care.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['bath'][0])->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['bath'][1])->toBe('month')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['japanese_chin']['bath'][3])->toBe(jchData("{$pgp}.bathing.quote"))
            ->and(jchData("{$pgp}.ear_care.quote"))->toBe(jchData('japanese_chin.suitability.petmd_ears.quote'))
            ->and(jchData("{$pgp}.bathing.quote"))->toBe(jchData('japanese_chin.suitability.petmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced japanese_chin entries', function () {
        // RKC breed standard (S806): ideal weight 1.8–3.2 kg (both sexes).
        expect(jchRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([1.8, 3.2])
            ->and(jchData('japanese_chin.adult_weight.rkc.value'))->toEqual(['male' => [1.8, 3.2], 'female' => [1.8, 3.2]])
            ->and(jchRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(jchData('japanese_chin.adult_weight.rkc.source_id'))
            ->and(jchRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(jchData('japanese_chin.adult_weight.rkc.quote'))
            ->and(jchRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(jchData('japanese_chin.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            ->and(jchRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(62)
            ->and(jchData('japanese_chin.trainability.coren_rank.value'))->toBe(62)
            ->and(jchRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('japanese_chin.lifespan.median_uk')
            ->and(jchRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(jchRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.5)
            ->and(jchRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(jchData('japanese_chin.lifespan.median_uk.quote'));

        // Brushing 2× a week: RKC "More than once a week" has no number → PetMD "once or twice a week" → 2.
        $grooming = jchRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S809')
            ->and($grooming['quote'])->toBe(jchData('japanese_chin.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(jchData('proposed_game_parameters.japanese_chin.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(jchRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(jchRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S809')
            ->and(jchRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'japanese-chin');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(1)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S809 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14: the summer-midday walk warning (PetMD S809).
            StageParamKey::NoWalkInHeat,
        ];
        // Care-task frequency rows are breed-specific and skipped (ear cleaning, bath).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('japanese-chin'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = jchPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 112 months' => [112, LifeStage::Adult, 30, 3000],
        'senior 113 months' => [113, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 113 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('japanese-chin', 112))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('japanese-chin', 113))->toBe(LifeStage::Senior);
    });

    it('learns 0.7× as fast (Coren rank 62, Fair) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = jchPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'japanese_chin', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = jchPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(jchLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::JapaneseChin)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 113 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'japanese_chin'] as $breed) {
            $pin = jchPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(jchLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['japanese_chin'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(113)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('japanese_chin')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = jchPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Japanese Chin after the Borzoi with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'japanese_chin', 'borzoi');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'japanese_chin', 'slug' => 'japanese-chin', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.japanese_chin',
            'search_keywords' => seededBreedConfig('japanese_chin')['search_keywords'], 'sort_order' => 1000,
            'suitability' => [
                'suits' => ['apartment', 'children'],
                'consider' => ['brachycephalic_breathing', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — RKC breed standard S806, FCI-Standard N° 206 S808)', function () {
    it('samples DNA in the two standard colours only with a moderate face, never tricolour', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('japanese_chin')
            ->and(PetDnaService::hasAppearance('japanese_chin'))->toBeTrue()
            ->and(config('breed_appearance.japanese_chin.verified'))->toBeFalse()
            ->and(config('breed_appearance.japanese_chin.prompt_order'))->toContain('muzzle')->toContain('ear_carriage')->toContain('tail');

        $standard = array_column(config('breed_appearance.japanese_chin.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('japanese_chin', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('japanese_chin', $traits);
            expect($prompt)->toContain('Japanese Chin')
                ->not->toMatch('/\b(merle|tricolou?r|brindle)\b/i')->toContain('open nostrils')->toContain('visible muzzle')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.japanese_chin');
        expect($standard)->toBe(['black and white', 'red and white'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-white', 'red-white'])
            // Black and white carries the highest weight → the portrait default.
            ->and(config('breed_appearance.japanese_chin.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.japanese_chin.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in black and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::JapaneseChin);

        expect($traits['coat_color'])->toBe('black and white')
            ->and($portrait->prompt(BreedType::JapaneseChin))->toContain('Japanese Chin')->toContain('black and white')->toContain('open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::JapaneseChin))->toBe('dog/japanese-chin.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::JapaneseChin);
        expect($v1['prompt_anchor'])->toContain('Japanese Chin')->toContain('black and white')->toContain('open nostrils')
            ->not->toMatch('/\b(merle|tricolou?r)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black and white');

        $pet = jchPet(113, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Japanese Chin')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare breed, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and leaves the flat-faced breed out of marketing (BVA S92)', function () {
        $app = json_encode([
            config('breed_appearance.japanese_chin'),
            config('breed_suitability.breeds.japanese_chin'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'japanese-chin')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '50%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::JapaneseChin);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Flat-faced (PetMD S809) → the breathing chip and no marketing, although RKC Breed Watch is Category 1.
            ->and($tags['consider'])->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(jchData('japanese_chin.health.brachycephaly.quote'))->toContain('flat-faced (brachycephalic)')
            ->and(jchData('japanese_chin.health.breed_watch.quote'))->toStartWith('Currently no points of concern')
            ->and(jchData('proposed_game_parameters.japanese_chin.marketing.value'))->toBe('in the app: yes; in marketing / store screenshots / social: no')
            ->and(jchData('proposed_game_parameters.japanese_chin.marketing.source_id'))->toBe('S92')
            // The alternative (90, RKC lower bound) is never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'japanese-chin')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([113]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an apartment and children; consider the flat face, company and shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::JapaneseChin);
        expect($tags['suits'])->toBe(['apartment', 'children'])
            ->and($tags['consider'])->toBe(['brachycephalic_breathing', 'needs_company', 'sheds'])
            ->and($tags['suits'])->not->toContain('family_pet')->not->toContain('active_family')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming')
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('large_home')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('eyelid_problems');

        expect(jchData('japanese_chin.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(jchData('japanese_chin.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(jchData('japanese_chin.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and(jchData('japanese_chin.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years')
            ->and(jchData('japanese_chin.behaviour.children.quote'))->toContain('generally good with children')
            ->and(jchData('japanese_chin.behaviour.time_alone.quote'))->toContain('separation anxiety');
    });
});
