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
| M5-R10-113 — Gordon Setter (appended after the Toy Fox Terrier: AKC 2024 No. 109 "Gordon Setter")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `gordon_setter` (S840–S847) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.gordon_setter` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10
| years"), arrival 2 / 9 / 36 / 90, learning × 1.2 (Coren rank 34, Above average), paid breed, Border Collie care rates,
| brushing every day (PDSA "daily grooming", M5-R13), the meal / exercise warning (PetMD, M5-R16), puppy socialisation
| with the PetMD quote (S844, M5-R17), tooth brushing every day (WebMD, M5-R15) and the parent's bath reminder every
| 4 weeks (WebMD "about once a month", M5-R19). RKC Breed Watch Category 2 without returned points → no welfare chip,
| in marketing; suits active_family / large_home; consider long_daily_exercise / older_children / sheds /
| frequent_grooming / chases_small_pets; black and tan portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gsData(string $path): mixed
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

/** @return array<string, mixed> the Gordon Setter seeder row of (stage, from, key) */
function gsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'gordon-setter'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("gordon-setter.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->gordonSetter()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GordonSetter;
        $config = BreedConfig::where('breed_slug', 'gordon-setter')->sole();

        expect($breed->value)->toBe('gordon_setter')
            ->and($breed->slug())->toBe('gordon-setter')
            ->and(BreedType::fromSlug('gordon-setter'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1060, 'breeds.gordon_setter'])
            ->and($config->search_keywords)->toBe(['gordon setter', 'gordonski seter', 'gordon seter', 'setter', 'seter', 'black and tan setter', 'scottish setter', 'škotski seter', 'skotski seter'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gsData('proposed_game_parameters.gordon_setter.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gsData('proposed_game_parameters.gordon_setter.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Gordon Setter pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('gordon_setter');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'setter']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'black_and_tan_setter']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_03_120000_add_gordon_setter_breed.php');
        // down() refuses while a Gordon Setter exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'gordon_setter']))->toThrow(QueryException::class);
        // The Toy Fox Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'toy_fox_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'gordon_setter']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GordonSetter);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'gordon-setter')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'gordon-setter')->count());

        BreedConfig::where('breed_slug', 'gordon-setter')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'gordon-setter')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'gordon-setter')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'gordon-setter');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'gordon-setter');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'gordon-setter'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GORDON_SETTER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_GORDON_SETTER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.gordon_setter';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S842) = 90 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsData('gordon_setter.lifespan.senior_from.months'))
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gsRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gsData('gordon_setter.lifespan.senior_from.derived_from'))->toBe(['S11', 'S842'])
            ->and(gsData('gordon_setter.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', gsData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_GORDON_SETTER)
            ->and(gsRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gsData('gordon_setter.exercise.adult.quote'))
            ->and(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gsData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S842')
            ->and(gsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(gsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gsData("{$pgp}.learning_multiplier.value"))
            ->and(gsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GORDON_SETTER) {
                $decided++;
                expect($row['breed_slug'])->toBe('gordon-setter')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.gordon_setter.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, PDSA), the meal / exercise warning (M5-R16, PetMD), puppy
        // socialisation with the PetMD quote (M5-R17), tooth brushing every day (WebMD, M5-R15) and the bath every 4 weeks
        // (WebMD, M5-R19). No ear / nail / groomer task (no frequency), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['gordon_setter'];
        expect(json_encode([gsData('gordon_setter'), gsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(gsData("{$pgp}.grooming.value"))->toBe(7)
            ->and(gsData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['gordon_setter'])->toBe(['S844', gsData("{$pgp}.meal_exercise_gap.quote")])
            ->and(gsData("{$pgp}.meal_exercise_gap.quote"))->toBe(gsData('gordon_setter.exercise.after_meals.quote'))
            ->and(gsData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['gordon_setter'])->toBe(['S844', gsData("{$pgp}.socialisation.quote")])
            ->and(gsData("{$pgp}.socialisation.quote"))->toBe(gsData('gordon_setter.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S845', gsData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S845', gsData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(gsData("{$pgp}.bathing.quote"))->toBe(gsData('gordon_setter.suitability.webmd_bath.quote'))
            ->and(gsData("{$pgp}.tooth_brushing.quote"))->toBe(gsData('gordon_setter.suitability.webmd_teeth.quote'))
            ->and($tasks)->not->toHaveKey('groomer_visit')->not->toHaveKey('nail_trim')->not->toHaveKey('ear_cleaning')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('gordon_setter');
    });

    it('takes the breed-level numbers from the sourced gordon_setter entries', function () {
        // FCI-St. N°6 (S841): males 29.5 kg, females 25.5 kg → 25.5–29.5 (PDSA 25-30 kg = alternative).
        expect(gsRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([25.5, 29.5])
            ->and(gsData('gordon_setter.adult_weight.fci.value'))->toEqual(['male' => [29.5, 29.5], 'female' => [25.5, 25.5]])
            ->and(gsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gsData('gordon_setter.adult_weight.fci.source_id'))
            ->and(gsRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(gsData('gordon_setter.adult_weight.fci.quote'))
            ->and(gsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(gsData('gordon_setter.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 34 → Above average → 1.2.
            ->and(gsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(34)
            ->and(gsData('gordon_setter.trainability.coren_rank.value'))->toBe(34)
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('gordon_setter.lifespan.rkc')
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S842')
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(gsData('gordon_setter.lifespan.rkc.quote'));

        // Brushing every day: RKC "More than once a week" has no number → PDSA "daily grooming" → 7.
        $grooming = gsRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S843')
            ->and($grooming['quote'])->toBe(gsData('gordon_setter.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(gsData('proposed_game_parameters.gordon_setter.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(gsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(gsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S844')
            ->and(gsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue()
            ->and(gsRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(gsRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S844');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'gordon-setter');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::ToothBrushingFrequency->value])->sort()->values()->all())
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
            // M5-R16 / R17: the meal warning (PetMD S844) and socialisation (PetMD S844 quote for this breed).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R19: tooth brushing and the parent's bath reminder (WebMD S845).
            StageParamKey::ToothBrushingFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('gordon-setter'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gsPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Irish Setter', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('gordon-setter', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('gordon-setter', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('irish-setter', 90))->toBe(LifeStage::Senior);
    });

    it('learns at 1.2 × the mixed-breed speed (Coren rank 34, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gsPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'gordon_setter', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GordonSetter)
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
        foreach (['border_collie', 'gordon_setter'] as $breed) {
            $pin = gsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gsLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['gordon_setter'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('gordon_setter')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Gordon Setter after the Toy Fox Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'gordon_setter', 'toy_fox_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'gordon_setter', 'slug' => 'gordon-setter', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.gordon_setter',
            'search_keywords' => seededBreedConfig('gordon_setter')['search_keywords'], 'sort_order' => 1060,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'older_children', 'sheds', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 6 S841)', function () {
    it('samples DNA only in the one standard colour, black and tan — never another colour or a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('gordon_setter')
            ->and(PetDnaService::hasAppearance('gordon_setter'))->toBeTrue()
            ->and(config('breed_appearance.gordon_setter.verified'))->toBeFalse()
            ->and(config('breed_appearance.gordon_setter.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.gordon_setter.traits.coat_color'), 'value');
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('gordon_setter', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('gordon_setter', $traits);
            expect($prompt)->toContain('Gordon Setter')->toContain('black and tan')
                ->not->toMatch('/\b(merle|brindle|belton|red setter|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.gordon_setter');
        expect($standard)->toBe(['black and tan (deep coal black with chestnut-red tan markings)'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-tan'])
            ->and(gsData('gordon_setter.appearance.colours.value'))->toBe(['black and tan']);
    });

    it('draws the register portrait in black and tan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GordonSetter);

        expect($traits['coat_color'])->toBe('black and tan (deep coal black with chestnut-red tan markings)')
            ->and($portrait->prompt(BreedType::GordonSetter))->toContain('Gordon Setter')->toContain('black and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::GordonSetter))->toBe('dog/gordon-setter.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GordonSetter);
        expect($v1['prompt_anchor'])->toContain('Gordon Setter')->toContain('black and tan')
            ->not->toMatch('/\b(merle|brindle|belton|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black and tan (coal black with chestnut-red tan markings)');

        $pet = gsPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Gordon Setter')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.gordon_setter'),
            config('breed_suitability.breeds.gordon_setter'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'gordon-setter')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::GordonSetter);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 2 without returned points (S842), a long muzzle (S841) → no breathing / hips chip,
            // no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')
            ->and(gsData('gordon_setter.suitability.rkc_breed_watch.value'))->toBe('Category 2')
            ->and(gsData('proposed_game_parameters.gordon_setter.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (112 Wikipedia, 108 PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'gordon-setter')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(112)->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family in a large home; consider long walks, older children, shedding, daily grooming and prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::GordonSetter);
        expect($tags['suits'])->toBe(['active_family', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'older_children', 'sheds', 'frequent_grooming', 'chases_small_pets'])
            // WebMD: only moderately good with young children and other dogs → never children / small_children / other_pets.
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('family_pet')->not->toContain('apartment')->not->toContain('first_time_owner')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('needs_company')
            ->not->toContain('experienced_owner')->not->toContain('not_for_small_children');

        expect(gsData('gordon_setter.behaviour.children.quote'))->toContain('only moderately good with young children')
            ->and(gsData('gordon_setter.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(gsData('gordon_setter.behaviour.family.quote'))->toBeNull()
            ->and(gsData('gordon_setter.behaviour.time_alone.quote'))->toBeNull()
            ->and(gsData('gordon_setter.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(gsData('gordon_setter.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(gsData('gordon_setter.lifespan.rkc.quote'))->toBe('Over 10 years');
    });
});
