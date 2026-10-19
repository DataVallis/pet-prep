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
| M5-R10-45 — Mastiff (appended after the Rough Collie: AKC 2024 No. 40 "Mastiff")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `mastiff` (S370–S376) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.mastiff` (exercise 60 min — PDSA "Minimum
| exercise (per day) | 1 hour"; the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped
| at 60, stages 9 / 36 / 81 — 0.75 × McMillan 2024 median 9.0 y —, arrival 2 / 9 / 36 / 81, learning × 0.5
| (Coren rank 72, Lowest), paid breed, Border Collie care rates, brushing once a week (PDSA, M5-R13), the
| 60-minute meal / walk warning (PetMD "an hour", M5-R16), puppy socialisation with the PDSA quote (M5-R17);
| suitability: suits family_pet / children / other_pets, consider not_for_small_children / shorter_lifespan /
| eyelid_problems / sensitive_skin / hips_hind_legs (welfare, Kennel Club Category 3) / sheds / drools;
| fawn portrait with a dark mask (fawn / apricot / brindle), moderate skin and eyelids; in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function mtfData(string $path): mixed
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

/** @return array<string, mixed> the Mastiff seeder row of (stage, from, key) */
function mtfRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'mastiff'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("mastiff.{$stage}.{$from}.{$key->value}");

    return $row;
}

function mtfPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->mastiff()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function mtfPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function mtfLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Mastiff;
        $config = BreedConfig::where('breed_slug', 'mastiff')->sole();

        expect($breed->value)->toBe('mastiff')
            ->and($breed->slug())->toBe('mastiff')
            ->and(BreedType::fromSlug('mastiff'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 410, 'breeds.mastiff'])
            ->and($config->search_keywords)->toBe(['mastiff', 'english mastiff', 'old english mastiff', 'angleški mastif', 'angleski mastif', 'mastif'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) mtfData('proposed_game_parameters.mastiff.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = mtfData('proposed_game_parameters.mastiff.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Mastiff pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = mtfPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('mastiff');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // The Bullmastiff, the Neapolitan and the Tibetan Mastiff are different breeds and no breed keys.
            ->and($refused(['breed_type' => 'bullmastiff']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'tibetan_mastiff']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_23_120000_add_mastiff_breed.php');
        // down() refuses while a Mastiff exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'mastiff']))->toThrow(QueryException::class);
        // The Rough Collie (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rough_collie']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'mastiff']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Mastiff);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'mastiff')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff')->count());

        BreedConfig::where('breed_slug', 'mastiff')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'mastiff')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'mastiff')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'mastiff');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'mastiff'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MASTIFF)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Mastiff's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_MASTIFF');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.mastiff';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(mtfRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(mtfData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(mtfRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mtfData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(mtfRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mtfData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.0 y (McMillan 2024 median, S54) = 81 — sourced, not provisional.
            ->and(mtfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(mtfData('mastiff.lifespan.senior_from.months'))
            ->and(mtfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * mtfRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(mtfData('mastiff.lifespan.senior_from.derived_from'))->toBe(['S11', 'S54'])
            ->and(mtfRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S54')
            ->and(mtfRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(mtfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(81);

        expect(mtfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mtfData("{$pgp}.exercise_minutes_adult.value"))
            ->and(mtfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(mtfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(mtfData('mastiff.exercise.adult.quote'))
            ->and(mtfRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(mtfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mtfData("{$pgp}.exercise_minutes_senior.value"))
            ->and(mtfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(mtfRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(mtfData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(mtfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(mtfData("{$pgp}.learning_multiplier.value"))
            ->and(mtfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every Mastiff decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MASTIFF) {
                $decided++;
                expect($row['breed_slug'])->toBe('mastiff')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.mastiff.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) mtfData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); daily face-wrinkle cleaning waits for
        // ROADMAP M5-R14, ear cleaning / bathing for M5-R20.
        expect(json_encode([mtfData('mastiff'), mtfData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(mtfData("{$pgp}.grooming.value"))->toBe(1)
            ->and(mtfData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(mtfData("{$pgp}.face_fold_cleaning.decision"))->toContain('ROADMAP M5-R14')
            ->and(mtfData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(mtfData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(mtfData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['mastiff'][1])
            ->and(mtfData('mastiff.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['mastiff'][1])
            ->and(mtfData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['mastiff'][1])
            ->and(mtfData('mastiff.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['mastiff'][1]);
    });

    it('takes the breed-level numbers from the sourced mastiff entries', function () {
        // PDSA "Average weight | 80kg-85kg" (S370); the FCI and AKC standards give no weight.
        expect(mtfRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([80.0, 85.0])
            ->and(mtfData('mastiff.adult_weight.pdsa.value'))->toEqual(['male' => [80, 85], 'female' => [80, 85]])
            ->and(mtfRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(mtfData('mastiff.adult_weight.pdsa.source_id'))
            ->and(mtfRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(mtfData('mastiff.adult_weight.pdsa.quote'))
            ->and(mtfRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(mtfData('mastiff.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 72 (S35), Lowest tier → learning 0.5.
            ->and(mtfRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(72)
            ->and(mtfRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(mtfData('mastiff.trainability.coren_rank.quote'))
            ->and(mtfData('mastiff.trainability.coren_rank.value'))->toBe(72)
            ->and(mtfRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('mastiff.lifespan.median_uk')
            ->and(mtfRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S54')
            ->and(mtfRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.0)
            ->and(mtfRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(mtfData('mastiff.lifespan.median_uk.quote'))
            ->and(mtfData('mastiff.lifespan.pdsa.quote'))->toBe('Average lifespan | Under 10 years');

        // Brushing once a week (PDSA "just once a week" → 1) — the rule, verified.
        $grooming = mtfRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S370')
            ->and($grooming['quote'])->toBe(mtfData('mastiff.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(mtfData('proposed_game_parameters.mastiff.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The digestion warning (M5-R16): 60 min, PetMD's verbatim advice (GDV).
            ->and(mtfRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(mtfRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S374')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(mtfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(mtfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S370');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning (PetMD S374) and the puppy's weekly "new experience"
            // with the breed's own PDSA quote (S370).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('mastiff'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = mtfPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 80 months' => [80, LifeStage::Adult, 60, 6000],
        'senior 81 months' => [81, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 81 months, the same month as the Rottweiler', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('mastiff', 80))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('mastiff', 81))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rottweiler', 81))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rottweiler', 80))->toBe(LifeStage::Adult);
    });

    it('learns slower than a mixed breed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = mtfPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'mastiff', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = mtfPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(mtfLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Mastiff)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 81 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'mastiff'] as $breed) {
            $pin = mtfPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(mtfLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['mastiff'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(81)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Mastiff appearance data.
            ->and($dog->pet_dna['breed'])->toBe('mastiff')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = mtfPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Mastiff after the Rough Collie with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'mastiff', 'rough_collie');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'mastiff', 'slug' => 'mastiff', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.mastiff',
            'search_keywords' => seededBreedConfig('mastiff')['search_keywords'], 'sort_order' => 410,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'other_pets'],
                'consider' => ['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sensitive_skin', 'hips_hind_legs', 'sheds', 'drools'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — fawn, apricot, brindle with a dark mask; welfare: moderate skin)', function () {
    it('samples Mastiff DNA in the three standard colours only, with moderate skin and tight eyelids', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('mastiff')
            ->and(PetDnaService::hasAppearance('mastiff'))->toBeTrue()
            ->and(config('breed_appearance.mastiff.verified'))->toBeFalse()
            ->and(config('breed_appearance.mastiff.prompt_order'))->toContain('skin')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('mastiff', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['skin'])->toContain('no heavy wrinkles')
                ->and($traits['eye_color'])->toContain('tight eyelids')
                ->and($traits['muzzle'])->toContain('open nostrils');

            $prompt = $prompts->imagePrompt('mastiff', $traits);
            expect($prompt)->toContain('Mastiff')
                ->not->toMatch('/\b(black and tan|merle|harlequin|blue|white markings|docked|cropped|Bullmastiff|Neapolitan|Tibetan)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['fawn with a dark mask, dark ears and a black nose', 'apricot with a dark mask, dark ears and a black nose', 'brindle with a dark mask, dark ears and a black nose'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.mastiff'), 'value'))->toBe($standard);
    });

    it('draws the register portrait fawn with a dark mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Mastiff);

        expect($traits['coat_color'])->toBe('fawn with a dark mask, dark ears and a black nose')
            ->and($portrait->prompt(BreedType::Mastiff))->toContain('Mastiff')->toContain('fawn with a dark mask')
            ->and(BreedPortraitService::relativeFile(BreedType::Mastiff))->toBe('dog/mastiff.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Mastiff);
        expect($v1['prompt_anchor'])->toContain('Mastiff')->toContain('fawn coat and a dark mask')
            ->not->toContain('harlequin')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with a dark mask', 'apricot with a dark mask', 'brindle with a dark mask'])
            ->and($v1['visual_traits']['eye_color'])->toBe('hazel brown');

        $pet = mtfPet(81, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Mastiff')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; Category 3 welfare chips, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the welfare chips and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.mastiff'),
            config('breed_suitability.breeds.mastiff'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', 'HR 2.3'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Mastiff);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toContain('eyelid_problems')->toContain('sensitive_skin')->toContain('hips_hind_legs')->toContain('shorter_lifespan')
            // Not named flat-faced by an A/B source → no breathing chip; one hour a day → no long_daily_exercise.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'long_daily_exercise', 'chases_small_pets', 'frequent_grooming']))->toBe([])
            ->and(mtfData('mastiff.health.breed_watch.quote'))->toBe('Mastiffs are amongst a group of breeds classed as ‘Category Three’ by The Kennel Club.')
            ->and(mtfData('proposed_game_parameters.mastiff.marketing.source_id'))->toBe('S370')
            ->and(mtfData('proposed_game_parameters.mastiff.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) is an alternative only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mastiff')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Mastiff family_pet / children / other_pets and the Category 3 welfare chips; never small_children, apartment or large_home', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Mastiff);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'other_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('active_family')->not->toContain('first_time_owner')
            ->and($tags['consider'])->toBe(['not_for_small_children', 'shorter_lifespan', 'eyelid_problems', 'sensitive_skin', 'hips_hind_legs', 'sheds', 'drools'])
            ->and($tags['consider'])->not->toContain('needs_company')->not->toContain('chews_when_bored')->not->toContain('experienced_owner');

        expect(mtfData('mastiff.suitability.petmd_family.quote'))->toBe('Yes, Mastiffs are great family dogs, and their calm nature is suitable for homes with children')
            ->and(mtfData('mastiff.suitability.petmd_children.quote'))->toBe('However, they may be best suited in homes without toddlers or babies, as their massive size can be dangerous.')
            ->and(mtfData('mastiff.health.eyelids.quote'))->toBe('Eyelid problems – such as entropion and ectropion – occur in Mastiffs due to excessive skin over the face and eyes.')
            ->and(mtfData('mastiff.behaviour.drooling.quote'))->toStartWith('But Mastiffs are known to be excessive droolers');
    });
});
