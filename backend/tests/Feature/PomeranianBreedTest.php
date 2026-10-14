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
| M5-R10-30 — Pomeranian (appended after the Great Dane: AKC 2024 No. 22)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `pomeranian` (S245–S252)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.pomeranian` (exercise 30 min, senior 23 min, puppy
| 10 min × age capped at 30, stages 9 / 36 / 108 — senior from the RKC lower bound
| "Over 12 years", S247, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 108,
| learning × 1.5 (Coren 23, Excellent), paid breed, Border Collie care rates, brushing 3× a week
| (PDSA "two-three times per week", M5-R13), puppy socialisation with the AKC quote (M5-R17), no
| meal / walk warning (no source), suitability: suits apartment / family_pet / other_pets, consider
| older_children (new) / frequent_grooming / sheds; orange (portrait), cream, white, black or brown
| — never merle, never white markings —, natural untrimmed coat; no welfare chip (Breed Watch
| Category 2 points not returned), in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pomData(string $path): mixed
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

/** @return array<string, mixed> the Pomeranian seeder row of (stage, from, key) */
function pomRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'pomeranian'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("pomeranian.{$stage}.{$from}.{$key->value}");

    return $row;
}

function pomPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->pomeranian()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pomPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pomLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Pomeranian;
        $config = BreedConfig::where('breed_slug', 'pomeranian')->sole();

        expect($breed->value)->toBe('pomeranian')
            ->and($breed->slug())->toBe('pomeranian')
            ->and(BreedType::fromSlug('pomeranian'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 260, 'breeds.pomeranian'])
            ->and($config->search_keywords)->toBe(['pomeranian', 'pomeranec', 'pom', 'nemški špic', 'nemski spic', 'german toy spitz', 'toy spitz'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pomData('proposed_game_parameters.pomeranian.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pomData('proposed_game_parameters.pomeranian.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Pomeranian pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pomPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('pomeranian');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'german_spitz']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_07_120000_add_pomeranian_breed.php');
        // down() refuses while a Pomeranian exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'pomeranian']))->toThrow(QueryException::class);
        // The Great Dane (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'great_dane']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pomeranian']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Pomeranian);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'pomeranian')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pomeranian')->count());

        BreedConfig::where('breed_slug', 'pomeranian')->sole()->update(['daily_steps_required' => 2500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'pomeranian')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'pomeranian')->value('daily_steps_required'))->toBe(2500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'pomeranian');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pomeranian');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_POMERANIAN)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Pomeranian's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_POMERANIAN');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.pomeranian';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pomRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pomData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pomRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pomData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pomRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pomData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S247) = 108 (provisional until McMillan 2024 is checked).
            ->and(pomRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pomData('pomeranian.lifespan.senior_from.months'))
            ->and(pomRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pomRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pomData('pomeranian.lifespan.senior_from.derived_from'))->toBe(['S11', 'S247'])
            ->and(pomRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(pomRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pomData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pomRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(pomRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(pomRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pomData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pomRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(pomRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pomData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(pomRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pomData("{$pgp}.learning_multiplier.value"))
            ->and(pomRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Pomeranian decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_POMERANIAN) {
                $decided++;
                expect($row['breed_slug'])->toBe('pomeranian')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.pomeranian.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pomData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; every sourced
        // need is built (brushing M5-R13, socialisation M5-R17). No source quotes a meal / walk gap.
        expect(json_encode([pomData('pomeranian'), pomData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built')
            ->and(pomData("{$pgp}.grooming.value"))->toBe(3)
            ->and(pomData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(pomData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('pomeranian')
            ->and(pomData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['pomeranian'][1])
            ->and(pomData('pomeranian.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['pomeranian'][1]);
    });

    it('takes the breed-level numbers from the sourced pomeranian entries', function () {
        // RKC standard: males 1.8–2 kg, females 2–2.5 kg (S249) → 1.8–2.5 kg; FCI gives no weight.
        expect(pomRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([1.8, 2.5])
            ->and(pomData('pomeranian.adult_weight.rkc.value'))->toEqual(['male' => [1.8, 2.0], 'female' => [2.0, 2.5]])
            ->and(pomRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pomData('pomeranian.adult_weight.rkc.source_id'))
            ->and(pomRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(pomData('pomeranian.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            ->and(pomRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(pomData('pomeranian.trainability.coren_rank.value'))
            ->and(pomRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(23)
            ->and(pomRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('pomeranian.lifespan.rkc')
            ->and(pomRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S247')
            ->and(pomRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(pomData('pomeranian.lifespan.rkc.quote'))->toBe('Lifespan: Over 12 years');

        // Brushing 3× a week (PDSA "two-three times per week" → the upper bound), sourced and confirmed by the runbook rule.
        expect(pomRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(3)
            ->and(pomRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S250')
            ->and(pomRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own AKC quote.
            ->and(pomRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(pomRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S251');
        // No digestion warning (no source quotes a gap between eating and exercise).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pomeranian')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pomeranian')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pomeranian')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own AKC quote (S251).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('pomeranian'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pomPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (reaches the cap)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, like the Pembroke Welsh Corgi (RKC "Over 12 years")', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('pomeranian', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('pomeranian', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pembroke-welsh-corgi', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('pembroke-welsh-corgi', 108))->toBe(LifeStage::Senior);
    });

    it('learns faster than the mixed breed (Coren Excellent tier, rank 23) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = pomPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'pomeranian', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pomPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pomLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Pomeranian)
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
        foreach (['border_collie', 'pomeranian'] as $breed) {
            $pin = pomPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pomLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['pomeranian'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Pomeranian appearance data.
            ->and($dog->pet_dna['breed'])->toBe('pomeranian')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pomPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Pomeranian after the Great Dane with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel'])
            ->and($breeds[26])->toBe([
                'breed' => 'pomeranian', 'slug' => 'pomeranian', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.pomeranian',
                'search_keywords' => BreedConfigsSeeder::configs()[26]['search_keywords'], 'sort_order' => 260,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'other_pets'],
                    'consider' => ['older_children', 'frequent_grooming', 'sheds'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: whole colours both standards name — never merle, never white markings)', function () {
    it('samples Pomeranian DNA inside the standards (orange, cream, white, black or brown; natural untrimmed coat)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('pomeranian')
            ->and(PetDnaService::hasAppearance('pomeranian'))->toBeTrue()
            ->and(config('breed_appearance.pomeranian.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('pomeranian', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('erect')
                ->and($traits['coat_length'])->toContain('untrimmed')
                ->and($traits['tail'])->toContain('over the back');

            $prompt = $prompts->imagePrompt('pomeranian', $traits);
            expect($prompt)->toContain('Pomeranian')
                ->not->toMatch('/\b(merle|parti|sable|white markings|trimmed coat|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black',
            'bright self-coloured orange',
            'brown',
            'cream',
            'white',
        ]);
    });

    it('draws the register portrait bright orange with a natural untrimmed coat', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Pomeranian);

        expect($traits['coat_color'])->toBe('bright self-coloured orange')
            ->and($portrait->prompt(BreedType::Pomeranian))->toContain('Pomeranian')->toContain('bright self-coloured orange')
            ->toContain('untrimmed')
            ->and(BreedPortraitService::relativeFile(BreedType::Pomeranian))->toBe('dog/pomeranian.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Pomeranian);
        expect($v1['prompt_anchor'])->toContain('Pomeranian')->toContain('bright orange coat')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['bright orange', 'cream', 'white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = pomPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Pomeranian')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the older-children chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.pomeranian'),
            config('breed_suitability.breeds.pomeranian'),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '12.2'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.pomeranian.traits')))->not->toMatch('/\bmerle\b/');
        $suitability = app(BreedSuitability::class)->for(BreedType::Pomeranian);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(config('breed_suitability.vocabulary.older_children'))->toBe('consider')
            ->and($suitability['consider'])->toContain('older_children')
            // AKC: a better fit for older children → never the child tags, not the fragile-bones chip.
            ->and($suitability['suits'])->not->toContain('children')->not->toContain('small_children')
            ->and($suitability['consider'])->not->toContain('fragile_older_children')
            // No welfare chip (Breed Watch Category 2 points not returned; not flat-faced).
            ->and($suitability['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(pomData('proposed_game_parameters.pomeranian.marketing.source_id'))->toBe('S248')
            ->and(pomData('proposed_game_parameters.pomeranian.marketing.value'))->toContain('in marketing: yes')
            ->and(pomData('pomeranian.suitability.rkc_breed_watch.value'))->toBe('Category 2');
    });
});
