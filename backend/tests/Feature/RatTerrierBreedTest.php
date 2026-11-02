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
| M5-R10-85 — Rat Terrier (appended after the Basenji: AKC 2024 No. 81)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `rat_terrier` (S647–S651) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.rat_terrier` (exercise 30 min — Rat Terrier Club of America "at least 30
| minutes"; no RKC / PDSA page exists for this American breed —, senior 23 min, puppy 10 min × age capped at 30, stages
| 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing once a
| week (VCA, M5-R13), puppy socialisation with the PetMD quote (M5-R17), the child's nail-trim game once a week (PetMD) and
| the parent's bath reminder every 4 weeks (WebMD, M5-R19); no meal gap). The senior boundary / senior arrival 108
| (0.75 × VCA lower bound "12 - 16 years") is Claude's suggestion, verified=false, open for David. Suitability: suits
| children; consider needs_mental_stimulation / needs_company / sheds / food_motivated_weight; black, tan and white
| portrait; no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function rtData(string $path): mixed
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

/** @return array<string, mixed> the Rat Terrier seeder row of (stage, from, key) */
function rtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'rat-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("rat-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function rtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->ratTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function rtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function rtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::RatTerrier;
        $config = BreedConfig::where('breed_slug', 'rat-terrier')->sole();

        expect($breed->value)->toBe('rat_terrier')
            ->and($breed->slug())->toBe('rat-terrier')
            ->and(BreedType::fromSlug('rat-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 790, 'breeds.rat_terrier'])
            ->and($config->search_keywords)->toBe(['rat terrier', 'rat terier', 'rattie', 'ratty', 'american rat terrier', 'ameriški rat terier', 'ameriski rat terier', 'terier za podgane'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) rtData('proposed_game_parameters.rat_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = rtData('proposed_game_parameters.rat_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Rat Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = rtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('rat_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'rattie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'rat-terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_25_120000_add_rat_terrier_breed.php');
        // down() refuses while a Rat Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'rat_terrier']))->toThrow(QueryException::class);
        // The Basenji (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'basenji']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rat_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::RatTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'rat-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier')->count());

        BreedConfig::where('breed_slug', 'rat-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'rat-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'rat-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'rat-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'rat-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_RAT_TERRIER)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_RAT_TERRIER_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_RAT_TERRIER', 'PROPOSED_R10_RAT_TERRIER_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.rat_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(rtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(rtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(rtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(rtRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rtData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (VCA lower bound "12 - 16 years", S647) = 108 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value exists) → verified=false, open for David.
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rtData('rat_terrier.lifespan.senior_from.months'))
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rtData("{$pgp}.senior_boundary_months.value"))
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * rtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(rtData('rat_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S647'])
            ->and(rtData('rat_terrier.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S647')
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_RAT_TERRIER_SENIOR)
            ->and(rtData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_RAT_TERRIER_SENIOR)
            ->and(rtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(rtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(rtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(rtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(rtData('rat_terrier.exercise.adult.quote'))
            ->and(rtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S650')
            ->and(rtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(rtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(rtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(rtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(rtData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(rtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(rtData("{$pgp}.learning_multiplier.value"))
            ->and(rtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_RAT_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('rat-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.rat_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) rtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (brushing M5-R13,
        // socialisation M5-R17, nails + bath M5-R19); no meal gap, no tooth / ear / groomer task (no home frequency).
        expect(json_encode([rtData('rat_terrier'), rtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(rtData("{$pgp}.grooming.value"))->toBe(1)
            ->and(rtData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(rtData("{$pgp}.nail_trimming.decision"))->toContain('M5-R19')
            ->and(rtData("{$pgp}.bathing.decision"))->toContain('M5-R19')
            ->and(rtData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('rat_terrier')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['rat_terrier'])->toBe(['S648', rtData('rat_terrier.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['rat_terrier'][1])->toBe(rtData("{$pgp}.socialisation.quote"));
    });

    it('takes the breed-level numbers from the sourced rat_terrier entries', function () {
        // PetMD (S648) "10–25 pounds" for both sizes and sexes — the AKC standard gives no weight.
        expect(rtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.5, 11.3])
            ->and(rtData('rat_terrier.adult_weight.petmd.value'))->toEqual(['male' => [4.5, 11.3], 'female' => [4.5, 11.3]])
            ->and(rtRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(rtData('rat_terrier.adult_weight.petmd.source_id'))
            ->and(rtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(rtData('rat_terrier.adult_weight.petmd.quote'))
            ->and(rtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(rtData('rat_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Not in Coren's ranking (S35) → unranked → learning 1.0.
            ->and(rtRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(rtData('rat_terrier.trainability.coren_rank.value'))->toBeNull()
            ->and(rtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('rat_terrier.lifespan.vca')
            ->and(rtRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S647')
            ->and(rtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(rtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(rtData('rat_terrier.lifespan.vca.quote'));

        // Brushing once a week (VCA "Brushing once a week" → 1) — the rule, verified.
        $grooming = rtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S647')
            ->and($grooming['quote'])->toBe(rtData('rat_terrier.suitability.vca_grooming.quote'))
            ->and($grooming['quote'])->toBe(rtData('proposed_game_parameters.rat_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(rtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(rtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S648')
            ->and(rtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION)
            // No digestion warning (no source).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Care tasks (David 2026-10-24): the child's nail-trim game once a week (PetMD S648 "weekly nail trims") and the
        // parent's bath reminder every 4 weeks (WebMD S649 "a monthly bath"); no tooth / ear / groomer task.
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::NailTrimFrequency->value => [1, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S648', 'S649'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['rat_terrier']['nail_trim'][3])->toBe(rtData('proposed_game_parameters.rat_terrier.nail_trimming.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['rat_terrier']['bath'][3])->toBe(rtData('proposed_game_parameters.rat_terrier.bathing.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (108 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_RAT_TERRIER_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.rat_terrier.senior_boundary_months')
                ->and($row['value'])->toBe(rtData('proposed_game_parameters.rat_terrier.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: no meal gap; the breed's own socialisation quote (PetMD S648).
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

        expect($general('rat-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = rtPet($arrival);
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
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, like the Basenji', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('rat-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('rat-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('basenji', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = rtPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'rat_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = rtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(rtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::RatTerrier)
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
        foreach (['border_collie', 'rat_terrier'] as $breed) {
            $pin = rtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(rtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['rat_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('rat_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = rtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Rat Terrier after the Basenji with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'rat_terrier', 'basenji');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'rat_terrier', 'slug' => 'rat-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.rat_terrier',
            'search_keywords' => seededBreedConfig('rat_terrier')['search_keywords'], 'sort_order' => 790,
            'suitability' => [
                'suits' => ['children'],
                'consider' => ['needs_mental_stimulation', 'needs_company', 'sheds', 'food_motivated_weight'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — AKC standard S650)', function () {
    it('samples DNA in the eight standard pied colours of the appearance block only, with a short smooth coat and a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('rat_terrier')
            ->and(PetDnaService::hasAppearance('rat_terrier'))->toBeTrue()
            ->and(config('breed_appearance.rat_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.rat_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample('rat_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('smooth and shiny coat')
                ->and($traits['tail'])->toContain('natural long tail')
                ->and($traits['coat_color'])->toContain('white');

            $prompt = $prompts->imagePrompt('rat_terrier', $traits);
            expect($prompt)->toContain('Rat Terrier')
                ->not->toMatch('/\b(blue eyes|merle|brindle|cropped|long coat|fluffy|hairless)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.rat_terrier.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(8)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.rat_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.rat_terrier'), 'key'))->toBe(['black-tan-white', 'red-white', 'chocolate-tan-white', 'black-white', 'apricot-white', 'lemon-white', 'blue-tan-white', 'fawn-white'])
            ->and(rtData('rat_terrier.appearance.colours.value'))->toHaveCount(8)
            ->and(rtData('rat_terrier.appearance.colours.value')[0])->toBe('black, tan and white (tricolour)');
    });

    it('draws the register portrait black, tan and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::RatTerrier);

        expect($traits['coat_color'])->toStartWith('black, tan and white tricolour pied')
            ->and($portrait->prompt(BreedType::RatTerrier))->toContain('Rat Terrier')->toContain('black, tan and white')
            ->and(BreedPortraitService::relativeFile(BreedType::RatTerrier))->toBe('dog/rat-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::RatTerrier);
        expect($v1['prompt_anchor'])->toContain('Rat Terrier')->toContain('pied black, tan and white')->toContain('natural long tail')
            ->not->toMatch('/\b(merle|brindle|docked tail|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black, tan and white tricolour pied with tan points', 'red and white pied', 'chocolate, tan and white pied with a brown nose']);

        $pet = rtPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Rat Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.rat_terrier'),
            config('breed_suitability.breeds.rat_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::RatTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; VCA "12 - 16 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(rtData('proposed_game_parameters.rat_terrier.marketing.source_id'))->toBe('S647')
            ->and(rtData('proposed_game_parameters.rat_terrier.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic; PetMD: sheds mildly all year → never low_shedding.
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rat-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children; consider mental stimulation, company, shedding and weight; never apartment, small_children, chases_small_pets or low_shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::RatTerrier);
        expect($tags['suits'])->toBe(['children'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'needs_company', 'sheds', 'food_motivated_weight'])
            // RTCA "do ok in an apartment" is not the RKC key fact → no apartment chip; no verbatim high prey drive → no chip.
            ->and($tags['suits'])->not->toContain('apartment')->not->toContain('small_children')->not->toContain('low_shedding')->not->toContain('family_pet')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise');

        expect(rtData('rat_terrier.exercise.adult.quote'))->toContain('at least 30 minutes')
            ->and(rtData('rat_terrier.behaviour.family.quote'))->toContain('good with children')
            ->and(rtData('rat_terrier.behaviour.time_alone.quote'))->toContain('left alone for long periods')
            ->and(rtData('rat_terrier.behaviour.other_pets.quote'))->not->toMatch('/(high|strong) prey drive/');
    });
});
