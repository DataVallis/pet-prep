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
| M5-R10-110 — Schipperke (appended after the Miniature Bull Terrier: AKC 2024 No. 106 "Schipperke")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `schipperke` (S824–S829, S771) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.schipperke` (exercise 60 min — RKC "Up to 1 hour per
| day" —, senior 45 min, puppy / young 10 min × age capped at 60, stages 9 / 36 / 128 (0.75 × McMillan 2024 median
| 14.2 y, S771), arrival 2 / 9 / 36 / 128, learning × 1.5 (Coren rank 15, Excellent), paid breed, Border Collie care
| rates, brushing 2× a week (RKC "More than once a week" → VCA "at least twice a week", M5-R13), tooth brushing every
| day (M5-R15), ear cleaning every 2 weeks and a bath reminder every 4 weeks (M5-R18) from PetMD S827, puppy
| socialisation with the PetMD quote (S827, M5-R17), no meal gap, no heat warning. Not a welfare breed (RKC Breed
| Watch Category 1) → in marketing; black portrait with erect ears.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function skData(string $path): mixed
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

/** @return array<string, mixed> the Schipperke seeder row of (stage, from, key) */
function skRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'schipperke'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("schipperke.{$stage}.{$from}.{$key->value}");

    return $row;
}

function skPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->schipperke()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function skPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function skLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Schipperke;
        $config = BreedConfig::where('breed_slug', 'schipperke')->sole();

        expect($breed->value)->toBe('schipperke')
            ->and($breed->slug())->toBe('schipperke')
            ->and(BreedType::fromSlug('schipperke'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1030, 'breeds.schipperke'])
            ->and($config->search_keywords)->toBe(['schipperke', 'siperke', 'šiperke', 'little captain'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) skData('proposed_game_parameters.schipperke.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = skData('proposed_game_parameters.schipperke.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Schipperke pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = skPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('schipperke');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'little_captain']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'spitzke']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_31_120000_add_schipperke_breed.php');
        // down() refuses while a Schipperke exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'schipperke']))->toThrow(QueryException::class);
        // The Miniature Bull Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'miniature_bull_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'schipperke']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Schipperke);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'schipperke')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'schipperke')->count());

        BreedConfig::where('breed_slug', 'schipperke')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'schipperke')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'schipperke')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'schipperke');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'schipperke');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'schipperke'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SCHIPPERKE)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_SCHIPPERKE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.schipperke';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(skRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(skData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(skRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(skData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(skRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(skData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 14.2 y × 12) = 128 (McMillan 2024 median, S771) → runbook rule, verified.
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(skData('schipperke.lifespan.senior_from.months'))
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * skRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(skData('schipperke.lifespan.senior_from.derived_from'))->toBe(['S11', 'S771'])
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', skData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SCHIPPERKE)
            ->and(skRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(skRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(128)
            // Dogs Trust's 7 y stays an alternative.
            ->and(skData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_dogs_trust_7_y' => 84]);

        expect(skRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(skData("{$pgp}.exercise_minutes_adult.value"))
            ->and(skRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(skRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(skData('schipperke.exercise.adult.quote'))
            ->and(skRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(skData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(skRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S826')
            ->and(skRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(skData("{$pgp}.exercise_minutes_senior.value"))
            ->and(skRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(skRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(skData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(skRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(skData("{$pgp}.learning_multiplier.value"))
            ->and(skRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SCHIPPERKE) {
                $decided++;
                expect($row['breed_slug'])->toBe('schipperke')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.schipperke.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) skData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 2× a week (M5-R13), tooth brushing every day (M5-R15), ear cleaning every
        // 2 weeks and a bath reminder every 4 weeks (M5-R18) from PetMD S827, puppy socialisation with the PetMD quote
        // (M5-R17). No meal gap and no heat warning (no quote), no nail / groomer task (no frequency).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['schipperke'];
        expect(json_encode([skData('schipperke'), skData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(skData("{$pgp}.grooming.value"))->toBe(2)
            ->and(skData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('schipperke')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('schipperke')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['schipperke'])->toBe(['S827', skData("{$pgp}.socialisation.quote")])
            ->and(skData("{$pgp}.socialisation.quote"))->toBe(skData('schipperke.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath'])
            ->and([$tasks['tooth_brushing'][0], $tasks['tooth_brushing'][1]])->toBe([7, 'day'])
            ->and([$tasks['ear_cleaning'][0], $tasks['ear_cleaning'][1]])->toBe([1, 'two_weeks'])
            ->and([$tasks['bath'][0], $tasks['bath'][1]])->toBe([1, 'month'])
            ->and($tasks['tooth_brushing'][3])->toBe(skData("{$pgp}.tooth_brushing.quote"))
            ->and($tasks['ear_cleaning'][3])->toBe(skData("{$pgp}.ear_care.quote"))
            ->and($tasks['bath'][3])->toBe(skData("{$pgp}.bathing.quote"))
            ->and(skData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(skData("{$pgp}.ear_care.value"))->toBe([1, 14])
            ->and(skData("{$pgp}.bathing.value"))->toBe([1, 28])
            ->and(skData("{$pgp}.tooth_brushing.quote"))->toBe(skData('schipperke.suitability.petmd_teeth.quote'))
            ->and(skData("{$pgp}.ear_care.quote"))->toBe(skData('schipperke.suitability.petmd_ears.quote'))
            ->and(skData("{$pgp}.bathing.quote"))->toBe(skData('schipperke.suitability.petmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced schipperke entries', function () {
        // FCI-Standard N° 83 (S825): 3–9 kg (both sexes).
        expect(skRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3, 9])
            ->and(skData('schipperke.adult_weight.fci.value'))->toEqual(['male' => [3, 9], 'female' => [3, 9]])
            ->and(skRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(skData('schipperke.adult_weight.fci.source_id'))
            ->and(skRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(skData('schipperke.adult_weight.fci.quote'))
            ->and(skRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(skData('schipperke.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(skRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(15)
            ->and(skData('schipperke.trainability.coren_rank.value'))->toBe(15)
            ->and(skRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('schipperke.lifespan.median_uk')
            ->and(skRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S771')
            ->and(skRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.2)
            ->and(skRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(skData('schipperke.lifespan.median_uk.quote'));

        // Brushing 2× a week: RKC "More than once a week" has no number → VCA "at least twice a week" → 2.
        $grooming = skRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S828')
            ->and($grooming['quote'])->toBe(skData('schipperke.suitability.vca_grooming.quote'))
            ->and($grooming['quote'])->toBe(skData('proposed_game_parameters.schipperke.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(skRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(skRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S827')
            ->and(skRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'schipperke');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::ToothBrushingFrequency->value, StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S827 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        // Care-task frequency rows are breed-specific and skipped (teeth, ears, bath).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('schipperke'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = skPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 127 months' => [127, LifeStage::Adult, 60, 6000],
        'senior 128 months' => [128, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 128 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('schipperke', 127))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('schipperke', 128))->toBe(LifeStage::Senior);
    });

    it('learns 1.5× as fast (Coren rank 15, Excellent) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = skPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'schipperke', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = skPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(skLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Schipperke)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 128 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'schipperke'] as $breed) {
            $pin = skPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(skLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['schipperke'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(128)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('schipperke')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = skPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Schipperke after the Miniature Bull Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'schipperke', 'miniature_bull_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'schipperke', 'slug' => 'schipperke', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.schipperke',
            'search_keywords' => seededBreedConfig('schipperke')['search_keywords'], 'sort_order' => 1030,
            'suitability' => [
                'suits' => ['apartment', 'family_pet', 'children'],
                'consider' => ['needs_mental_stimulation', 'sheds', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 83 S825)', function () {
    it('samples DNA in black only, never white spots, merle or brindle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('schipperke')
            ->and(PetDnaService::hasAppearance('schipperke'))->toBeTrue()
            ->and(config('breed_appearance.schipperke.verified'))->toBeFalse()
            ->and(config('breed_appearance.schipperke.prompt_order'))->toContain('ear_carriage')->toContain('tail')->not->toContain('muzzle');

        $standard = array_column(config('breed_appearance.schipperke.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('schipperke', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('schipperke', $traits);
            expect($prompt)->toContain('Schipperke')->toContain('erect')->toContain('black')
                ->not->toMatch('/\b(merle|tricolou?r|brindle|white spot)/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.schipperke');
        expect($standard)->toBe(['black'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black']);
    });

    it('draws the register portrait in black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Schipperke);

        expect($traits['coat_color'])->toBe('black')
            ->and($portrait->prompt(BreedType::Schipperke))->toContain('Schipperke')->toContain('black')->toContain('erect')
            ->and(BreedPortraitService::relativeFile(BreedType::Schipperke))->toBe('dog/schipperke.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Schipperke);
        expect($v1['prompt_anchor'])->toContain('Schipperke')->toContain('black')->toContain('erect')
            ->not->toMatch('/\b(merle|tricolou?r)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black');

        $pet = skPet(128, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Schipperke')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and keeps the breed in marketing', function () {
        $app = json_encode([
            config('breed_appearance.schipperke'),
            config('breed_suitability.breeds.schipperke'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'schipperke')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '7.41', '4.02'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Schipperke);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced; RKC Breed Watch Category 1 → no welfare chip.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(skData('schipperke.health.breed_watch.value'))->toStartWith('RKC Breed Watch Category 1')
            ->and(skData('proposed_game_parameters.schipperke.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            // The alternative (84) is never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'schipperke')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([128]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an apartment, a family and children; consider mental stimulation, shedding, grooming and the prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Schipperke);
        expect($tags['suits'])->toBe(['apartment', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'sheds', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('active_family')->not->toContain('low_shedding')->not->toContain('small_children')
            ->and($tags['suits'])->not->toContain('often_alone')->not->toContain('large_home')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')
            ->and($tags['consider'])->not->toContain('secure_fencing')->not->toContain('needs_company');

        expect(skData('schipperke.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(skData('schipperke.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(skData('schipperke.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and(skData('schipperke.suitability.rkc_lifespan.quote'))->toBe('Lifespan: Over 12 years')
            ->and(skData('schipperke.behaviour.children.quote'))->toBe('very gentle with children')
            // A quote naming a high prey drive → chases_small_pets (runbook).
            ->and(skData('schipperke.behaviour.other_pets.quote'))->toContain('high prey drive');
    });
});
