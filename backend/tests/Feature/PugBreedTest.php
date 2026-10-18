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
| M5-R10-43 — Pug (appended after the Maltese: AKC 2024 No. 38 "Pug")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `pug` (S353–S362) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.pug` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 104 — 0.75 × McMillan 2024
| median 11.6 y, S54 —, arrival 2 / 9 / 36 / 104, learning × 0.7 (Coren rank 57, Fair), paid breed, Border
| Collie care rates, brushing once a week (RKC "Once a week", M5-R13), puppy socialisation with the PetMD
| quote (M5-R17), no meal / walk gap (no source); suitability: suits apartment / children / other_pets,
| consider brachycephalic_breathing / sensitive_skin / food_motivated_weight / needs_company / sheds; fawn
| portrait, moderate face with open nostrils; flat-faced welfare breed (RKC Breed Watch Category 3) → not in
| marketing (BVA S92)).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pugData(string $path): mixed
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

/** @return array<string, mixed> the Pug seeder row of (stage, from, key) */
function pugRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'pug'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("pug.{$stage}.{$from}.{$key->value}");

    return $row;
}

function pugPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->pug()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pugPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pugLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Pug;
        $config = BreedConfig::where('breed_slug', 'pug')->sole();

        expect($breed->value)->toBe('pug')
            ->and($breed->slug())->toBe('pug')
            ->and(BreedType::fromSlug('pug'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 390, 'breeds.pug'])
            ->and($config->search_keywords)->toBe(['pug', 'mops', 'mopsek', 'pug dog', 'carlin'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pugData('proposed_game_parameters.pug.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pugData('proposed_game_parameters.pug.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Pug pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pugPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('pug');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "carlin" is no breed key (the FCI's French name is only a search keyword).
            ->and($refused(['breed_type' => 'carlin']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_21_120000_add_pug_breed.php');
        // down() refuses while a Pug exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'pug']))->toThrow(QueryException::class);
        // The Maltese (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maltese']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pug']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Pug);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'pug')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')->count());

        BreedConfig::where('breed_slug', 'pug')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'pug')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'pug')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'pug');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'pug'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PUG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Pug's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_PUG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.pug';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pugRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pugData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pugRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pugData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pugRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pugData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.6 y (McMillan 2024, S54) = 104.4 → 104.
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pugData('pug.lifespan.senior_from.months'))
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pugRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pugData('pug.lifespan.senior_from.derived_from'))->toBe(['S11', 'S54'])
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', pugData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S54')
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(pugRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(104);

        expect(pugRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pugData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pugRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(pugRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(pugData('pug.exercise.adult.quote'))
            ->and(pugRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(pugRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pugData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pugRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(pugRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pugData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(pugRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pugData("{$pgp}.learning_multiplier.value"))
            ->and(pugRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every Pug decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PUG) {
                $decided++;
                expect($row['breed_slug'])->toBe('pug')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.pug.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pugData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); face-fold cleaning and no walks in the heat wait for
        // ROADMAP M5-R14, tooth brushing for M5-R15, ear care for M5-R20. No source names a meal / walk gap.
        expect(json_encode([pugData('pug'), pugData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(pugData("{$pgp}.grooming.value"))->toBe(1)
            ->and(pugData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(pugData("{$pgp}.face_fold_cleaning.decision"))->toContain('ROADMAP M5-R14')
            ->and(pugData("{$pgp}.no_exercise_in_heat.decision"))->toContain('ROADMAP M5-R14')
            ->and(pugData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(pugData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(pugData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(pugData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['pug'][1])
            ->and(pugData('pug.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['pug'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('pug')
            ->and(pugData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(pugData('pug.exercise.after_meals.source_id'))->toBeNull();
    });

    it('takes the breed-level numbers from the sourced pug entries', function () {
        // FCI "Ideal weight 6,3 – 8,1 kg." (S354), one range for both sexes (PDSA agrees; the AKC 14–18 lb is an alternative).
        expect(pugRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6.3, 8.1])
            ->and(pugData('pug.adult_weight.fci.value'))->toEqual(['male' => [6.3, 8.1], 'female' => [6.3, 8.1]])
            ->and(pugRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pugData('pug.adult_weight.fci.source_id'))
            ->and(pugRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(pugData('pug.adult_weight.fci.quote'))
            ->and(pugRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(pugData('pug.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 57 (S35), Fair tier → learning 0.7.
            ->and(pugRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(57)
            ->and(pugRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(pugData('pug.trainability.coren_rank.quote'))
            ->and(pugData('pug.trainability.coren_rank.value'))->toBe(57)
            // McMillan 2024 median (corrected Fig. 3 legend, S54).
            ->and(pugRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('pug.lifespan.median_uk')
            ->and(pugRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S54')
            ->and(pugRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.6)
            ->and(pugRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(pugData('pug.lifespan.median_uk.quote'));

        // Brushing once a week (RKC "Once a week"; PDSA and PetMD at least once a week) — the rule, verified.
        $grooming = pugRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S355')
            ->and($grooming['quote'])->toBe(pugData('pug.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(pugData('proposed_game_parameters.pug.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(pugRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(pugRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S359');
        // No meal / walk gap row (no source names one for the breed).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S359).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('pug'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pugPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 103 months' => [103, LifeStage::Adult, 60, 6000],
        'senior 104 months' => [104, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 104 months — later than the French Bulldog and the Bulldog (88)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('pug', 103))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('pug', 104))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('french-bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bulldog', 88))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = pugPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'pug', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pugPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pugLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Pug)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 104 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'pug'] as $breed) {
            $pin = pugPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pugLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['pug'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(104)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Pug appearance data.
            ->and($dog->pet_dna['breed'])->toBe('pug')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pugPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Pug after the Maltese with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'pug', 'maltese');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'pug', 'slug' => 'pug', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.pug',
            'search_keywords' => seededBreedConfig('pug')['search_keywords'], 'sort_order' => 390,
            'suitability' => [
                'suits' => ['apartment', 'children', 'other_pets'],
                'consider' => ['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — welfare breed → moderate face)', function () {
    it('samples Pug DNA inside the standard (fawn, black, apricot, silver; moderate muzzle with open nostrils)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('pug')
            ->and(PetDnaService::hasAppearance('pug'))->toBeTrue()
            ->and(config('breed_appearance.pug.verified'))->toBeFalse()
            ->and(config('breed_appearance.pug.prompt_order'))->toContain('muzzle');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample('pug', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('open nostrils')->toContain('moderate')
                ->and($traits['eye_color'])->toContain('not bulging')
                ->and($traits['build'])->toContain('square');

            $prompt = $prompts->imagePrompt('pug', $traits);
            expect($prompt)->toContain('Pug')
                ->not->toMatch('/\b(merle|brindle|pied|liver|blue|white markings|heavy wrinkles)\b/i')
                ->not->toMatch('/\b(flat face|flat-faced|pushed-in|brachycephalic)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        expect(array_keys($colours))->toBe([
            'apricot with a black mask and black ears',
            'black',
            'fawn with a black mask and black ears',
            'silver with a black mask and black ears',
        ])->and(array_column(config('breed_colours.pug'), 'value'))->toBe([
            'fawn with a black mask and black ears',
            'black',
            'apricot with a black mask and black ears',
            'silver with a black mask and black ears',
        ]);
    });

    it('draws the register portrait fawn with a moderate muzzle and open nostrils', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Pug);

        expect($traits['coat_color'])->toBe('fawn with a black mask and black ears')
            ->and($traits['muzzle'])->toContain('open nostrils')
            ->and($portrait->prompt(BreedType::Pug))->toContain('Pug')->toContain('fawn')
            ->and(BreedPortraitService::relativeFile(BreedType::Pug))->toBe('dog/pug.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Pug);
        expect($v1['prompt_anchor'])->toContain('Pug')->toContain('fawn')->toContain('open nostrils')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with a black mask', 'black', 'apricot with a black mask'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = pugPet(104, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Pug')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; brachycephalic welfare breed, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the flat-face and skin chips and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.pug'),
            config('breed_suitability.breeds.pug'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '7.65'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Pug);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds'])
            // Flat-faced with a heat warning (PDSA S357) → the flat-face chip, not the moderate short-muzzle one (Boxer, Shih Tzu).
            ->and($tags['consider'])->not->toContain('short_muzzle_breathing')
            ->and(array_intersect($tags['consider'], ['hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'long_daily_exercise', 'chases_small_pets', 'frequent_grooming']))->toBe([])
            ->and(pugData('pug.suitability.rkc_breed_watch.value'))->toBe('Category 3')
            ->and(pugData('proposed_game_parameters.pug.marketing.source_id'))->toBe('S92')
            ->and(pugData('proposed_game_parameters.pug.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and(pugData('pug.health.brachycephaly_boas.source_id'))->toBe('S357')
            // The RKC lower bound (90) and the Dogs Trust rule of thumb (84) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pug')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Pug apartment / children / other_pets and the welfare chips brachycephalic_breathing / sensitive_skin (Breed Watch Category 3)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Pug);
        expect($tags['suits'])->toBe(['apartment', 'children', 'other_pets'])
            // An explicit children statement (PDSA S357, PetMD S359) with a supervision caveat — no rating → never small_children.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')
            ->not->toContain('active_family')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['brachycephalic_breathing', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds']);

        expect(pugData('pug.suitability.rkc_grooming.quote'))->toBe('Grooming: Once a week')
            ->and(pugData('pug.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(pugData('pug.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(pugData('pug.behaviour.time_alone.quote'))->toBe('Pugs adore company and don’t do well if left alone.')
            ->and(pugData('pug.health.brachycephaly_boas.quote'))->toBe('they are also a severely brachycephalic (flat-faced) breed')
            ->and(pugData('pug.health.breed_watch.quote'))->toStartWith('Signs of respiratory difficulty or distress');
    });
});
