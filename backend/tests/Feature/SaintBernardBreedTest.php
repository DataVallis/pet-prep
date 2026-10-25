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
| M5-R10-66 — Saint Bernard (appended after the German Wirehaired Pointer: AKC 2024 No. 62)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `saint_bernard` (S527–S535) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.saint_bernard` (exercise 60 min — RKC "Up to 1 hour per
| day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning × 0.7 (Coren rank
| 65, Fair), paid breed, Border Collie care rates, brushing 3× a week (RKC "More than once a week" → PDSA "at least three
| times a week", M5-R13), puppy socialisation with the PDSA quote (M5-R17), the digestion warning with the PDSA gap quote
| (M5-R16), ear cleaning every 2 weeks (PetMD "every two to three weeks", M5-R20)). The senior boundary / senior arrival
| 63 (0.75 × Adams 2010 survey median 7.0 y — RKC "Under 10 years" has no lower bound) is Claude's suggestion,
| verified=false, open for David. Suitability: suits family_pet / children / large_home; consider shorter_lifespan,
| eyelid_problems, hips_hind_legs (welfare rule, RKC Breed Watch Category 3), heat_sensitive, needs_company, sheds,
| frequent_grooming, drools; red and white portrait; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function stbData(string $path): mixed
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

/** @return array<string, mixed> the Saint Bernard seeder row of (stage, from, key) */
function stbRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'saint-bernard'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("saint_bernard.{$stage}.{$from}.{$key->value}");

    return $row;
}

function stbPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->saintBernard()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function stbPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function stbLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SaintBernard;
        $config = BreedConfig::where('breed_slug', 'saint-bernard')->sole();

        expect($breed->value)->toBe('saint_bernard')
            ->and($breed->slug())->toBe('saint-bernard')
            ->and(BreedType::fromSlug('saint-bernard'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 620, 'breeds.saint_bernard'])
            ->and($config->search_keywords)->toBe(['saint bernard', 'st. bernard', 'st bernard', 'st bernhardshund', 'bernhardiner', 'bernardinec', 'sveti bernard', 'pes svetega bernarda'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) stbData('proposed_game_parameters.saint_bernard.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = stbData('proposed_game_parameters.saint_bernard.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Saint Bernard pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = stbPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('saint_bernard');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'st_bernard']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bernhardiner']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_29_120000_add_saint_bernard_breed.php');
        // down() refuses while a Saint Bernard exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'saint_bernard']))->toThrow(QueryException::class);
        // The German Wirehaired Pointer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_wirehaired_pointer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'saint_bernard']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SaintBernard);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'saint-bernard')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saint-bernard')->count());

        BreedConfig::where('breed_slug', 'saint-bernard')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'saint-bernard')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'saint-bernard')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'saint-bernard');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saint-bernard');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'saint-bernard'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SAINT_BERNARD)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_SAINT_BERNARD_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_SAINT_BERNARD', 'PROPOSED_R10_SAINT_BERNARD_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.saint_bernard';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(stbRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(stbData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(stbRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stbData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(stbRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stbData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 7.0 y (Adams 2010 survey median, S534) = 63 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass value reachable, RKC "Under 10 years" has no lower bound)
            // → verified=false, open for David.
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stbData('saint_bernard.lifespan.senior_from.months'))
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stbData("{$pgp}.senior_boundary_months.value"))
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * stbRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(stbData('saint_bernard.lifespan.senior_from.derived_from'))->toBe(['S11', 'S534'])
            ->and(stbData('saint_bernard.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S534')
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SAINT_BERNARD_SENIOR)
            ->and(stbData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_SAINT_BERNARD_SENIOR)
            ->and(stbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(63);

        expect(stbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stbData("{$pgp}.exercise_minutes_adult.value"))
            ->and(stbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(stbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(stbData('saint_bernard.exercise.adult.quote'))
            ->and(stbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S529')
            ->and(stbRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(stbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stbData("{$pgp}.exercise_minutes_senior.value"))
            ->and(stbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(stbRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(stbData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(stbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(stbData("{$pgp}.learning_multiplier.value"))
            ->and(stbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SAINT_BERNARD) {
                $decided++;
                expect($row['breed_slug'])->toBe('saint-bernard')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.saint_bernard.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) stbData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13, socialisation
        // M5-R17, digestion warning M5-R16, ear cleaning M5-R20); eye / skin-fold care has no frequency → no task.
        expect(json_encode([stbData('saint_bernard'), stbData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(stbData("{$pgp}.grooming.value"))->toBe(3)
            ->and(stbData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(stbData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(stbData("{$pgp}.eye_fold_care.decision"))->toContain('brez naloge')
            ->and(stbData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(stbData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['saint_bernard'][1])
            ->and(stbData('saint_bernard.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['saint_bernard'][1])
            ->and(stbData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['saint_bernard'][1])
            ->and(stbData('saint_bernard.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['saint_bernard'][1]);
    });

    it('takes the breed-level numbers from the sourced saint_bernard entries', function () {
        // PDSA (S532): 54–81 kg, both sexes (no standard gives a weight).
        expect(stbRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([54, 81])
            ->and(stbData('saint_bernard.adult_weight.pdsa.value'))->toEqual(['male' => [54, 81], 'female' => [54, 81]])
            ->and(stbRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(stbData('saint_bernard.adult_weight.pdsa.source_id'))
            ->and(stbRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(stbData('saint_bernard.adult_weight.pdsa.quote'))
            ->and(stbRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(stbData('saint_bernard.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 65 (S35), Fair tier → learning 0.7.
            ->and(stbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(65)
            ->and(stbRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(stbData('saint_bernard.trainability.coren_rank.quote'))
            ->and(stbData('saint_bernard.trainability.coren_rank.value'))->toBe(65)
            ->and(stbRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('saint_bernard.lifespan.median_kc_survey')
            ->and(stbRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S534')
            ->and(stbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(7.0)
            ->and(stbRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(stbData('saint_bernard.lifespan.median_kc_survey.quote'));

        // Brushing 3× a week (RKC "More than once a week" has no number → PDSA "at least three times a week") — the rule, verified.
        $grooming = stbRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S532')
            ->and($grooming['quote'])->toBe(stbData('saint_bernard.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(stbData('proposed_game_parameters.saint_bernard.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(stbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(stbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S532')
            // The digestion warning (M5-R16): 60 minutes after a meal, PDSA gap quote.
            ->and(stbRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(stbRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S532');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saint-bernard');
        // M5-R20: the child's ear-cleaning mini-game once per 14 program days ("every two to three weeks", PetMD S533 →
        // the lower bound); no other care task (eyes / skin folds "regularly", nails "regular", baths "occasional") and no
        // heat rule (not flat-faced).
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::EarCleaningFrequency->value])
            ->and($care[0]['value'])->toBe([1, 14])
            ->and($care[0]['source_id'])->toBe('S533')
            ->and($care[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
            ->and($care[0]['verified'])->toBeTrue()
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['saint_bernard']['ear_cleaning'][3])->toBe(stbData('proposed_game_parameters.saint_bernard.ear_care.quote'));
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (63 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SAINT_BERNARD_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.saint_bernard.senior_boundary_months')
                ->and($row['value'])->toBe(stbData('proposed_game_parameters.saint_bernard.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" (PDSA S532).
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

        expect($general('saint-bernard'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = stbPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 63-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'young 20 months (capped)' => [20, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 62 months' => [62, LifeStage::Adult, 60, 6000],
        'senior 63 months' => [63, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 63 months, 2 months after the Bloodhound (61)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('saint-bernard', 62))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('saint-bernard', 63))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bloodhound', 60))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bloodhound', 61))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = stbPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'saint_bernard', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = stbPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(stbLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SaintBernard)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 63 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'saint_bernard'] as $breed) {
            $pin = stbPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(stbLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['saint_bernard'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(63)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('saint_bernard')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = stbPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Saint Bernard after the German Wirehaired Pointer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'saint_bernard', 'german_wirehaired_pointer');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'saint_bernard', 'slug' => 'saint-bernard', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.saint_bernard',
            'search_keywords' => seededBreedConfig('saint-bernard')['search_keywords'], 'sort_order' => 620,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'large_home'],
                'consider' => ['shorter_lifespan', 'eyelid_problems', 'hips_hind_legs', 'heat_sensitive', 'needs_company', 'sheds', 'frequent_grooming', 'drools'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — red mantle, white with red patches, red-brindle and white; tight eyelids)', function () {
    it('samples DNA in the three standard colours of the appearance block only, never solid colours or drooping eyelids', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('saint_bernard')
            ->and(PetDnaService::hasAppearance('saint_bernard'))->toBeTrue()
            ->and(config('breed_appearance.saint_bernard.verified'))->toBeFalse()
            ->and(config('breed_appearance.saint_bernard.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('saint_bernard', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('short-haired')
                ->and($traits['eye_color'])->toContain('tight eyelids')
                ->and($traits['head'])->toContain('never heavy, drooping or drooling');

            $prompt = $prompts->imagePrompt('saint_bernard', $traits);
            expect($prompt)->toContain('Saint Bernard')
                ->not->toMatch('/\b(solid white|solid red|black coat|merle|tricolou?r|barrel|docked|cropped|drooping eyelids|heavy wrinkles)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['red mantle with white markings on the muzzle, blaze, collar, chest, feet and tail tip and a dark mask', 'white with red patches', 'red-brindle and white'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.saint_bernard'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.saint_bernard'), 'key'))->toBe(['red-white', 'white-red', 'brindle-white']);
    });

    it('draws the register portrait red and white with a dark mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SaintBernard);

        expect($traits['coat_color'])->toStartWith('red mantle with white markings')
            ->and($portrait->prompt(BreedType::SaintBernard))->toContain('Saint Bernard')->toContain('dark mask')
            ->and(BreedPortraitService::relativeFile(BreedType::SaintBernard))->toBe('dog/saint-bernard.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SaintBernard);
        expect($v1['prompt_anchor'])->toContain('Saint Bernard')->toContain('red and white coat')->toContain('tight, healthy eyelids')
            ->not->toMatch('/\b(barrel|docked|cropped|merle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red and white with a dark mask', 'white with red patches', 'red-brindle and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown, with tight healthy eyelids');

        $pet = stbPet(63, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Saint Bernard')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chips, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the eyelid and hind-leg welfare chips and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.saint_bernard'),
            config('breed_suitability.breeds.saint_bernard'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saint-bernard')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '14.70', '2.6%', '74%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::SaintBernard);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 3 (eyelids, hind conformation) → eyelid_problems, hips_hind_legs; not flat-faced.
            ->and($tags['consider'])->toContain('eyelid_problems')->toContain('hips_hind_legs')->toContain('shorter_lifespan')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'sensitive_skin']))->toBe([])
            ->and(stbData('proposed_game_parameters.saint_bernard.marketing.source_id'))->toBe('S529')
            ->and(stbData('proposed_game_parameters.saint_bernard.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 via Wikipedia / Dogs Trust, 72 PetMD) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saint-bernard')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(72);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives three suits chips and eight consider chips; never small_children, other_pets, apartment or chases_small_pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::SaintBernard);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'large_home'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('active_family')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['shorter_lifespan', 'eyelid_problems', 'hips_hind_legs', 'heat_sensitive', 'needs_company', 'sheds', 'frequent_grooming', 'drools'])
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('chases_small_pets')->not->toContain('not_for_small_children')
            ->not->toContain('experienced_owner');

        expect(stbData('saint_bernard.exercise.adult.quote'))->toBe('Exercise: Up to 1 hour per day')
            ->and(stbData('saint_bernard.behaviour.children.quote'))->toContain('which includes children')
            ->and(stbData('saint_bernard.behaviour.other_pets.quote'))->toContain('low prey drive')
            ->and(stbData('saint_bernard.health.eyelids.quote'))->toContain('turned inwards or outwards')
            ->and(stbData('saint_bernard.exercise.heat.quote'))->toContain('in the heat');
    });
});
