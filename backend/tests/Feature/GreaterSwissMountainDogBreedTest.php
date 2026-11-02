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
| M5-R10-86 — Greater Swiss Mountain Dog (appended after the Rat Terrier: AKC 2024 No. 82)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `greater_swiss_mountain_dog` (S652–S659) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.greater_swiss_mountain_dog` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"),
| arrival 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing once a week
| (RKC, M5-R13), puppy socialisation with the PetMD quote (M5-R17), the 60-minute meal / exercise warning (PetMD, M5-R16),
| the child's tooth brushing every day (WebMD), ear cleaning once a week (PetMD) and nail trim once a week (VCA)).
| Suitability: suits family_pet / children / large_home; consider needs_mental_stimulation / sheds / food_motivated_weight /
| heat_sensitive; black, tan and white portrait (the only standard colour); no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gsmdData(string $path): mixed
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

/** @return array<string, mixed> the Greater Swiss Mountain Dog seeder row of (stage, from, key) */
function gsmdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'greater-swiss-mountain-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("greater-swiss-mountain-dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gsmdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->greaterSwissMountainDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gsmdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gsmdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GreaterSwissMountainDog;
        $config = BreedConfig::where('breed_slug', 'greater-swiss-mountain-dog')->sole();

        expect($breed->value)->toBe('greater_swiss_mountain_dog')
            ->and($breed->slug())->toBe('greater-swiss-mountain-dog')
            ->and(BreedType::fromSlug('greater-swiss-mountain-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 800, 'breeds.greater_swiss_mountain_dog'])
            ->and($config->search_keywords)->toBe(['greater swiss mountain dog', 'great swiss mountain dog', 'swissy', 'grosser schweizer sennenhund', 'veliki švicarski planšarski pes', 'veliki svicarski plansarski pes', 'švicarski planšar', 'svicarski plansar'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Greater Swiss Mountain Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gsmdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('greater_swiss_mountain_dog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'swissy']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'greater-swiss-mountain-dog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_26_120000_add_greater_swiss_mountain_dog_breed.php');
        // down() refuses while a Greater Swiss Mountain Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'greater_swiss_mountain_dog']))->toThrow(QueryException::class);
        // The Rat Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rat_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'greater_swiss_mountain_dog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GreaterSwissMountainDog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'greater-swiss-mountain-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog')->count());

        BreedConfig::where('breed_slug', 'greater-swiss-mountain-dog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'greater-swiss-mountain-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'greater-swiss-mountain-dog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'greater-swiss-mountain-dog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'greater-swiss-mountain-dog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GREATER_SWISS_MOUNTAIN_DOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_GREATER_SWISS_MOUNTAIN_DOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.greater_swiss_mountain_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gsmdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsmdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gsmdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsmdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gsmdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsmdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S654) = 90 — the runbook's third lifespan source, verified.
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsmdData('greater_swiss_mountain_dog.lifespan.senior_from.months'))
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsmdData("{$pgp}.senior_boundary_months.value"))
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gsmdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gsmdData('greater_swiss_mountain_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S654'])
            ->and(gsmdData('greater_swiss_mountain_dog.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S654')
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_GREATER_SWISS_MOUNTAIN_DOG)
            ->and(gsmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(gsmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsmdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gsmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(gsmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gsmdData('greater_swiss_mountain_dog.exercise.adult.quote'))
            ->and(gsmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S654')
            ->and(gsmdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(gsmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsmdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gsmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gsmdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gsmdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(gsmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gsmdData("{$pgp}.learning_multiplier.value"))
            ->and(gsmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GREATER_SWISS_MOUNTAIN_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('greater-swiss-mountain-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.greater_swiss_mountain_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gsmdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (brushing M5-R13,
        // socialisation M5-R17, meal gap M5-R16, teeth M5-R15, ears M5-R20, nails M5-R19); no bath / groomer task.
        expect(json_encode([gsmdData('greater_swiss_mountain_dog'), gsmdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('čaka Davida')
            ->and(gsmdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(gsmdData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(gsmdData("{$pgp}.tooth_brushing.decision"))->toContain('M5-R15')
            ->and(gsmdData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(gsmdData("{$pgp}.nail_trimming.decision"))->toContain('M5-R19')
            ->and(gsmdData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(gsmdData("{$pgp}.meal_exercise_gap.decision"))->toContain('M5-R16')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['greater_swiss_mountain_dog'])->toBe(['S656', gsmdData('greater_swiss_mountain_dog.exercise.after_meals.quote')])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['greater_swiss_mountain_dog'][1])->toBe(gsmdData("{$pgp}.meal_exercise_gap.quote"))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['greater_swiss_mountain_dog'])->toBe(['S656', gsmdData('greater_swiss_mountain_dog.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['greater_swiss_mountain_dog'][1])->toBe(gsmdData("{$pgp}.socialisation.quote"));
    });

    it('takes the breed-level numbers from the sourced greater_swiss_mountain_dog entries', function () {
        // WebMD (S658) males 115–140 lb, females 85–110 lb — no standard gives a weight.
        expect(gsmdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([39, 64])
            ->and(gsmdData('greater_swiss_mountain_dog.adult_weight.webmd.value'))->toEqual(['male' => [52, 64], 'female' => [39, 50]])
            ->and(gsmdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gsmdData('greater_swiss_mountain_dog.adult_weight.webmd.source_id'))
            ->and(gsmdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(gsmdData('greater_swiss_mountain_dog.adult_weight.webmd.quote'))
            ->and(gsmdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(gsmdData('greater_swiss_mountain_dog.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Not in Coren's ranking (S35) → unranked → learning 1.0.
            ->and(gsmdRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(gsmdData('greater_swiss_mountain_dog.trainability.coren_rank.value'))->toBeNull()
            ->and(gsmdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('greater_swiss_mountain_dog.lifespan.rkc')
            ->and(gsmdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S654')
            ->and(gsmdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(gsmdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(gsmdData('greater_swiss_mountain_dog.lifespan.rkc.quote'));

        // Brushing once a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = gsmdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S654')
            ->and($grooming['quote'])->toBe(gsmdData('greater_swiss_mountain_dog.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(gsmdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(gsmdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S656')
            ->and(gsmdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        // The digestion warning (M5-R16): 60 minutes, PetMD "do not exercise your dog directly before or after mealtimes".
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog')->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S656')
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        // Care tasks (David 2026-10-24): the child's tooth brushing every day (WebMD S658), ear cleaning once a week
        // (PetMD S656) and nail trim once a week (VCA S657); no bath / groomer reminder (no frequency).
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::NailTrimFrequency->value => [1, 7],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S656', 'S657', 'S658'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['greater_swiss_mountain_dog']['tooth_brushing'][3])->toBe(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.tooth_brushing.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['greater_swiss_mountain_dog']['ear_cleaning'][3])->toBe(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.ear_care.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['greater_swiss_mountain_dog']['nail_trim'][3])->toBe(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.nail_trimming.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the meal gap (PetMD S656) and the breed's own socialisation quote (PetMD S656).
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

        expect($general('greater-swiss-mountain-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gsmdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the German Wirehaired Pointer', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('greater-swiss-mountain-dog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('greater-swiss-mountain-dog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-wirehaired-pointer', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gsmdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'greater_swiss_mountain_dog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gsmdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gsmdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GreaterSwissMountainDog)
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
        foreach (['border_collie', 'greater_swiss_mountain_dog'] as $breed) {
            $pin = gsmdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gsmdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['greater_swiss_mountain_dog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('greater_swiss_mountain_dog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gsmdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Greater Swiss Mountain Dog after the Rat Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'greater_swiss_mountain_dog', 'rat_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'greater_swiss_mountain_dog', 'slug' => 'greater-swiss-mountain-dog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.greater_swiss_mountain_dog',
            'search_keywords' => seededBreedConfig('greater_swiss_mountain_dog')['search_keywords'], 'sort_order' => 800,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'large_home'],
                'consider' => ['needs_mental_stimulation', 'sheds', 'food_motivated_weight', 'heat_sensitive'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 58 S653)', function () {
    it('samples DNA in the one standard tricolour only, with a short-to-medium double coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('greater_swiss_mountain_dog')
            ->and(PetDnaService::hasAppearance('greater_swiss_mountain_dog'))->toBeTrue()
            ->and(config('breed_appearance.greater_swiss_mountain_dog.verified'))->toBeFalse()
            ->and(config('breed_appearance.greater_swiss_mountain_dog.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('greater_swiss_mountain_dog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('double coat')
                ->and($traits['coat_color'])->toContain('black')->toContain('tan')->toContain('white');

            $prompt = $prompts->imagePrompt('greater_swiss_mountain_dog', $traits);
            expect($prompt)->toContain('Greater Swiss Mountain Dog')
                ->not->toMatch('/\b(blue eyes|merle|brindle|cropped|long coat|fluffy|hairless)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The one standard colour of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.greater_swiss_mountain_dog.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(1)
            ->and(array_keys($colours))->toBe($standard)
            ->and(array_column(config('breed_colours.greater_swiss_mountain_dog'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.greater_swiss_mountain_dog'), 'key'))->toBe(['tricolour'])
            ->and(gsmdData('greater_swiss_mountain_dog.appearance.colours.value'))->toBe(['black, tan and white (tricolour)']);
    });

    it('draws the register portrait black, tan and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GreaterSwissMountainDog);

        expect($traits['coat_color'])->toStartWith('tricolour: black main colour')
            ->and($portrait->prompt(BreedType::GreaterSwissMountainDog))->toContain('Greater Swiss Mountain Dog')->toContain('tan')
            ->and(BreedPortraitService::relativeFile(BreedType::GreaterSwissMountainDog))->toBe('dog/greater-swiss-mountain-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GreaterSwissMountainDog);
        expect($v1['prompt_anchor'])->toContain('Greater Swiss Mountain Dog')->toContain('tricolour')
            ->not->toMatch('/\b(merle|brindle|long coat|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('hazel to chestnut brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black, tan and white tricolour');

        $pet = gsmdPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Greater Swiss Mountain Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.greater_swiss_mountain_dog'),
            config('breed_suitability.breeds.greater_swiss_mountain_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%', '6.75'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::GreaterSwissMountainDog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.marketing.source_id'))->toBe('S654')
            ->and(gsmdData('proposed_game_parameters.greater_swiss_mountain_dog.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic; RKC "Sheds: Yes" → never low_shedding.
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 Dogs Trust, 72 PetMD) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greater-swiss-mountain-dog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(72);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families, children and a large home; consider mental stimulation, shedding, weight and heat; never apartment, small_children or chases_small_pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::GreaterSwissMountainDog);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'sheds', 'food_motivated_weight', 'heat_sensitive'])
            ->and($tags['suits'])->not->toContain('apartment')->not->toContain('small_children')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('needs_company');

        expect(gsmdData('greater_swiss_mountain_dog.exercise.adult.quote'))->toBe('Up to 1 hour per day')
            ->and(gsmdData('greater_swiss_mountain_dog.behaviour.family.quote'))->toContain('gentle with children')
            ->and(gsmdData('greater_swiss_mountain_dog.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(gsmdData('greater_swiss_mountain_dog.behaviour.other_pets.quote'))->not->toMatch('/(high|strong) prey drive/');
    });
});
