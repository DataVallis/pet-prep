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
| M5-R10-93 — Old English Sheepdog (appended after the Chow Chow: AKC 2024 No. 89 "Old English Sheepdog")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `old_english_sheepdog` (S703–S708) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.old_english_sheepdog` (exercise 120 min — PDSA "a minimum of
| two hours", the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 0.7 (Coren rank 63, Fair), paid breed, Border Collie care rates, brushing every day (PDSA, M5-R13),
| puppy socialisation with the PDSA quote (M5-R17), the 60-minute meal / exercise gap warning (PDSA, M5-R16), the parent's
| groomer reminder every 6 weeks (PDSA, M5-R18). Open for David: the senior boundary / senior arrival 90 (0.75 × 10 y, the
| PDSA band "Over 10 years" — Claude's proposal). Not a flat-faced breed → in marketing; suits active_family / family_pet;
| consider long_daily_exercise / not_for_small_children / needs_company / frequent_grooming / chases_small_pets; grey and
| white portrait, natural tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function oesData(string $path): mixed
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

/** @return array<string, mixed> the Old English Sheepdog seeder row of (stage, from, key) */
function oesRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'old-english-sheepdog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("old-english-sheepdog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function oesPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->oldEnglishSheepdog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function oesPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function oesLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::OldEnglishSheepdog;
        $config = BreedConfig::where('breed_slug', 'old-english-sheepdog')->sole();

        expect($breed->value)->toBe('old_english_sheepdog')
            ->and($breed->slug())->toBe('old-english-sheepdog')
            ->and(BreedType::fromSlug('old-english-sheepdog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 870, 'breeds.old_english_sheepdog'])
            ->and($config->search_keywords)->toBe(['old english sheepdog', 'bobtail', 'bob-tail', 'oes', 'shepherd\'s dog', 'staroangleški ovčar', 'staroangleski ovcar', 'bobtejl', 'bobtail ovčar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) oesData('proposed_game_parameters.old_english_sheepdog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = oesData('proposed_game_parameters.old_english_sheepdog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Old English Sheepdog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = oesPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('old_english_sheepdog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bobtail']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'oes']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_10_120000_add_old_english_sheepdog_breed.php');
        // down() refuses while a Old English Sheepdog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'old_english_sheepdog']))->toThrow(QueryException::class);
        // The Chow Chow (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chow_chow']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'old_english_sheepdog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::OldEnglishSheepdog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'old-english-sheepdog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'old-english-sheepdog')->count());

        BreedConfig::where('breed_slug', 'old-english-sheepdog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'old-english-sheepdog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'old-english-sheepdog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'old-english-sheepdog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'old-english-sheepdog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'old-english-sheepdog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_OLD_ENGLISH_SHEEPDOG)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_OLD_ENGLISH_SHEEPDOG_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_OLD_ENGLISH_SHEEPDOG', 'PROPOSED_R10_OLD_ENGLISH_SHEEPDOG_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.old_english_sheepdog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(oesRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(oesData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(oesRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(oesData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(oesRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(oesData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (the lower bound of the PDSA band "Over 10 years", S705) = 90 — the closest rule row,
            // Claude's suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(oesData('old_english_sheepdog.lifespan.senior_from.months'))
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(oesData("{$pgp}.senior_boundary_months.value"))
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * oesRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(oesData('old_english_sheepdog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S705'])
            ->and(oesData('old_english_sheepdog.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S705')
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_OLD_ENGLISH_SHEEPDOG_SENIOR)
            ->and(oesData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_OLD_ENGLISH_SHEEPDOG_SENIOR)
            ->and(oesRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(oesRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(oesData("{$pgp}.exercise_minutes_adult.value"))
            ->and(oesRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(oesRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(oesData('old_english_sheepdog.suitability.pdsa_exercise.quote'))
            ->and(oesRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S705')
            ->and(oesRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(oesRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(oesData("{$pgp}.exercise_minutes_senior.value"))
            ->and(oesRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(oesRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(oesData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(oesRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(oesData("{$pgp}.learning_multiplier.value"))
            ->and(oesRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_OLD_ENGLISH_SHEEPDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('old-english-sheepdog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.old_english_sheepdog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) oesData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13), socialisation (M5-R17), the meal gap (M5-R16), the groomer
        // reminder every 6 weeks (M5-R18). No other care task (no tooth / ear / nail / bath / fold frequency), no heat warning.
        expect(json_encode([oesData('old_english_sheepdog'), oesData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(oesData("{$pgp}.grooming.value"))->toBe(7)
            ->and(oesData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(oesData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(oesData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['old_english_sheepdog'])->toBe(['S705', oesData('old_english_sheepdog.exercise.after_meals.quote')])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['old_english_sheepdog'][1])->toBe(oesData("{$pgp}.meal_exercise_gap.quote"))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['old_english_sheepdog'])->toBe(['S705', oesData('old_english_sheepdog.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['old_english_sheepdog'][1])->toBe(oesData("{$pgp}.socialisation.quote"))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['old_english_sheepdog']))->toBe(['groomer_visit'])
            ->and(array_slice(BreedStageParamsSeeder::DOG_CARE_TASKS['old_english_sheepdog']['groomer_visit'], 0, 5))
            ->toBe([1, 'six_weeks', 'S705', oesData('old_english_sheepdog.suitability.pdsa_groomer.quote'), 'proposed_game_parameters.old_english_sheepdog.professional_grooming'])
            ->and(oesData("{$pgp}.professional_grooming.quote"))->toBe(oesData('old_english_sheepdog.suitability.pdsa_groomer.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('old_english_sheepdog');
    });

    it('takes the breed-level numbers from the sourced old_english_sheepdog entries', function () {
        // PDSA (S705) "27-30 kg" for both sexes — the FCI standard gives no weight.
        expect(oesRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([27, 30])
            ->and(oesData('old_english_sheepdog.adult_weight.pdsa.value'))->toEqual(['male' => [27, 30], 'female' => [27, 30]])
            ->and(oesRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(oesData('old_english_sheepdog.adult_weight.pdsa.source_id'))
            ->and(oesRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(oesData('old_english_sheepdog.adult_weight.pdsa.quote'))
            ->and(oesRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(oesData('old_english_sheepdog.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 63 (Fair) → learning 0.7.
            ->and(oesRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(63)
            ->and(oesData('old_english_sheepdog.trainability.coren_rank.value'))->toBe(63)
            ->and(oesRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('old_english_sheepdog.lifespan.pdsa')
            ->and(oesRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S705')
            ->and(oesRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(oesRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(oesData('old_english_sheepdog.lifespan.pdsa.quote'));

        // Brushing every day: PDSA "a daily brush" → 7 (runbook rule, verified).
        $grooming = oesRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S705')
            ->and($grooming['quote'])->toBe(oesData('old_english_sheepdog.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(oesData('proposed_game_parameters.old_english_sheepdog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(oesRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(oesRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S705')
            ->and(oesRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'old-english-sheepdog');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(1)
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->first()['value'])->toBe(60)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->values()->all())->toBe([StageParamKey::GroomerVisitFrequency->value])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_OLD_ENGLISH_SHEEPDOG_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.old_english_sheepdog.senior_boundary_months')
                ->and($row['value'])->toBe(oesData('proposed_game_parameters.old_english_sheepdog.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the breed's own socialisation quote (PDSA S705).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / exercise gap (PDSA S705; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R18: the parent's groomer reminder every 6 weeks (PDSA S705).
            StageParamKey::GroomerVisitFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('old-english-sheepdog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = oesPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Chow Chow and 18 months before the Pekingese (108)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('old-english-sheepdog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('old-english-sheepdog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('chow-chow', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pekingese', 107))->toBe(LifeStage::Adult);
    });

    it('learns at 0.7 of the mixed-breed speed (Coren Fair tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = oesPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'old_english_sheepdog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = oesPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(oesLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::OldEnglishSheepdog)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 90 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'old_english_sheepdog'] as $breed) {
            $pin = oesPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(oesLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['old_english_sheepdog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('old_english_sheepdog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = oesPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Old English Sheepdog after the Chow Chow with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'old_english_sheepdog', 'chow_chow');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'old_english_sheepdog', 'slug' => 'old-english-sheepdog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.old_english_sheepdog',
            'search_keywords' => seededBreedConfig('old_english_sheepdog')['search_keywords'], 'sort_order' => 870,
            'suitability' => [
                'suits' => ['active_family', 'family_pet'],
                'consider' => ['long_daily_exercise', 'not_for_small_children', 'needs_company', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 16 S703)', function () {
    it('samples DNA in the three colours of the appearance block only, never brown or merle, with a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('old_english_sheepdog')
            ->and(PetDnaService::hasAppearance('old_english_sheepdog'))->toBeTrue()
            ->and(config('breed_appearance.old_english_sheepdog.verified'))->toBeFalse()
            ->and(config('breed_appearance.old_english_sheepdog.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('old_english_sheepdog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('natural')
                ->and($traits['coat_color'])->toContain('white head');

            $prompt = $prompts->imagePrompt('old_english_sheepdog', $traits);
            expect($prompt)->toContain('Old English Sheepdog')
                ->not->toMatch('/\b(merle|brown|docked|bobbed|curly)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The three colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.old_english_sheepdog.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(3)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.old_english_sheepdog'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.old_english_sheepdog'), 'key'))->toBe(['grey-white', 'blue-white', 'grizzle-white'])
            ->and(oesData('old_english_sheepdog.appearance.colours.value'))->toBe(['grey and white', 'grizzle and white', 'blue and white']);
    });

    it('draws the register portrait grey and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::OldEnglishSheepdog);

        expect($traits['coat_color'])->toBe('grey body and hindquarters with a white head, neck, forequarters and underbelly')
            ->and($portrait->prompt(BreedType::OldEnglishSheepdog))->toContain('Old English Sheepdog')->toContain('grey body')->toContain('natural, well-feathered tail')
            ->and(BreedPortraitService::relativeFile(BreedType::OldEnglishSheepdog))->toBe('dog/old-english-sheepdog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::OldEnglishSheepdog);
        expect($v1['prompt_anchor'])->toContain('Old English Sheepdog')->toContain('natural, well-feathered tail')
            ->not->toMatch('/\b(merle|brown|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['grey body with a white head, neck, forequarters and underbelly', 'blue-grey body with a white head, neck, forequarters and underbelly']);

        $pet = oesPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Old English Sheepdog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no flat-face chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.old_english_sheepdog'),
            config('breed_suitability.breeds.old_english_sheepdog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'old-english-sheepdog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::OldEnglishSheepdog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced (FCI muzzle "Strong, square and truncated", S703) → no breathing chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(oesData('proposed_game_parameters.old_english_sheepdog.marketing.value'))->toContain('in marketing: yes')
            // PDSA "shed surprisingly little" vs Dogs Trust "shed heavily" → neither shedding chip.
            ->and($tags['suits'])->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('sheds')
            // The alternatives (109 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'old-english-sheepdog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(109)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family pet; consider exercise, small children, company, grooming and prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::OldEnglishSheepdog);
        expect($tags['suits'])->toBe(['active_family', 'family_pet'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'not_for_small_children', 'needs_company', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('apartment')->not->toContain('large_home')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('hips_hind_legs');

        expect(oesData('old_english_sheepdog.exercise.adult.quote'))->toContain('two hours')
            ->and(oesData('old_english_sheepdog.behaviour.children.quote'))->toContain('older children')
            ->and(oesData('old_english_sheepdog.behaviour.other_pets.quote'))->toContain('prey drive')
            ->and(oesData('old_english_sheepdog.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(oesData('old_english_sheepdog.lifespan.pdsa.quote'))->toBe('Over 10 years');
    });
});
