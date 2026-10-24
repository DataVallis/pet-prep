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
| M5-R10-47 — Rhodesian Ridgeback (appended after the Shiba Inu: AKC 2024 No. 43 "Rhodesian Ridgeback")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `rhodesian_ridgeback` (S384–S394) and the runbook's standing
| rules (confirmed by David 2026-10-10) in `proposed_game_parameters.rhodesian_ridgeback` (exercise 120 min —
| RKC "Exercise: More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages
| 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until McMillan 2024 is checked —, arrival
| 2 / 9 / 36 / 90, learning × 1.0 (Coren rank 52, Average), paid breed, Border Collie care rates, brushing once
| a week (RKC, M5-R13), the meal / walk gap (PDSA, M5-R16), puppy socialisation with the PDSA quote (M5-R17);
| suitability: suits active_family / family_pet / large_home, consider long_daily_exercise / experienced_owner /
| not_for_small_children / needs_company / sheds / chases_small_pets; Breed Watch Category 1 → no welfare chip;
| red wheaten portrait (light / red wheaten) with the ridge always visible; in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function rrData(string $path): mixed
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

/** @return array<string, mixed> the Rhodesian Ridgeback seeder row of (stage, from, key) */
function rrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'rhodesian-ridgeback'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("rhodesian-ridgeback.{$stage}.{$from}.{$key->value}");

    return $row;
}

function rrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->rhodesianRidgeback()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function rrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function rrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::RhodesianRidgeback;
        $config = BreedConfig::where('breed_slug', 'rhodesian-ridgeback')->sole();

        expect($breed->value)->toBe('rhodesian_ridgeback')
            ->and($breed->slug())->toBe('rhodesian-ridgeback')
            ->and(BreedType::fromSlug('rhodesian-ridgeback'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 430, 'breeds.rhodesian_ridgeback'])
            ->and($config->search_keywords)->toBe(['rhodesian ridgeback', 'ridgeback', 'african lion dog', 'rodezijski ridgeback', 'rodezijski'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) rrData('proposed_game_parameters.rhodesian_ridgeback.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = rrData('proposed_game_parameters.rhodesian_ridgeback.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Rhodesian Ridgeback pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = rrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('rhodesian_ridgeback');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Only the full key is a breed.
            ->and($refused(['breed_type' => 'ridgeback']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'rhodesian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_27_120000_add_rhodesian_ridgeback_breed.php');
        // down() refuses while a Rhodesian Ridgeback exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'rhodesian_ridgeback']))->toThrow(QueryException::class);
        // The Shiba Inu (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shiba_inu']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rhodesian_ridgeback']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::RhodesianRidgeback);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'rhodesian-ridgeback')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback')->count());

        BreedConfig::where('breed_slug', 'rhodesian-ridgeback')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'rhodesian-ridgeback')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'rhodesian-ridgeback')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'rhodesian-ridgeback');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'rhodesian-ridgeback'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_RHODESIAN_RIDGEBACK)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Ridgeback's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_RHODESIAN_RIDGEBACK');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.rhodesian_ridgeback';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(rrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(rrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(rrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(rrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S387) = 90 — provisional until McMillan 2024 is checked.
            ->and(rrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rrData('rhodesian_ridgeback.lifespan.senior_from.months'))
            ->and(rrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * rrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(rrData('rhodesian_ridgeback.lifespan.senior_from.derived_from'))->toBe(['S11', 'S387'])
            ->and(rrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S387')
            ->and(rrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(rrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(rrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(rrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(rrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(rrData('rhodesian_ridgeback.exercise.adult.quote'))
            ->and(rrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(rrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(rrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(rrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(rrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(rrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(rrData("{$pgp}.learning_multiplier.value"))
            ->and(rrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Ridgeback decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_RHODESIAN_RIDGEBACK) {
                $decided++;
                expect($row['breed_slug'])->toBe('rhodesian-ridgeback')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.rhodesian_ridgeback.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) rrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); tooth care waits for ROADMAP M5-R15, ear care /
        // baths for M5-R20, the off-lead rule for a new task (needs-david).
        expect(json_encode([rrData('rhodesian_ridgeback'), rrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(rrData("{$pgp}.grooming.value"))->toBe(1)
            ->and(rrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(rrData("{$pgp}.dental_care.decision"))->toContain('ROADMAP M5-R15')
            ->and(rrData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(rrData("{$pgp}.off_lead.decision"))->toContain('needs-david')
            // PDSA advises a gap between meals and exercise (GDV) → the digestion warning (M5-R16).
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['rhodesian_ridgeback'])->toBe(['S390', rrData("{$pgp}.meal_exercise_gap.quote")])
            ->and(rrData('rhodesian_ridgeback.behaviour.after_meals.quote'))->toBe(rrData("{$pgp}.meal_exercise_gap.quote"))
            ->and(rrData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(rrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['rhodesian_ridgeback'][1])
            ->and(rrData('rhodesian_ridgeback.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['rhodesian_ridgeback'][1]);
    });

    it('takes the breed-level numbers from the sourced rhodesian_ridgeback entries', function () {
        // FCI standard: males 36.5 kg, females 32 kg (S385) → 32–36.5 kg.
        expect(rrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([32.0, 36.5])
            ->and(rrData('rhodesian_ridgeback.adult_weight.fci.value'))->toEqual(['male' => 36.5, 'female' => 32.0])
            ->and(rrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(rrData('rhodesian_ridgeback.adult_weight.fci.source_id'))
            ->and(rrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(rrData('rhodesian_ridgeback.adult_weight.fci.quote'))
            ->and(rrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(rrData('rhodesian_ridgeback.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(rrRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(rrData('rhodesian_ridgeback.growth.adult_weight_reached.quote'))
            // Coren rank 52 (S35), Average tier → learning 1.0.
            ->and(rrRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(52)
            ->and(rrData('rhodesian_ridgeback.trainability.coren_rank.value'))->toBe(52)
            ->and(rrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('rhodesian_ridgeback.lifespan.rkc')
            ->and(rrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S387')
            ->and(rrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(rrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(rrData('rhodesian_ridgeback.lifespan.rkc.quote'));

        // Brushing once a week (RKC "Grooming: Once a week" → 1) — the rule, verified.
        $grooming = rrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S387')
            ->and($grooming['quote'])->toBe(rrData('rhodesian_ridgeback.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(rrData('proposed_game_parameters.rhodesian_ridgeback.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(rrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(rrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S390');

        // The digestion warning (M5-R16): 60 minutes, David's decision, PDSA quote.
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S390')
            ->and($gap[0]['quote'])->toBe(rrData('proposed_game_parameters.rhodesian_ridgeback.meal_exercise_gap.quote'))
            ->and($gap[0]['verified'])->toBeTrue()
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        // No individual variation row (factor 1.0), like the other pedigree breeds.
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback');
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA S390).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S390).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('rhodesian-ridgeback'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = rrPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Belgian Malinois', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('rhodesian-ridgeback', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('rhodesian-ridgeback', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('belgian-malinois', 90))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = rrPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'rhodesian_ridgeback', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = rrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(rrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::RhodesianRidgeback)
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
        foreach (['border_collie', 'rhodesian_ridgeback'] as $breed) {
            $pin = rrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(rrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['rhodesian_ridgeback'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Rhodesian Ridgeback appearance data.
            ->and($dog->pet_dna['breed'])->toBe('rhodesian_ridgeback')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = rrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Rhodesian Ridgeback after the Shiba Inu with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'rhodesian_ridgeback', 'shiba_inu');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'rhodesian_ridgeback', 'slug' => 'rhodesian-ridgeback', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.rhodesian_ridgeback',
            'search_keywords' => seededBreedConfig('rhodesian-ridgeback')['search_keywords'], 'sort_order' => 430,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'large_home'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — light wheaten to red wheaten; the ridge always visible)', function () {
    it('samples Rhodesian Ridgeback DNA in the two standard colours only, always with the ridge', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('rhodesian_ridgeback')
            ->and(PetDnaService::hasAppearance('rhodesian_ridgeback'))->toBeTrue()
            ->and(config('breed_appearance.rhodesian_ridgeback.verified'))->toBeFalse()
            ->and(config('breed_appearance.rhodesian_ridgeback.prompt_order'))->toContain('ridge')
            ->and(config('breed_appearance.rhodesian_ridgeback.sources'))->toHaveKey('ridge');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('rhodesian_ridgeback', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toContain('wheaten')
                ->and($traits['ridge'])->toContain('ridge')->toContain('two identical crowns')
                ->and($traits['tail'])->toContain('never curled')
                ->and($traits['nose'])->toBe('black nose');

            $prompt = $prompts->imagePrompt('rhodesian_ridgeback', $traits);
            expect($prompt)->toContain('Rhodesian Ridgeback')->toContain('ridge: clearly defined ridge')
                ->not->toMatch('/\b(black and tan|brindle|solid black|merle|docked|cropped|ridgeless)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_colours.rhodesian_ridgeback'), 'value');
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and($standard)->toHaveCount(2)
            ->and(array_column(config('breed_colours.rhodesian_ridgeback'), 'key'))->toBe(['red-wheaten', 'light-wheaten'])
            ->and(array_column(config('breed_colours.rhodesian_ridgeback'), 'sl'))->toBe(['Rdeče pšenični', 'Svetlo pšenični']);
    });

    it('draws the register portrait red wheaten with the ridge', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::RhodesianRidgeback);

        expect($traits['coat_color'])->toStartWith('red wheaten')
            ->and($portrait->prompt(BreedType::RhodesianRidgeback))->toContain('Rhodesian Ridgeback')->toContain('red wheaten')->toContain('ridge')
            ->and(BreedPortraitService::relativeFile(BreedType::RhodesianRidgeback))->toBe('dog/rhodesian-ridgeback.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::RhodesianRidgeback);
        expect($v1['prompt_anchor'])->toContain('Rhodesian Ridgeback')->toContain('red wheaten coat')->toContain('ridge')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red wheaten', 'light wheaten', 'wheaten with a dark muzzle and ears'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = rrPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Rhodesian Ridgeback')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.rhodesian_ridgeback'),
            config('breed_suitability.breeds.rhodesian_ridgeback'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', 'HR 0.5'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::RhodesianRidgeback);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Breed Watch Category 1 → no welfare chip; RKC "Over 10 years" → no shorter_lifespan; weekly brush only.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'sensitive_skin', 'eyelid_problems', 'heart_and_spine', 'shorter_lifespan', 'frequent_grooming', 'drools']))->toBe([])
            ->and(rrData('rhodesian_ridgeback.health.breed_watch.source_id'))->toBe('S387')
            ->and(rrData('proposed_game_parameters.rhodesian_ridgeback.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (108) and the Dogs Trust rule of thumb (84) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rhodesian-ridgeback')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Rhodesian Ridgeback active_family / family_pet / large_home and the exercise, owner, children, company, shedding and prey-drive chips', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::RhodesianRidgeback);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'])
            // A large dog: never the small-dog older_children chip; never a children / first-time-owner / pets / flat chip.
            ->and($tags['consider'])->not->toContain('older_children')->not->toContain('independent_experienced_owner')->not->toContain('needs_mental_stimulation')->not->toContain('secure_fencing')
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('first_time_owner')
            ->not->toContain('other_pets')->not->toContain('apartment')->not->toContain('often_alone');

        expect(rrData('rhodesian_ridgeback.suitability.pdsa_experienced_owner.quote'))->toBe('so it’s for this reason we wouldn’t recommend them if you are a first time owner.')
            ->and(rrData('rhodesian_ridgeback.suitability.pdsa_children.quote'))->toBe('Their large size and boisterous nature when they are younger also means they risk knocking over smaller children.')
            ->and(rrData('rhodesian_ridgeback.suitability.pdsa_prey_drive.quote'))->toContain('very high prey drive')
            ->and(rrData('rhodesian_ridgeback.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(rrData('rhodesian_ridgeback.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house');
    });
});
