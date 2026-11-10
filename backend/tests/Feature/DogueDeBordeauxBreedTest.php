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
| M5-R10-100 — Dogue de Bordeaux (appended after the Dogo Argentino, blocked: AKC 2024 No. 96 "Dogue de Bordeaux")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `dogue_de_bordeaux` (S754–S759) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.dogue_de_bordeaux` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 50 (0.75 × VetCompass median 5.5 y, S212 —
| provisional, RKC / PDSA "Over 10 years" conflict), arrival 2 / 9 / 36 / 50, learning × 1.0 (not ranked by Coren),
| paid breed, Border Collie care rates, brushing 1× a week (RKC "Once a week", M5-R13), meal / walk gap (PDSA S757,
| M5-R16), puppy socialisation with the PDSA quote (S757, M5-R17), the summer-midday walk warning (PetMD S759, M5-R14).
| No care task (no source states a frequency). Welfare breed (RKC Breed Watch Category 3, brachycephalic) → not in
| marketing (BVA S92); suits large_home; mahogany fawn portrait with a moderate muzzle.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ddbData(string $path): mixed
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

/** @return array<string, mixed> the Dogue de Bordeaux seeder row of (stage, from, key) */
function ddbRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'dogue-de-bordeaux'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("dogue-de-bordeaux.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ddbPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->dogueDeBordeaux()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ddbPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ddbLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::DogueDeBordeaux;
        $config = BreedConfig::where('breed_slug', 'dogue-de-bordeaux')->sole();

        expect($breed->value)->toBe('dogue_de_bordeaux')
            ->and($breed->slug())->toBe('dogue-de-bordeaux')
            ->and(BreedType::fromSlug('dogue-de-bordeaux'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 930, 'breeds.dogue_de_bordeaux'])
            ->and($config->search_keywords)->toBe(['dogue de bordeaux', 'bordeaux', 'bordeaux dog', 'bordojska doga', 'bordoška doga', 'bordoska doga'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ddbData('proposed_game_parameters.dogue_de_bordeaux.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ddbData('proposed_game_parameters.dogue_de_bordeaux.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Lhasa Apso pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ddbPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('dogue_de_bordeaux');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bordeaux_mastiff']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'french_mastiff']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_19_120000_add_dogue_de_bordeaux_breed.php');
        // down() refuses while a Dogue de Bordeaux exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'dogue_de_bordeaux']))->toThrow(QueryException::class);
        // The Lhasa Apso (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'lhasa_apso']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dogue_de_bordeaux']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::DogueDeBordeaux);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'dogue-de-bordeaux')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dogue-de-bordeaux')->count());

        BreedConfig::where('breed_slug', 'dogue-de-bordeaux')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'dogue-de-bordeaux')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'dogue-de-bordeaux')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'dogue-de-bordeaux');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dogue-de-bordeaux');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'dogue-de-bordeaux'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DOGUE_DE_BORDEAUX)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_DOGUE_DE_BORDEAUX');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.dogue_de_bordeaux';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ddbRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ddbData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ddbRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ddbData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ddbRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ddbData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 5.5 y × 12) = round(49.5) = 50 (VetCompass median, S212) — the runbook order (McMillan 2024 not
            // reachable → a VetCompass median before the RKC band) → confirmed rule, verified (provisional, open for David).
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ddbData('dogue_de_bordeaux.lifespan.senior_from.months'))
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ddbRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ddbData('dogue_de_bordeaux.lifespan.senior_from.derived_from'))->toBe(['S11', 'S212'])
            ->and(ddbData('dogue_de_bordeaux.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', ddbData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_DOGUE_DE_BORDEAUX)
            ->and(ddbRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(ddbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(50)
            // The conflicting RKC band (90) and the Dogs Trust rule of thumb (84) stay alternatives.
            ->and(ddbData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_rkc_over_10_y' => 90, 'senior_dogs_trust_7_y' => 84]);

        expect(ddbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ddbData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ddbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ddbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ddbData('dogue_de_bordeaux.exercise.adult.quote'))
            ->and(ddbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ddbData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ddbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S756')
            ->and(ddbRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ddbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ddbData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ddbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(ddbRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ddbData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(ddbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ddbData("{$pgp}.learning_multiplier.value"))
            ->and(ddbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_DOGUE_DE_BORDEAUX) {
                $decided++;
                expect($row['breed_slug'])->toBe('dogue-de-bordeaux')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.dogue_de_bordeaux.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ddbData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 1× a week (M5-R13, RKC / PDSA), the meal / walk gap (M5-R16, PDSA), puppy
        // socialisation with the PDSA quote (M5-R17), the summer-midday walk warning (M5-R14, PetMD). No care task: face folds
        // "regularly", ears "ask your vet" / "occasional", nails / bath without a number.
        expect(json_encode([ddbData('dogue_de_bordeaux'), ddbData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ddbData("{$pgp}.grooming.value"))->toBe(1)
            ->and(ddbData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['dogue_de_bordeaux'])->toBe(['S757', ddbData("{$pgp}.meal_exercise_gap.quote")])
            ->and(ddbData("{$pgp}.meal_exercise_gap.quote"))->toBe(ddbData('dogue_de_bordeaux.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['dogue_de_bordeaux'])->toBe(['S757', ddbData("{$pgp}.socialisation.quote")])
            ->and(ddbData("{$pgp}.socialisation.quote"))->toBe(ddbData('dogue_de_bordeaux.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('dogue_de_bordeaux')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT['dogue_de_bordeaux'])->toBe(['S759', ddbData("{$pgp}.no_exercise_in_heat.quote"), "{$pgp}.no_exercise_in_heat"])
            ->and(ddbData("{$pgp}.no_exercise_in_heat.quote"))->toBe(ddbData('dogue_de_bordeaux.exercise.heat_and_walk_timing.quote'));
    });

    it('takes the breed-level numbers from the sourced dogue_de_bordeaux entries', function () {
        // FCI / PDSA give minimums only → PetMD (S759) 99–145 lb = 44.9–65.8 kg.
        expect(ddbRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([44.9, 65.8])
            ->and(ddbData('dogue_de_bordeaux.adult_weight.petmd.value'))->toEqual(['male' => [49.9, 65.8], 'female' => [44.9, 54.4]])
            ->and(ddbData('dogue_de_bordeaux.adult_weight.fci_minimum.value'))->toEqual(['male' => 50, 'female' => 45])
            ->and(ddbRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ddbData('dogue_de_bordeaux.adult_weight.petmd.source_id'))
            ->and(ddbRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ddbData('dogue_de_bordeaux.adult_weight.petmd.quote'))
            ->and(ddbRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(ddbData('dogue_de_bordeaux.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Not ranked by Coren → learning 1.0.
            ->and(ddbRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(ddbData('dogue_de_bordeaux.trainability.coren_rank.value'))->toBeNull()
            ->and(ddbRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('dogue_de_bordeaux.lifespan.median_uk')
            ->and(ddbRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S212')
            ->and(ddbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(5.5)
            ->and(ddbRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ddbData('dogue_de_bordeaux.lifespan.median_uk.quote'));

        // Brushing 1× a week: RKC "Grooming: Once a week" → 1 (PDSA / PetMD weekly agree).
        $grooming = ddbRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S756')
            ->and($grooming['quote'])->toBe(ddbData('dogue_de_bordeaux.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(ddbData('proposed_game_parameters.dogue_de_bordeaux.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(ddbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ddbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S757')
            ->and(ddbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dogue-de-bordeaux');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap->count())->toBe(1)
            ->and($gap[0]['value'])->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP_MINUTES)
            ->and($gap[0]['source_id'])->toBe('S757')
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(1)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
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
            // M5-R17: socialisation (PDSA S757 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / walk gap (PDSA S757); M5-R14: the summer-midday walk warning (PetMD S759).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('dogue-de-bordeaux'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ddbPet($arrival);
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
        'adult 49 months' => [49, LifeStage::Adult, 60, 6000],
        'senior 50 months' => [50, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 50 months, a little earlier than the Great Dane (54)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('dogue-de-bordeaux', 49))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('dogue-de-bordeaux', 50))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('great-dane', 50))->toBe(LifeStage::Adult);
    });

    it('learns at 1.0 × the mixed-breed speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ddbPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'dogue_de_bordeaux', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ddbPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ddbLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::DogueDeBordeaux)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 50 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'dogue_de_bordeaux'] as $breed) {
            $pin = ddbPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ddbLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['dogue_de_bordeaux'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(50)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('dogue_de_bordeaux')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ddbPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Dogue de Bordeaux after the Lhasa Apso with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'dogue_de_bordeaux', 'lhasa_apso');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'dogue_de_bordeaux', 'slug' => 'dogue-de-bordeaux', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.dogue_de_bordeaux',
            'search_keywords' => seededBreedConfig('dogue_de_bordeaux')['search_keywords'], 'sort_order' => 930,
            'suitability' => [
                'suits' => ['large_home'],
                'consider' => ['short_muzzle_breathing', 'sensitive_skin', 'hips_hind_legs', 'not_for_small_children', 'experienced_owner', 'drools', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 116 S755)', function () {
    it('samples DNA in the four standard fawn shades only, never brindle, with a moderate muzzle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('dogue_de_bordeaux')
            ->and(PetDnaService::hasAppearance('dogue_de_bordeaux'))->toBeTrue()
            ->and(config('breed_appearance.dogue_de_bordeaux.verified'))->toBeFalse()
            ->and(config('breed_appearance.dogue_de_bordeaux.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.dogue_de_bordeaux.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('dogue_de_bordeaux', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('dogue_de_bordeaux', $traits);
            expect($prompt)->toContain('Dogue de Bordeaux')->toContain('clearly open nostrils')->toContain('only moderate wrinkles')
                ->not->toMatch('/\b(merle|brindle|solid brown|heavy wrinkles?|flat face)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.dogue_de_bordeaux');
        expect($standard)->toBe(['mahogany fawn with a brown mask', 'fawn with a black mask', 'fawn without a mask', 'pale isabella fawn'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['mahogany-brown-mask', 'fawn-black-mask', 'fawn-no-mask', 'isabella'])
            // Mahogany fawn with a brown mask carries the highest weight → the portrait default.
            ->and(config('breed_appearance.dogue_de_bordeaux.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.dogue_de_bordeaux.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in mahogany fawn with a brown mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::DogueDeBordeaux);

        expect($traits['coat_color'])->toBe('mahogany fawn with a brown mask')
            ->and($portrait->prompt(BreedType::DogueDeBordeaux))->toContain('Dogue de Bordeaux')->toContain('mahogany fawn')
            ->and(BreedPortraitService::relativeFile(BreedType::DogueDeBordeaux))->toBe('dog/dogue-de-bordeaux.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::DogueDeBordeaux);
        expect($v1['prompt_anchor'])->toContain('Dogue de Bordeaux')->toContain('mahogany fawn')->toContain('clearly open nostrils')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('hazel to dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('mahogany fawn');

        $pet = ddbPet(50, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Dogue de Bordeaux')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has the welfare chips and stays out of marketing (BVA S92)', function () {
        $app = json_encode([
            config('breed_appearance.dogue_de_bordeaux'),
            config('breed_suitability.breeds.dogue_de_bordeaux'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dogue-de-bordeaux')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::DogueDeBordeaux);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 3 (S756), brachycephalic (S758), short muzzle (S759) → welfare chips + BVA exclusion.
            ->and($tags['consider'])->toContain('short_muzzle_breathing')->toContain('sensitive_skin')->toContain('hips_hind_legs')
            ->and(ddbData('dogue_de_bordeaux.health.breed_watch.quote'))->toBe('Category 3')
            ->and(ddbData('proposed_game_parameters.dogue_de_bordeaux.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and(ddbData('proposed_game_parameters.dogue_de_bordeaux.marketing.source_id'))->toBe('S92')
            // The alternatives (90 RKC band, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dogue-de-bordeaux')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a large house; consider the short muzzle, skin, hips, small children, experience, drool, shedding and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::DogueDeBordeaux);
        expect($tags['suits'])->toBe(['large_home'])
            ->and($tags['consider'])->toBe(['short_muzzle_breathing', 'sensitive_skin', 'hips_hind_legs', 'not_for_small_children', 'experienced_owner', 'drools', 'sheds', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')
            ->not->toContain('apartment')->not->toContain('first_time_owner')->not->toContain('other_pets')
            // Conflicting lifespan sources → no shorter_lifespan chip (open for David); not flat-faced wording → no brachycephalic_breathing.
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('brachycephalic_breathing')
            ->not->toContain('needs_company')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming');

        expect(ddbData('dogue_de_bordeaux.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(ddbData('dogue_de_bordeaux.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(ddbData('dogue_de_bordeaux.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years')
            ->and(ddbData('dogue_de_bordeaux.behaviour.other_pets.quote'))->toContain('very high prey drive');
    });
});
