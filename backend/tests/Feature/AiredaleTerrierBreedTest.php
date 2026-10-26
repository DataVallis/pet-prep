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
| M5-R10-68 — Airedale Terrier (appended after the Staffordshire Bull Terrier: AKC 2024 No. 64)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `airedale_terrier` (S545–S552) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.airedale_terrier` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10
| years"), arrival 2 / 9 / 36 / 90, learning × 1.2 (Coren rank 29, Above average), paid breed, Border Collie care rates,
| brushing 7× a week (RKC "Every day", M5-R13), puppy socialisation with the PetMD quote (M5-R17), the 60-minute
| meal / exercise gap (PetMD quote, M5-R16), ear cleaning once a week and the groomer reminder every 12 program weeks
| (PetMD, M5-R20 / M5-R18)). Suitability: suits family_pet / children / large_home / low_shedding; consider
| needs_mental_stimulation, needs_company, frequent_grooming. Weight from PetMD (no standard gives one).
| Black-and-tan portrait; RKC Breed Watch Category 1 → no welfare chip; in marketing.
*/

const AIR_KEY = 'airedale_terrier';
const AIR_SLUG = 'airedale-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function airData(string $path): mixed
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

/** @return array<string, mixed> the Airedale Terrier seeder row of (stage, from, key) */
function airRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === AIR_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(AIR_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function airPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->airedaleTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function airPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function airLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AiredaleTerrier;
        $config = BreedConfig::where('breed_slug', AIR_SLUG)->sole();

        expect($breed->value)->toBe(AIR_KEY)
            ->and($breed->slug())->toBe(AIR_SLUG)
            ->and(BreedType::fromSlug(AIR_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 640, 'breeds.airedale_terrier'])
            ->and($config->search_keywords)->toBe(['airedale terrier', 'airedale', 'king of terriers', 'waterside terrier', 'bingley terrier', 'airedalski terier', 'airedale terier', 'erdelterier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) airData('proposed_game_parameters.airedale_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = airData('proposed_game_parameters.airedale_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Airedale Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = airPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(AIR_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'airedale']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'waterside_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_31_120000_add_airedale_terrier_breed.php');
        // down() refuses while an Airedale Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => AIR_KEY]))->toThrow(QueryException::class);
        // The Staffordshire Bull Terrier (previous dog migration) and the terrier neighbours stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'staffordshire_bull_terrier']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'scottish_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => AIR_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AiredaleTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', AIR_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', AIR_SLUG)->count());

        BreedConfig::where('breed_slug', AIR_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', AIR_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', AIR_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', AIR_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', AIR_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, AIR_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AIREDALE_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_AIREDALE_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.airedale_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(airRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(airData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(airRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(airData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(airRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(airData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S547) = 90 — the runbook rule, verified.
            ->and(airRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(airData('airedale_terrier.lifespan.senior_from.months'))
            ->and(airRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * airRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(airData('airedale_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S547'])
            ->and(airData('airedale_terrier.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(airRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S547')
            ->and(airRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(airRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(airRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(airData("{$pgp}.exercise_minutes_adult.value"))
            ->and(airRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(airRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(airData('airedale_terrier.exercise.adult.quote'))
            ->and(airRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S547')
            ->and(airRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(airRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(airData("{$pgp}.exercise_minutes_senior.value"))
            ->and(airRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(airRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(airData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(airRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(airData("{$pgp}.learning_multiplier.value"))
            ->and(airRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AIREDALE_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(AIR_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.airedale_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) airData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13,
        // socialisation M5-R17, meal gap M5-R16, ear cleaning M5-R20, groomer M5-R18).
        expect(json_encode([airData(AIR_KEY), airData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(airData("{$pgp}.grooming.value"))->toBe(7)
            ->and(airData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(airData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[AIR_KEY][1])
            ->and(airData('airedale_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[AIR_KEY][1])
            ->and(airData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[AIR_KEY][1])
            ->and(airData('airedale_terrier.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[AIR_KEY][1])
            ->and(airData("{$pgp}.ear_care.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[AIR_KEY]['ear_cleaning'][3])
            ->and(airData('airedale_terrier.suitability.petmd_ears.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[AIR_KEY]['ear_cleaning'][3])
            ->and(airData("{$pgp}.professional_grooming.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[AIR_KEY]['groomer_visit'][3])
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[AIR_KEY]))->toBe(['ear_cleaning', 'groomer_visit']);
    });

    it('takes the breed-level numbers from the sourced airedale_terrier entries', function () {
        // PetMD (S549): 40–60 lb → 18.1–27.2 kg (no breed standard gives a weight).
        expect(airRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([18.1, 27.2])
            ->and(airRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(airData('airedale_terrier.adult_weight.petmd.source_id'))
            ->and(airRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(airData('airedale_terrier.adult_weight.petmd.quote'))
            ->and(airData('airedale_terrier.adult_weight.petmd.value'))->toEqual(['male' => [18.1, 27.2], 'female' => [18.1, 27.2]])
            ->and(airRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(airData('airedale_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 29 (S35), Above average tier → learning 1.2.
            ->and(airRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(29)
            ->and(airData('airedale_terrier.trainability.coren_rank.value'))->toBe(29)
            ->and(airRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('airedale_terrier.lifespan.rkc')
            ->and(airRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S547')
            ->and(airRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(airRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(airData('airedale_terrier.lifespan.rkc.quote'));

        // Brushing 7× a week (RKC "Every day" → 7) — the rule, verified.
        $grooming = airRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S547')
            ->and($grooming['quote'])->toBe(airData('airedale_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(airData('proposed_game_parameters.airedale_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(airRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(airRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S549')
            // The digestion warning (M5-R16): 60 minutes after a meal, PetMD gap quote.
            ->and(airRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(airRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S549');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', AIR_SLUG);
        // M5-R20 ear cleaning once a week and M5-R18 groomer every 84 program days (PetMD S549); no tooth / nail / bath
        // frequency, not flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toEqualCanonicalizing([StageParamKey::EarCleaningFrequency->value, StageParamKey::GroomerVisitFrequency->value])
            ->and($care->firstWhere('key', StageParamKey::EarCleaningFrequency->value)['value'])->toBe([1, 7])
            ->and($care->firstWhere('key', StageParamKey::GroomerVisitFrequency->value)['value'])->toBe([1, 84]);
        foreach ($care as $row) {
            expect($row['source_id'])->toBe('S549')
                ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
                ->and($row['verified'])->toBeTrue();
        }
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposal: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" (PetMD S549).
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

        expect($general(AIR_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = airPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the German Wirehaired Pointer', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(AIR_SLUG, 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(AIR_SLUG, 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-wirehaired-pointer', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Above average tier, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = airPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => AIR_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = airPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(airLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AiredaleTerrier)
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
        foreach (['border_collie', AIR_KEY] as $breed) {
            $pin = airPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(airLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[AIR_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(AIR_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = airPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Airedale Terrier after the Staffordshire Bull Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, AIR_KEY, 'staffordshire_bull_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => AIR_KEY, 'slug' => AIR_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.airedale_terrier',
            'search_keywords' => seededBreedConfig(AIR_KEY)['search_keywords'], 'sort_order' => 640,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'large_home', 'low_shedding'],
                'consider' => ['needs_mental_stimulation', 'needs_company', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S546, black or grizzle saddle with tan)', function () {
    it('samples DNA in the two colours of the appearance block only, with V-shaped ears and a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(AIR_KEY)
            ->and(PetDnaService::hasAppearance(AIR_KEY))->toBeTrue()
            ->and(config('breed_appearance.airedale_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.airedale_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(AIR_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('V-shaped')
                ->and($traits['tail'])->toContain('natural undocked');

            $prompt = $prompts->imagePrompt(AIR_KEY, $traits);
            expect($prompt)->toContain('Airedale Terrier')
                ->not->toMatch('/\b(docked tail|cropped|merle|brindle|solid black|wheaten)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['black saddle and tan', 'grizzle saddle and tan'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.airedale_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.airedale_terrier'), 'key'))->toBe(['black-tan', 'grizzle-tan']);
    });

    it('draws the register portrait black saddle and tan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AiredaleTerrier);

        expect($traits['coat_color'])->toBe('black saddle and tan')
            ->and($portrait->prompt(BreedType::AiredaleTerrier))->toContain('Airedale Terrier')->toContain('black saddle and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::AiredaleTerrier))->toBe('dog/airedale-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AiredaleTerrier);
        expect($v1['prompt_anchor'])->toContain('Airedale Terrier')->toContain('black saddle')->toContain('undocked tail')
            ->not->toMatch('/\b(cropped|merle|brindle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['tan with a black saddle', 'tan with a grizzle saddle', 'rich tan with a dark black saddle'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = airPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Airedale Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.airedale_terrier'),
            config('breed_suitability.breeds.airedale_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', AIR_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', 'x2.1', 'x1.9'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AiredaleTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, not flat-faced → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(airData('proposed_game_parameters.airedale_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (84 Dogs Trust, 108 McMillan via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', AIR_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family, children, a large home and low shedding; consider mental stimulation, company and daily grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AiredaleTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'large_home', 'low_shedding'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'needs_company', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise');

        expect(airData('airedale_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(airData('airedale_terrier.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(airData('airedale_terrier.behaviour.family.quote'))->toContain('great family dogs')
            ->and(airData('airedale_terrier.exercise.mental.quote'))->toBe('Airedale Terriers need daily exercise and mental engagement.');
    });
});
