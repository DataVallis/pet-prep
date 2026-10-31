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
| M5-R10-84 — Basenji (appended after the Alaskan Malamute: AKC 2024 No. 80)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `basenji` (S642–S646) and the runbook's standing rules (confirmed by David
| 2026-10-10) in `proposed_game_parameters.basenji` (exercise 60 min — PDSA "Minimum exercise (per day): 1 hour"; the RKC
| page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning
| × 0.5 (Coren rank 78, Lowest), paid breed, Border Collie care rates, brushing once a week (PDSA, M5-R13), the child's
| ear-care game once a week and tooth-brushing twice a week (WebMD, M5-R20 / M5-R15); puppy socialisation = David's general
| row; no meal gap). The senior boundary / senior arrival 108 (0.75 × PDSA lower bound "Over 12 years") is Claude's
| suggestion, verified=false, open for David. Suitability: no suits chip; consider needs_mental_stimulation /
| secure_fencing; red and white portrait; no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bjData(string $path): mixed
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

/** @return array<string, mixed> the Basenji seeder row of (stage, from, key) */
function bjRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'basenji'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("basenji.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bjPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->basenji()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bjPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bjLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Basenji;
        $config = BreedConfig::where('breed_slug', 'basenji')->sole();

        expect($breed->value)->toBe('basenji')
            ->and($breed->slug())->toBe('basenji')
            ->and(BreedType::fromSlug('basenji'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 780, 'breeds.basenji'])
            ->and($config->search_keywords)->toBe(['basenji', 'basendži', 'basendzi', 'african barkless dog', 'barkless dog', 'congo dog', 'kongovski terier', 'pes, ki ne laja'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bjData('proposed_game_parameters.basenji.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bjData('proposed_game_parameters.basenji.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Basenji pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bjPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('basenji');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'basendzi']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'african_barkless_dog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_24_120000_add_basenji_breed.php');
        // down() refuses while an Basenji exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'basenji']))->toThrow(QueryException::class);
        // The Alaskan Malamute (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'alaskan_malamute']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'basenji']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Basenji);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'basenji')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji')->count());

        BreedConfig::where('breed_slug', 'basenji')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'basenji')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'basenji')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'basenji');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'basenji'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BASENJI)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BASENJI_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_BASENJI', 'PROPOSED_R10_BASENJI_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.basenji';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bjRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bjData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bjRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bjData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bjRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bjData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PDSA lower bound "Over 12 years", S644) = 108 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bjData('basenji.lifespan.senior_from.months'))
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bjData("{$pgp}.senior_boundary_months.value"))
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bjRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bjData('basenji.lifespan.senior_from.derived_from'))->toBe(['S11', 'S644'])
            ->and(bjData('basenji.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S644')
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BASENJI_SENIOR)
            ->and(bjData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BASENJI_SENIOR)
            ->and(bjRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(bjRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bjData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bjRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bjRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bjData('basenji.exercise.adult.quote'))
            ->and(bjRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S644')
            ->and(bjRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(bjRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bjData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bjRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bjRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bjData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bjRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bjData("{$pgp}.learning_multiplier.value"))
            ->and(bjRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BASENJI) {
                $decided++;
                expect($row['breed_slug'])->toBe('basenji')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.basenji.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bjData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (brushing M5-R13,
        // teeth M5-R15, ears M5-R20); no meal gap, no nail / bath / groomer task (no frequency); socialisation = David's
        // general puppy row (the only WebMD quote is about early training).
        expect(json_encode([bjData('basenji'), bjData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bjData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bjData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(bjData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(bjData("{$pgp}.tooth_brushing.decision"))->toContain('M5-R15')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('basenji')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION)->not->toHaveKey('basenji');
    });

    it('takes the breed-level numbers from the sourced basenji entries', function () {
        // PDSA (S644) "9-11 kg" for both sexes — the FCI standard gives one ideal weight per sex.
        expect(bjRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.0, 11.0])
            ->and(bjData('basenji.adult_weight.pdsa.value'))->toEqual(['male' => [9, 11], 'female' => [9, 11]])
            ->and(bjRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bjData('basenji.adult_weight.pdsa.source_id'))
            ->and(bjRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bjData('basenji.adult_weight.pdsa.quote'))
            ->and(bjRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(bjData('basenji.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 78 (S35), Lowest tier → learning 0.5.
            ->and(bjRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(78)
            ->and(bjRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(bjData('basenji.trainability.coren_rank.quote'))
            ->and(bjData('basenji.trainability.coren_rank.value'))->toBe(78)
            ->and(bjRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('basenji.lifespan.pdsa')
            ->and(bjRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S644')
            ->and(bjRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(bjRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bjData('basenji.lifespan.pdsa.quote'));

        // Brushing once a week (PDSA "brushing once a week" → 1) — the rule, verified.
        $grooming = bjRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S644')
            ->and($grooming['quote'])->toBe(bjData('basenji.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(bjData('proposed_game_parameters.basenji.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17): David's general row (no breed quote).
            ->and(bjRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bjRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBeNull()
            // No digestion warning (no source).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Care tasks (David 2026-10-24): the child's tooth-brushing game twice a week and ear-care game once a week
        // (WebMD S646); no nail / bath / groomer task (no frequency).
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::ToothBrushingFrequency->value => [2, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->values()->all())->toBe(['S646'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['basenji']['ear_cleaning'][3])->toBe(bjData('proposed_game_parameters.basenji.ear_care.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['basenji']['tooth_brushing'][3])->toBe(bjData('proposed_game_parameters.basenji.tooth_brushing.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (108 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BASENJI_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.basenji.senior_boundary_months')
                ->and($row['value'])->toBe(bjData('proposed_game_parameters.basenji.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: none for this breed (no meal gap; David's general socialisation row, which the Border
            // Collie has as well) — listed so the comparison stays the shared one.
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('basenji'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bjPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 108-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, like the Lagotto Romagnolo and 18 months after the Alaskan Malamute (90)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('basenji', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('basenji', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('lagotto-romagnolo', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('alaskan-malamute', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('alaskan-malamute', 90))->toBe(LifeStage::Senior);
    });

    it('learns at half the mixed-breed speed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bjPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'basenji', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bjPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bjLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Basenji)
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
        foreach (['border_collie', 'basenji'] as $breed) {
            $pin = bjPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bjLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['basenji'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('basenji')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bjPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Basenji after the Alaskan Malamute with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'basenji', 'alaskan_malamute');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'basenji', 'slug' => 'basenji', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.basenji',
            'search_keywords' => seededBreedConfig('basenji')['search_keywords'], 'sort_order' => 780,
            'suitability' => [
                'suits' => [],
                'consider' => ['needs_mental_stimulation', 'secure_fencing'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S643 / AKC S645)', function () {
    it('samples DNA in the four standard colours of the appearance block only, with a short fine coat and a curled tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('basenji')
            ->and(PetDnaService::hasAppearance('basenji'))->toBeTrue()
            ->and(config('breed_appearance.basenji.verified'))->toBeFalse()
            ->and(config('breed_appearance.basenji.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('basenji', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('very fine coat')
                ->and($traits['tail'])->toContain('curled tightly')
                ->and($traits['eye_color'])->toContain('dark');

            $prompt = $prompts->imagePrompt('basenji', $traits);
            expect($prompt)->toContain('Basenji')
                ->not->toMatch('/\b(blue eyes|merle|docked|cropped|long coat|fluffy|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'chestnut red and white with white feet, chest and tail tip',
            'black and white with white feet, chest and tail tip',
            'black, tan and white tricolour with tan markings on the muzzle and cheeks',
            'brindle (black stripes on chestnut red) and white with white feet, chest and tail tip',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.basenji'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.basenji'), 'key'))->toBe(['red-white', 'black-white', 'tricolour', 'brindle-white'])
            ->and(bjData('basenji.appearance.colours.value'))->toBe(['red and white', 'black and white', 'black, tan and white (tricolour)', 'brindle and white']);
    });

    it('draws the register portrait red and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Basenji);

        expect($traits['coat_color'])->toBe('chestnut red and white with white feet, chest and tail tip')
            ->and($portrait->prompt(BreedType::Basenji))->toContain('Basenji')->toContain('chestnut red and white')
            ->and(BreedPortraitService::relativeFile(BreedType::Basenji))->toBe('dog/basenji.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Basenji);
        expect($v1['prompt_anchor'])->toContain('Basenji')->toContain('chestnut red and white')->toContain('dark almond-shaped eyes')
            ->not->toMatch('/\b(merle|docked|shaved)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(array_slice(array_column(config('breed_colours.basenji'), 'value'), 0, 3))
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = bjPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Basenji')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.basenji'),
            config('breed_suitability.breeds.basenji'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Basenji);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; PDSA "Over 12 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(bjData('proposed_game_parameters.basenji.marketing.source_id'))->toBe('S644')
            ->and(bjData('proposed_game_parameters.basenji.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic; no low-shedding statement in the sources read.
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basenji')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives no suits chip and two consider chips; never family_pet, children, active_family, long_daily_exercise, chases_small_pets or low_shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Basenji);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'secure_fencing'])
            // WebMD describes the chase but names no high / strong prey drive → no chip (runbook rule, BreedSuitabilityTest).
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('needs_company')->not->toContain('sheds');

        expect(bjData('basenji.exercise.adult.quote'))->toBe('1 hour')
            ->and(bjData('basenji.behaviour.mental_stimulation.quote'))->toContain('plenty of mental stimulation')
            ->and(bjData('basenji.behaviour.escaping.quote'))->toContain('very high fences')
            ->and(bjData('basenji.behaviour.other_pets.quote'))->toContain('chase anything that looks or acts like prey')->not->toMatch('/(high|strong) prey drive/');
    });
});
