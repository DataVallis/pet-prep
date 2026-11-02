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
| M5-R10-87 — Shar Pei (appended after the Greater Swiss Mountain Dog: AKC 2024 No. 83 "Chinese Shar-Pei")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `shar_pei` (S660–S668) and the runbook's standing rules (confirmed by David
| 2026-10-10) in `proposed_game_parameters.shar_pei` (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 66 (0.75 × VetCompass median 7.28 y, O'Neill et al. 2023), arrival 2 / 9 / 36 /
| 66, learning × 1.0 (Coren rank 51, Average), paid breed, Border Collie care rates, brushing once a week (RKC, M5-R13), puppy
| socialisation with the PDSA quote (M5-R17), the 60-minute meal / exercise warning (PDSA, M5-R16); no care task (no source
| gives a frequency for folds, ears, teeth, nails or baths).
| Welfare breed (RKC Breed Watch Category 2, PDSA BOAS): suits family_pet; consider independent_older_children (new) /
| sheds / short_muzzle_breathing / eyelid_problems / sensitive_skin / shorter_lifespan /
| chases_small_pets; fawn portrait with moderate wrinkles; not in marketing (BVA S92).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function sharpData(string $path): mixed
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

/** @return array<string, mixed> the Shar Pei seeder row of (stage, from, key) */
function sharpRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'shar-pei'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("shar-pei.{$stage}.{$from}.{$key->value}");

    return $row;
}

function sharpPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->sharPei()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function sharpPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function sharpLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SharPei;
        $config = BreedConfig::where('breed_slug', 'shar-pei')->sole();

        expect($breed->value)->toBe('shar_pei')
            ->and($breed->slug())->toBe('shar-pei')
            ->and(BreedType::fromSlug('shar-pei'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 810, 'breeds.shar_pei'])
            ->and($config->search_keywords)->toBe(['shar pei', 'shar-pei', 'sharpei', 'chinese shar-pei', 'chinese shar pei', 'šarpej', 'sarpej', 'šar pej', 'sar pej', 'kitajski šarpej', 'kitajski sarpej'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) sharpData('proposed_game_parameters.shar_pei.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = sharpData('proposed_game_parameters.shar_pei.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Shar Pei pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = sharpPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('shar_pei');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'chinese_shar_pei']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'shar-pei']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_27_120000_add_shar_pei_breed.php');
        // down() refuses while a Shar Pei exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'shar_pei']))->toThrow(QueryException::class);
        // The Greater Swiss Mountain Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'greater_swiss_mountain_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shar_pei']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SharPei);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'shar-pei')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei')->count());

        BreedConfig::where('breed_slug', 'shar-pei')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'shar-pei')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'shar-pei')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'shar-pei');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'shar-pei'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SHAR_PEI)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_SHAR_PEI');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.shar_pei';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(sharpRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(sharpData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(sharpRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(sharpData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(sharpRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(sharpData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 7.28 y (VetCompass median, O'Neill et al. 2023, S667) = 65.52 → 66 — the runbook's second lifespan source.
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(sharpData('shar_pei.lifespan.senior_from.months'))
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(sharpData("{$pgp}.senior_boundary_months.value"))
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * sharpRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(sharpData('shar_pei.lifespan.senior_from.derived_from'))->toBe(['S11', 'S667'])
            ->and(sharpData('shar_pei.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S667')
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SHAR_PEI)
            ->and(sharpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(66);

        expect(sharpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sharpData("{$pgp}.exercise_minutes_adult.value"))
            ->and(sharpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(sharpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(sharpData('shar_pei.exercise.adult.quote'))
            ->and(sharpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S664')
            ->and(sharpRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(sharpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sharpData("{$pgp}.exercise_minutes_senior.value"))
            ->and(sharpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(sharpRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(sharpData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(sharpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(sharpData("{$pgp}.learning_multiplier.value"))
            ->and(sharpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SHAR_PEI) {
                $decided++;
                expect($row['breed_slug'])->toBe('shar-pei')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.shar_pei.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) sharpData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left; every sourced need with a frequency is built (brushing M5-R13, socialisation
        // M5-R17, meal gap M5-R16); folds / ears have no frequency → no care task.
        expect(json_encode([sharpData('shar_pei'), sharpData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('čaka Davida')
            ->and(sharpData("{$pgp}.grooming.value"))->toBe(1)
            ->and(sharpData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(sharpData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(sharpData("{$pgp}.meal_exercise_gap.decision"))->toContain('M5-R16')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['shar_pei'])->toBe(['S660', sharpData('shar_pei.exercise.after_meals.quote')])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['shar_pei'][1])->toBe(sharpData("{$pgp}.meal_exercise_gap.quote"))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['shar_pei'])->toBe(['S660', sharpData('shar_pei.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['shar_pei'][1])->toBe(sharpData("{$pgp}.socialisation.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('shar_pei');
    });

    it('takes the breed-level numbers from the sourced shar_pei entries', function () {
        // PDSA (S660) 20-27 kg, no split by sex; no weight in the standards.
        expect(sharpRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20, 27])
            ->and(sharpData('shar_pei.adult_weight.pdsa.value'))->toEqual(['male' => [20, 27], 'female' => [20, 27]])
            ->and(sharpRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(sharpData('shar_pei.adult_weight.pdsa.source_id'))
            ->and(sharpRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(sharpData('shar_pei.adult_weight.pdsa.quote'))
            ->and(sharpRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(sharpData('shar_pei.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 51 (Average) → learning 1.0.
            ->and(sharpRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(51)
            ->and(sharpData('shar_pei.trainability.coren_rank.value'))->toBe(51)
            ->and(sharpRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('shar_pei.lifespan.median_uk')
            ->and(sharpRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S667')
            ->and(sharpRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(7.28)
            ->and(sharpRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(sharpData('shar_pei.lifespan.median_uk.quote'));

        // Brushing once a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = sharpRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S664')
            ->and($grooming['quote'])->toBe(sharpData('shar_pei.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(sharpData('proposed_game_parameters.shar_pei.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(sharpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(sharpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S660')
            ->and(sharpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        // The digestion warning (M5-R16): 60 minutes, PDSA "Remember to leave a gap between eating and exercising."
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei')->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S660')
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei');
        // No care task (no frequency in any source) and no individual variation row.
        expect($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

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
            // M5-R16 / M5-R17: the meal gap (PDSA S660) and the breed's own socialisation quote (PDSA S660).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('shar-pei'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = sharpPet($arrival);
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
        'adult 65 months' => [65, LifeStage::Adult, 60, 6000],
        'senior 66 months' => [66, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 66 months, earlier than the Greater Swiss Mountain Dog', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('shar-pei', 65))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('shar-pei', 66))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('greater-swiss-mountain-dog', 66))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (Coren rank 51, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = sharpPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'shar_pei', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = sharpPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(sharpLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SharPei)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 66 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'shar_pei'] as $breed) {
            $pin = sharpPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(sharpLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['shar_pei'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(66)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('shar_pei')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = sharpPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Shar Pei after the Greater Swiss Mountain Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'shar_pei', 'greater_swiss_mountain_dog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'shar_pei', 'slug' => 'shar-pei', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.shar_pei',
            'search_keywords' => seededBreedConfig('shar_pei')['search_keywords'], 'sort_order' => 810,
            'suitability' => [
                'suits' => ['family_pet'],
                'consider' => ['independent_older_children', 'sheds', 'short_muzzle_breathing', 'eyelid_problems', 'sensitive_skin', 'shorter_lifespan', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 309 S663)', function () {
    it('samples DNA in solid standard colours only, never white, brindle or patched, with moderate wrinkles', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('shar_pei')
            ->and(PetDnaService::hasAppearance('shar_pei'))->toBeTrue()
            ->and(config('breed_appearance.shar_pei.verified'))->toBeFalse()
            ->and(config('breed_appearance.shar_pei.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('shar_pei', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toStartWith('solid ')
                ->and($traits['muzzle'])->toContain('eyes clearly open');

            $prompt = $prompts->imagePrompt('shar_pei', $traits);
            expect($prompt)->toContain('Shar Pei')
                ->not->toMatch('/\b(white|brindle|merle|spotted|patches|black and tan|heavy folds all over|long coat|fluffy)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The six solid colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.shar_pei.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(6)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.shar_pei'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.shar_pei'), 'key'))->toBe(['fawn', 'red', 'cream', 'black', 'chocolate', 'blue'])
            ->and(sharpData('shar_pei.appearance.colours.value'))->toBe(['fawn', 'red', 'cream', 'black', 'chocolate', 'blue']);
    });

    it('draws the register portrait solid fawn', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SharPei);

        expect($traits['coat_color'])->toBe('solid fawn')
            ->and($portrait->prompt(BreedType::SharPei))->toContain('Shar Pei')->toContain('fawn')->toContain('eyes clearly open')
            ->and(BreedPortraitService::relativeFile(BreedType::SharPei))->toBe('dog/shar-pei.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SharPei);
        expect($v1['prompt_anchor'])->toContain('Shar Pei')->toContain('solid colour')
            ->not->toMatch('/\b(merle|brindle|long coat|white)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toStartWith('solid ');

        $pet = sharpPet(66, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Shar Pei')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chips, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the welfare chips and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.shar_pei'),
            config('breed_suitability.breeds.shar_pei'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '17.88', '16.36', '15.4%', 'one in five'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::SharPei);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 2 (S666) + PDSA BOAS (S660) → welfare chips; not the flat-face heat chip.
            ->and($tags['consider'])->toContain('eyelid_problems')->toContain('sensitive_skin')->toContain('short_muzzle_breathing')->toContain('shorter_lifespan')
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')
            ->and(sharpData('proposed_game_parameters.shar_pei.marketing.source_id'))->toBe('S92')
            ->and(sharpData('proposed_game_parameters.shar_pei.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (90 RKC, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shar-pei')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families; consider older children, shedding, the welfare chips and small pets; never children or apartment', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::SharPei);
        expect($tags['suits'])->toBe(['family_pet'])
            ->and($tags['consider'])->toBe(['independent_older_children', 'sheds', 'short_muzzle_breathing', 'eyelid_problems', 'sensitive_skin', 'shorter_lifespan', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            // Shiba-only by its own test (ShibaInuBreedTest) — open for David.
            ->and($tags['consider'])->not->toContain('independent_experienced_owner')
            ->and(config('breed_suitability.vocabulary.independent_older_children'))->toBe('consider');

        expect(sharpData('shar_pei.exercise.adult.quote'))->toBe('Up to 1 hour per day')
            ->and(sharpData('shar_pei.behaviour.family.quote'))->toContain('older children')
            ->and(sharpData('shar_pei.behaviour.other_pets.quote'))->toContain('really high prey drive')
            ->and(sharpData('shar_pei.suitability.rkc_size_of_home.quote'))->toBe('Small house');
    });
});
