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
| M5-R10-36 — Brittany (appended after the Shetland Sheepdog: AKC 2024 No. 31)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `brittany` (S296–S302) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.brittany`
| (exercise 120 min — RKC "More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at
| 120, stages 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until McMillan 2024 is checked —,
| arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren rank 19, Excellent), paid breed, Border Collie care
| rates, brushing once a week (RKC "More than once a week" has no number → AKC "Weekly brushing",
| M5-R13), puppy socialisation with the PetMD quote (M5-R17), no meal / walk warning (no source);
| suitability: suits active_family / family_pet / children, consider long_daily_exercise /
| needs_mental_stimulation / needs_company / sheds / chases_small_pets; orange and white (portrait),
| never a solid colour; Breed Watch Category 1 → no welfare chip, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function brData(string $path): mixed
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

/** @return array<string, mixed> the Brittany seeder row of (stage, from, key) */
function brRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'brittany'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("brittany.{$stage}.{$from}.{$key->value}");

    return $row;
}

function brPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->brittany()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function brPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function brLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Brittany;
        $config = BreedConfig::where('breed_slug', 'brittany')->sole();

        expect($breed->value)->toBe('brittany')
            ->and($breed->slug())->toBe('brittany')
            ->and(BreedType::fromSlug('brittany'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 320, 'breeds.brittany'])
            ->and($config->search_keywords)->toBe(['brittany', 'bretonski ptičar', 'bretonski pticar', 'epagneul breton', 'bretonski španjel', 'brittany spaniel'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) brData('proposed_game_parameters.brittany.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = brData('proposed_game_parameters.brittany.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Brittany pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = brPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('brittany');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'epagneul_francais']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_14_120000_add_brittany_breed.php');
        // down() refuses while a Brittany exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'brittany']))->toThrow(QueryException::class);
        // The Shetland Sheepdog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shetland_sheepdog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'brittany']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Brittany);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'brittany')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')->count());

        BreedConfig::where('breed_slug', 'brittany')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'brittany')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'brittany')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'brittany');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'american-cocker-spaniel', 'belgian-malinois', 'basset-hound', 'hungarian-vizsla'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BRITTANY)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Brittany's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BRITTANY');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.brittany';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(brRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(brData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(brRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(brData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(brRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(brData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S298) = 90 — provisional until McMillan 2024 is checked.
            ->and(brRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(brData('brittany.lifespan.senior_from.months'))
            ->and(brRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * brRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(brData('brittany.lifespan.senior_from.derived_from'))->toBe(['S11', 'S298'])
            ->and(brRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S298')
            ->and(brRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(brRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(brRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brData("{$pgp}.exercise_minutes_adult.value"))
            ->and(brRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(brRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(brData('brittany.exercise.adult.quote'))
            ->and(brRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(brRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brData("{$pgp}.exercise_minutes_senior.value"))
            ->and(brRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(brRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(brData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(brRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(brData("{$pgp}.learning_multiplier.value"))
            ->and(brRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Brittany decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BRITTANY) {
                $decided++;
                expect($row['breed_slug'])->toBe('brittany')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.brittany.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) brData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); nail trimming waits for ROADMAP M5-R19, ear care for M5-R20.
        expect(json_encode([brData('brittany'), brData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(brData("{$pgp}.grooming.value"))->toBe(1)
            ->and(brData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(brData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(brData("{$pgp}.ear_care_bathing.decision"))->toContain('ROADMAP M5-R20')
            ->and(brData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['brittany'][1])
            ->and(brData('brittany.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['brittany'][1])
            // No source quotes a gap after meals → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('brittany');
    });

    it('takes the breed-level numbers from the sourced brittany entries', function () {
        // AKC standard 30–40 lb (S300) ≈ 13.6–18.1 kg; the FCI / RKC standards give no weight.
        expect(brRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([13.6, 18.1])
            ->and(brData('brittany.adult_weight.akc.value'))->toEqual(['male' => [13.6, 18.1], 'female' => [13.6, 18.1]])
            ->and(brRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(brData('brittany.adult_weight.akc.source_id'))
            ->and(brRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(brData('brittany.adult_weight.akc.quote'))
            ->and(brRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(brData('brittany.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 19 (S35), Excellent tier → learning 1.5.
            ->and(brRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(19)
            ->and(brData('brittany.trainability.coren_rank.value'))->toBe(19)
            ->and(brRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('brittany.lifespan.rkc')
            ->and(brRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S298')
            ->and(brRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(brRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(brData('brittany.lifespan.rkc.quote'));

        // Brushing once a week (RKC "More than once a week" has no number → AKC "Weekly brushing" → 1; PetMD agrees).
        expect(brRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(1)
            ->and(brRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S301')
            ->and(brRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(brData('brittany.suitability.akc_grooming.quote'))
            ->and(brRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(brData('proposed_game_parameters.brittany.grooming.quote'))
            ->and(brRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(brRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(brRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S302');
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S302).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('brittany'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = brPet($arrival);
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

    it('becomes senior at 90 months, like the Shetland Sheepdog', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('brittany', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('brittany', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('shetland-sheepdog', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = brPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'brittany', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = brPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(brLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Brittany)
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
        foreach (['border_collie', 'brittany'] as $breed) {
            $pin = brPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(brLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['brittany'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Brittany appearance data.
            ->and($dog->pet_dna['breed'])->toBe('brittany')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = brPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Brittany after the Shetland Sheepdog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois', 'basset_hound', 'hungarian_vizsla'])
            ->and(collect($breeds[32])->except('colours')->all())->toBe([
                'breed' => 'brittany', 'slug' => 'brittany', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.brittany',
                'search_keywords' => BreedConfigsSeeder::configs()[32]['search_keywords'], 'sort_order' => 320,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'children'],
                    'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only)', function () {
    it('samples Brittany DNA inside the RKC / FCI standard (orange, liver or black and white, roans, tricolour; never a solid colour)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('brittany')
            ->and(PetDnaService::hasAppearance('brittany'))->toBeTrue()
            ->and(config('breed_appearance.brittany.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('brittany', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('triangular')
                ->and($traits['eye_color'])->toContain('brown')
                ->and($traits['tail'])->toContain('natural')
                ->and($traits['muzzle'])->toContain('not snipy');

            $prompt = $prompts->imagePrompt('brittany', $traits);
            expect($prompt)->toContain('Brittany')
                ->not->toMatch('/\b(solid black|solid red|docked|merle|brindle|long tail)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black and white', 'liver and white (clear white with liver-brown patches)', 'liver roan', 'orange and white (clear white with bright orange patches)', 'orange roan (white finely ticked with orange)', 'tricolour (black and white with orange markings over the eyes, on the muzzle, legs and chest)']);
    });

    it('draws the register portrait orange and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Brittany);

        expect($traits['coat_color'])->toStartWith('orange and white')
            ->and($traits['tail'])->toContain('natural')
            ->and($portrait->prompt(BreedType::Brittany))->toContain('Brittany')->toContain('orange and white')
            ->and(BreedPortraitService::relativeFile(BreedType::Brittany))->toBe('dog/brittany.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Brittany);
        expect($v1['prompt_anchor'])->toContain('Brittany')->toContain('orange and white coat')
            ->not->toContain('docked')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['orange and white', 'liver and white', 'orange roan'])
            ->and($v1['visual_traits']['eye_color'])->toBe('brown');

        $pet = brPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Brittany')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.brittany'),
            config('breed_suitability.breeds.brittany'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::Brittany)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(brData('brittany.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(brData('proposed_game_parameters.brittany.marketing.value'))->toContain('in marketing: yes')
            // The PetMD lifespan (12–14 y, not in the runbook's lifespan order) is research only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brittany')->pluck('value')->all())->not->toContain(12.0)->not->toContain(108);
    });
});
