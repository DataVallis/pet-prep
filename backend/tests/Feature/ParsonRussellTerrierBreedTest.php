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
| M5-R10-116 — Parson Russell Terrier (appended after the Parson Russell Terrier: AKC 2024 No. 112)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `parson_russell_terrier` (S864–S870) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.parson_russell_terrier` (exercise 60 min — RKC "Up to 1
| hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12
| years"), arrival 2 / 9 / 36 / 108, learning × 1.0 (not ranked by Coren under this name), paid breed, Border Collie care
| rates, brushing once a week (RKC "Once a week", M5-R13), puppy socialisation with the PDSA quote (S866, M5-R17), tooth
| brushing every day (PetMD, M5-R15), ear cleaning every 2 weeks (PetMD, M5-R18) and the parent's bath reminder every 2
| weeks (PetMD "every two to four weeks", M5-R19). RKC Breed Watch Category 1 → no welfare chip, in marketing; suits
| active_family / family_pet / children; consider sheds / needs_company / chases_small_pets; white and tan portrait.
| Not the Jack Russell Terrier (FCI 345, russell_terrier).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function prtData(string $path): mixed
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

/** @return array<string, mixed> the Parson Russell Terrier seeder row of (stage, from, key) */
function prtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'parson-russell-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("parson-russell-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function prtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->parsonRussellTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function prtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function prtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ParsonRussellTerrier;
        $config = BreedConfig::where('breed_slug', 'parson-russell-terrier')->sole();

        expect($breed->value)->toBe('parson_russell_terrier')
            ->and($breed->slug())->toBe('parson-russell-terrier')
            ->and(BreedType::fromSlug('parson-russell-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1090, 'breeds.parson_russell_terrier'])
            ->and($config->search_keywords)->toBe(['parson russell terrier', 'parson russell terier', 'parson terrier', 'parson terier', 'parson', 'prt', 'terier', 'terrier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) prtData('proposed_game_parameters.parson_russell_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = prtData('proposed_game_parameters.parson_russell_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Parson Russell Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = prtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('parson_russell_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'parson_jack_russell_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'parson']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_06_120000_add_parson_russell_terrier_breed.php');
        // down() refuses while an Parson Russell Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'parson_russell_terrier']))->toThrow(QueryException::class);
        // The Australian Silky Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_silky_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'parson_russell_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ParsonRussellTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'parson-russell-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'parson-russell-terrier')->count());

        BreedConfig::where('breed_slug', 'parson-russell-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'parson-russell-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'parson-russell-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'parson-russell-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'parson-russell-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'parson-russell-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PARSON_RUSSELL_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_PARSON_RUSSELL_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.parson_russell_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(prtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(prtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(prtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(prtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(prtRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(prtData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S865) = 108 — the runbook order (McMillan 2024 only via
            // Wikipedia, no VetCompass median) → confirmed rule, verified.
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(prtData('parson_russell_terrier.lifespan.senior_from.months'))
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * prtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(prtData('parson_russell_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S865'])
            // McMillan 2024 via Wikipedia (tier C) is background only — never the seeded boundary.
            ->and(prtData('parson_russell_terrier.lifespan.mcmillan_2024.source_id'))->toBe('S870')
            ->and(prtData("{$pgp}.stage_boundaries_months.alternatives.senior_mcmillan_2024_via_wikipedia_13_8_y"))->toBe(124)
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', prtData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_PARSON_RUSSELL_TERRIER)
            ->and(prtRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(prtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(prtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(prtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(prtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(prtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(prtData('parson_russell_terrier.exercise.adult.quote'))
            ->and(prtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(prtData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(prtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S865')
            ->and(prtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(prtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(prtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(prtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(prtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(prtData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(prtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(prtData("{$pgp}.learning_multiplier.value"))
            ->and(prtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PARSON_RUSSELL_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('parson-russell-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.parson_russell_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) prtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing once a week (M5-R13, RKC), puppy socialisation with the PDSA quote (M5-R17),
        // tooth brushing every day (PetMD, M5-R15), ear cleaning every 2 weeks (PetMD, M5-R18) and the bath every 2 weeks
        // (PetMD, M5-R19). No nail / groomer task (no frequency), no meal gap, no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['parson_russell_terrier'];
        expect(json_encode([prtData('parson_russell_terrier'), prtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(prtData("{$pgp}.grooming.value"))->toBe(1)
            ->and(prtData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('parson_russell_terrier')
            ->and(prtData('parson_russell_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['parson_russell_terrier'])->toBe(['S866', prtData("{$pgp}.socialisation.quote")])
            ->and(prtData("{$pgp}.socialisation.quote"))->toBe(prtData('parson_russell_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S867', prtData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'two_weeks', 'S867', prtData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'two_weeks', 'S867', prtData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(prtData("{$pgp}.bathing.quote"))->toBe(prtData('parson_russell_terrier.suitability.petmd_bath.quote'))
            ->and(prtData("{$pgp}.ear_care.quote"))->toBe(prtData('parson_russell_terrier.suitability.petmd_ears.quote'))
            ->and(prtData("{$pgp}.tooth_brushing.quote"))->toBe(prtData('parson_russell_terrier.suitability.petmd_teeth.quote'))
            ->and($tasks)->not->toHaveKey('groomer_visit')->not->toHaveKey('nail_trim')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('parson_russell_terrier');
    });

    it('takes the breed-level numbers from the sourced parson_russell_terrier entries', function () {
        // PDSA (S866) "6-8 kg" (PetMD / WebMD 13–17 lb agree; the FCI standard gives no weight).
        expect(prtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6.0, 8.0])
            ->and(prtData('parson_russell_terrier.adult_weight.pdsa.value'))->toEqual(['male' => [6, 8], 'female' => [6, 8]])
            ->and(prtRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(prtData('parson_russell_terrier.adult_weight.pdsa.source_id'))
            ->and(prtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(prtData('parson_russell_terrier.adult_weight.pdsa.quote'))
            ->and(prtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(prtData('parson_russell_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Not ranked by Coren under this name → 1.0.
            ->and(prtRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(prtData('parson_russell_terrier.trainability.coren_rank.value'))->toBeNull()
            ->and(prtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('parson_russell_terrier.lifespan.rkc')
            ->and(prtRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S865')
            ->and(prtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(prtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(prtData('parson_russell_terrier.lifespan.rkc.quote'));

        // Brushing once a week: RKC "Grooming: Once a week" → 1 (PDSA / PetMD weekly agree).
        $grooming = prtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S865')
            ->and($grooming['quote'])->toBe(prtData('parson_russell_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(prtData('proposed_game_parameters.parson_russell_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(prtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(prtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S866')
            ->and(prtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'parson-russell-terrier');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::EarCleaningFrequency->value, StageParamKey::ToothBrushingFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PDSA S866 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R18 / R19: tooth brushing, ear cleaning and the parent's bath reminder (PetMD S867).
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('parson-russell-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = prtPet($arrival);
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
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, like the Havanese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('parson-russell-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('parson-russell-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = prtPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'parson_russell_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = prtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(prtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ParsonRussellTerrier)
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
        foreach (['border_collie', 'parson_russell_terrier'] as $breed) {
            $pin = prtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(prtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['parson_russell_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('parson_russell_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = prtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Parson Russell Terrier after the Australian Silky Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'parson_russell_terrier', 'australian_silky_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'parson_russell_terrier', 'slug' => 'parson-russell-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.parson_russell_terrier',
            'search_keywords' => seededBreedConfig('parson_russell_terrier')['search_keywords'], 'sort_order' => 1090,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['sheds', 'needs_company', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 339 S864)', function () {
    it('samples DNA only in white with tan, lemon or black markings (or tricolour) — never merle, brindle or a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('parson_russell_terrier')
            ->and(PetDnaService::hasAppearance('parson_russell_terrier'))->toBeTrue()
            ->and(config('breed_appearance.parson_russell_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.parson_russell_terrier.prompt_order'))->toContain('head')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.parson_russell_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('parson_russell_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('parson_russell_terrier', $traits);
            expect($prompt)->toContain('Parson Russell Terrier')->toContain('white with')->toContain('undocked')
                ->not->toMatch('/\b(merle|brindle|solid black)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.parson_russell_terrier');
        expect($standard)->toBe(['white with tan markings', 'white with black and tan markings', 'white with lemon markings', 'white with black markings'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['white-tan', 'tricolour', 'white-lemon', 'white-black'])
            ->and(prtData('parson_russell_terrier.appearance.colours.value'))->toBe(['white with tan markings', 'white with lemon markings', 'white with black markings', 'white with black and tan markings'])
            ->and(prtData('parson_russell_terrier.appearance.colours.quote'))->toContain('tan, lemon or black markings');
    });

    it('draws the register portrait white with tan markings (the highest weight)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ParsonRussellTerrier);

        expect($traits['coat_color'])->toBe('white with tan markings')
            ->and($portrait->prompt(BreedType::ParsonRussellTerrier))->toContain('Parson Russell Terrier')->toContain('white with tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::ParsonRussellTerrier))->toBe('dog/parson-russell-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ParsonRussellTerrier);
        expect($v1['prompt_anchor'])->toContain('Parson Russell Terrier')->toContain('white with tan markings')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('predominantly white with tan markings');

        $pet = prtPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Parson Russell Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.parson_russell_terrier'),
            config('breed_suitability.breeds.parson_russell_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'parson-russell-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ParsonRussellTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, no points of concern (S865) → no breathing / hips / back chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(prtData('parson_russell_terrier.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(prtData('proposed_game_parameters.parson_russell_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 124 McMillan via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'parson-russell-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(124);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family, a family pet and children; consider shedding, company and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ParsonRussellTerrier);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['sheds', 'needs_company', 'chases_small_pets'])
            // PDSA "will get on well with children" → children; no explicit rating → never small_children. RKC "Small
            // house" → never apartment; 60 min → never long_daily_exercise; weekly → never frequent_grooming.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('frequent_grooming')->not->toContain('shorter_lifespan')
            ->not->toContain('long_daily_exercise')->not->toContain('not_for_small_children');

        expect(prtData('parson_russell_terrier.behaviour.children.quote'))->toBe('but will get on well with children')
            ->and(prtData('parson_russell_terrier.behaviour.family.quote'))->toBe('and will make an excellent family pet.')
            ->and(prtData('parson_russell_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(prtData('parson_russell_terrier.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(prtData('parson_russell_terrier.suitability.rkc_size_of_home.quote'))->toBe('Small house')
            ->and(prtData('parson_russell_terrier.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(prtData('parson_russell_terrier.lifespan.rkc.quote'))->toBe('Over 12 years');
    });
});
