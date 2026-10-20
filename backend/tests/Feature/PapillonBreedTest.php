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
| M5-R10-48 — Papillon (appended after the Rhodesian Ridgeback: AKC 2024 No. 44 "Papillon")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `papillon` (S395–S401) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.papillon` (exercise 30 min — RKC "Up to 30
| minutes per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 — 0.75 × RKC
| lower bound 12 y, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 108, learning × 1.8
| (Coren rank 8, Brightest), paid breed, Border Collie care rates, brushing 3× a week (RKC "More than once a
| week" → PDSA "two-three times per week", M5-R13), puppy socialisation with the PDSA quote (M5-R17), no meal /
| walk gap (no source); suitability: suits apartment / family_pet / children / other_pets, consider
| frequent_grooming / needs_mental_stimulation / needs_company / sheds; white with red patches (portrait), the
| erect-eared Papillon variety; not a welfare breed (RKC Breed Watch Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function papData(string $path): mixed
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

/** @return array<string, mixed> the Papillon seeder row of (stage, from, key) */
function papRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'papillon'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("papillon.{$stage}.{$from}.{$key->value}");

    return $row;
}

function papPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->papillon()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function papPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function papLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Papillon;
        $config = BreedConfig::where('breed_slug', 'papillon')->sole();

        expect($breed->value)->toBe('papillon')
            ->and($breed->slug())->toBe('papillon')
            ->and(BreedType::fromSlug('papillon'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 440, 'breeds.papillon'])
            ->and($config->search_keywords)->toBe(['papillon', 'phalene', 'phalène', 'butterfly dog', 'papijon', 'falen'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) papData('proposed_game_parameters.papillon.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = papData('proposed_game_parameters.papillon.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Papillon pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = papPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('papillon');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "phalene" / "continental_toy_spaniel" are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'phalene']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'continental_toy_spaniel']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_28_120000_add_papillon_breed.php');
        // down() refuses while a Papillon exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'papillon']))->toThrow(QueryException::class);
        // The Rhodesian Ridgeback (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rhodesian_ridgeback']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'papillon']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Papillon);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'papillon')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')->count());

        BreedConfig::where('breed_slug', 'papillon')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'papillon')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'papillon')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'papillon');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'papillon'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PAPILLON)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Papillon's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_PAPILLON');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.papillon';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(papRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(papData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(papRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(papData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(papRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(papData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S397) = 108 (provisional until McMillan 2024 is checked).
            ->and(papRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(papData('papillon.lifespan.senior_from.months'))
            ->and(papRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * papRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(papData('papillon.lifespan.senior_from.derived_from'))->toBe(['S11', 'S397'])
            ->and(papRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S397')
            ->and(papRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(papRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(papRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(papData("{$pgp}.exercise_minutes_adult.value"))
            ->and(papRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(papRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(papData('papillon.exercise.adult.quote'))
            ->and(papRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(papRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(papData("{$pgp}.exercise_minutes_senior.value"))
            ->and(papRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(papRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(papData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(papRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(papData("{$pgp}.learning_multiplier.value"))
            ->and(papRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.8);

        // Every Papillon decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PAPILLON) {
                $decided++;
                expect($row['breed_slug'])->toBe('papillon')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.papillon.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) papData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); tooth brushing waits for ROADMAP M5-R15, nail trimming for
        // M5-R19, ear checks for M5-R20. No source names a meal / walk gap → no M5-R16 row.
        expect(json_encode([papData('papillon'), papData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(papData("{$pgp}.grooming.value"))->toBe(3)
            ->and(papData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(papData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(papData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(papData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(papData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(papData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['papillon'][1])
            ->and(papData('papillon.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['papillon'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('papillon')
            ->and(papData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(papData('papillon.exercise.after_meals.source_id'))->toBeNull();
    });

    it('takes the breed-level numbers from the sourced papillon entries', function () {
        // FCI (S396): two categories — under 2.5 kg (minimum 1.5 kg) and 2.5–4.5 kg (dogs) / 2.5–5 kg (bitches) → 1.5–5 kg.
        expect(papRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([1.5, 5.0])
            ->and(papData('papillon.adult_weight.fci.value'))->toEqual(['male' => [1.5, 4.5], 'female' => [1.5, 5]])
            ->and(papRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(papData('papillon.adult_weight.fci.source_id'))
            ->and(papRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(papData('papillon.adult_weight.fci.quote'))
            ->and(papRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(papData('papillon.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 8 (S35), Brightest tier → learning 1.8.
            ->and(papRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(8)
            ->and(papRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(papData('papillon.trainability.coren_rank.quote'))
            ->and(papData('papillon.trainability.coren_rank.value'))->toBe(8)
            ->and(papRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('papillon.lifespan.rkc')
            ->and(papRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S397')
            ->and(papRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(papRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(papData('papillon.lifespan.rkc.quote'));

        // Brushing 3× a week (RKC "More than once a week" has no number → PDSA "two-three times per week" → 3) — the rule, verified.
        $grooming = papRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S398')
            ->and($grooming['quote'])->toBe(papData('papillon.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(papData('proposed_game_parameters.papillon.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(papRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(papRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S398');
        // No meal / walk gap row (no source names one for the breed).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S398).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('papillon'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = papPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, the same month as the Maltese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('papillon', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('papillon', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('maltese', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('maltese', 107))->toBe(LifeStage::Adult);
    });

    it('learns faster than a mixed breed (Coren Brightest tier, × 1.8) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = papPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.8);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'papillon', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = papPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(papLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Papillon)
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
        foreach (['border_collie', 'papillon'] as $breed) {
            $pin = papPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(papLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['papillon'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Papillon appearance data.
            ->and($dog->pet_dna['breed'])->toBe('papillon')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = papPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Papillon after the Rhodesian Ridgeback with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'papillon', 'rhodesian_ridgeback');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'papillon', 'slug' => 'papillon', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.papillon',
            'search_keywords' => seededBreedConfig('papillon')['search_keywords'], 'sort_order' => 440,
            'suitability' => [
                'suits' => ['apartment', 'family_pet', 'children', 'other_pets'],
                'consider' => ['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — white with patches of any colour; the erect-eared Papillon)', function () {
    it('samples Papillon DNA in the five standard colour options only, always white with patches and butterfly ears', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('papillon')
            ->and(PetDnaService::hasAppearance('papillon'))->toBeTrue()
            ->and(config('breed_appearance.papillon.verified'))->toBeFalse()
            ->and(config('breed_appearance.papillon.prompt_order'))->toContain('nose')->toContain('ear_carriage');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('papillon', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toContain('white')->toContain('patches')->toContain('over both eyes')
                ->and($traits['ear_carriage'])->toContain('erect')->toContain('butterfly')
                ->and($traits['nose'])->toContain('not short-faced');

            $prompt = $prompts->imagePrompt('papillon', $traits);
            expect($prompt)->toContain('Papillon')->toContain('white with')
                ->not->toMatch('/\b(merle|brindle|all white|pure white|solid|drop ears|docked|cropped)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_colours.papillon'), 'value');
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and($standard)->toHaveCount(5)
            ->and(array_column(config('breed_colours.papillon'), 'key'))->toBe(['red-white', 'sable-white', 'tricolour', 'black-white', 'lemon-white']);
    });

    it('draws the register portrait white with red patches', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Papillon);

        expect($traits['coat_color'])->toStartWith('white with red patches')
            ->and($portrait->prompt(BreedType::Papillon))->toContain('Papillon')->toContain('white with red patches')->toContain('butterfly')
            ->and(BreedPortraitService::relativeFile(BreedType::Papillon))->toBe('dog/papillon.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Papillon);
        expect($v1['prompt_anchor'])->toContain('Papillon')->toContain('white with red patches')->toContain('butterfly')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['white with red patches', 'white with sable patches', 'tricolour: white with black patches and tan markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = papPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Papillon')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.papillon'),
            config('breed_suitability.breeds.papillon'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Papillon);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 12 years"; RKC "Up to 30 minutes"; no prey-drive statement.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'first_time_owner', 'large_home', 'active_family', 'low_shedding']))->toBe([])
            ->and(papData('papillon.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(papData('proposed_game_parameters.papillon.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (senior 131), PetMD (126) and the Dogs Trust rule of thumb (84) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'papillon')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(131)->not->toContain(126)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Papillon apartment / family_pet / children / other_pets and grooming, mental-stimulation, company and shedding chips — no welfare chip (Breed Watch Category 1)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Papillon);
        expect($tags['suits'])->toBe(['apartment', 'family_pet', 'children', 'other_pets'])
            // PetMD: very young children can accidentally injure small dogs → never small_children; no first-time-owner statement.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('active_family')->not->toContain('first_time_owner')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_mental_stimulation', 'needs_company', 'sheds'])
            // Breed Watch Category 1 → no welfare chip; 30 min a day; no prey-drive statement; no older-children source.
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('older_children')->not->toContain('fragile_older_children');

        expect(papData('papillon.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and(papData('papillon.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(papData('papillon.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(papData('papillon.suitability.petmd_children.quote'))->toBe('Papillons live well with other dogs, cats, kids, adults, and seniors alike.')
            ->and(papData('papillon.behaviour.time_alone.quote'))->toBe('However, some Papillons may develop separation anxiety.')
            ->and(papData('papillon.suitability.rkc_breed_watch.value'))->toBe('Category 1');
    });
});
