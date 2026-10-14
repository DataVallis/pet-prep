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
| M5-R10-31 — Boston Terrier (appended after the Pomeranian: AKC 2024 No. 23)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `boston_terrier` (S253–S263)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.boston_terrier` (exercise 60 min, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 90 — senior from the RKC lower bound
| "Over 10 years", S255, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 90,
| learning × 1.0 (Coren 54, Average), paid breed, Border Collie care rates, brushing 1× a week
| (RKC "Once a week", M5-R13), puppy socialisation with the AKC quote (M5-R17), no
| meal / walk warning (no source), suitability: suits apartment / family_pet / children, consider
| brachycephalic_breathing / needs_company; brindle and white (portrait), black and white or seal
| and white — always with white markings, never solid / blue / liver / fawn / merle —, moderate
| muzzle with open nostrils, natural ears and tail; flat-faced welfare breed → not in marketing (BVA S92)).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function btData(string $path): mixed
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

/** @return array<string, mixed> the Boston Terrier seeder row of (stage, from, key) */
function btRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'boston-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("boston-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function btPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bostonTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function btPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function btLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BostonTerrier;
        $config = BreedConfig::where('breed_slug', 'boston-terrier')->sole();

        expect($breed->value)->toBe('boston_terrier')
            ->and($breed->slug())->toBe('boston-terrier')
            ->and(BreedType::fromSlug('boston-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 270, 'breeds.boston_terrier'])
            ->and($config->search_keywords)->toBe(['boston terrier', 'bostonski terier', 'boston', 'american gentleman'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) btData('proposed_game_parameters.boston_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = btData('proposed_game_parameters.boston_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Boston Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = btPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('boston_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'american_bulldog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_08_120000_add_boston_terrier_breed.php');
        // down() refuses while a Boston Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'boston_terrier']))->toThrow(QueryException::class);
        // The Pomeranian (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pomeranian']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'boston_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BostonTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'boston-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier')->count());

        BreedConfig::where('breed_slug', 'boston-terrier')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'boston-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'boston-terrier')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'boston-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BOSTON_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Boston Terrier's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BOSTON_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.boston_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(btRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(btRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(btRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S255) = 90 (provisional until McMillan 2024 is checked).
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData('boston_terrier.lifespan.senior_from.months'))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * btRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(btData('boston_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S255'])
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S255')
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['notes'])->toContain('Provisional until McMillan 2024 is checked')
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_adult.value"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData('boston_terrier.exercise.adult.quote'))
            ->and(btRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_senior.value"))
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(btRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(btData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(btData("{$pgp}.learning_multiplier.value"))
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Boston Terrier decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BOSTON_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('boston-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.boston_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) btData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; every sourced
        // need with a game mechanic is built (brushing M5-R13, socialisation M5-R17); the heat warning waits
        // for ROADMAP M5-R14 (as the Bulldog), nail trimming for a new task. No source quotes a meal / walk gap.
        expect(json_encode([btData('boston_terrier'), btData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(btData("{$pgp}.grooming.value"))->toBe(1)
            ->and(btData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(btData("{$pgp}.no_exercise_in_heat.decision"))->toContain('ROADMAP M5-R14')
            ->and(btData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('boston_terrier')
            ->and(btData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['boston_terrier'][1])
            ->and(btData('boston_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['boston_terrier'][1]);
    });

    it('takes the breed-level numbers from the sourced boston_terrier entries', function () {
        // PDSA 7–11 kg (S259), inside the FCI (11.35 kg) and RKC (11.5 kg) maximum.
        expect(btRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([7, 11])
            ->and(btData('boston_terrier.adult_weight.pdsa.value'))->toEqual(['male' => [7, 11], 'female' => [7, 11]])
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(btData('boston_terrier.adult_weight.pdsa.source_id'))
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(btData('boston_terrier.adult_weight.pdsa.quote'))
            ->and(btData('boston_terrier.adult_weight.fci.value.male')[1])->toBeGreaterThanOrEqual(11)
            ->and(btData('boston_terrier.adult_weight.rkc.value.male')[1])->toBeGreaterThanOrEqual(11)
            ->and(btRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(btData('boston_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(btRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(btData('boston_terrier.trainability.coren_rank.value'))
            ->and(btRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(54)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('boston_terrier.lifespan.rkc')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S255')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(btData('boston_terrier.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Brushing 1× a week (RKC "Once a week"; PDSA / AKC agree), sourced and confirmed by the runbook rule.
        expect(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(1)
            ->and(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S255')
            ->and(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(btData('boston_terrier.suitability.rkc_grooming.quote'))
            ->and(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own AKC quote.
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S260');
        // No digestion warning (no source quotes a gap between eating and exercise).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own AKC quote (S260).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('boston-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = btPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Australian Shepherd (RKC "Over 10 years")', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('boston-terrier', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('boston-terrier', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-shepherd', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior);
    });

    it('learns like the mixed breed (Coren Average tier, rank 54) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = btPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'boston_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = btPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(btLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BostonTerrier)
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
        foreach (['border_collie', 'boston_terrier'] as $breed) {
            $pin = btPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(btLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['boston_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Boston Terrier appearance data.
            ->and($dog->pet_dna['breed'])->toBe('boston_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = btPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Boston Terrier after the Pomeranian with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel'])
            ->and($breeds[27])->toBe([
                'breed' => 'boston_terrier', 'slug' => 'boston-terrier', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.boston_terrier',
                'search_keywords' => BreedConfigsSeeder::configs()[27]['search_keywords'], 'sort_order' => 270,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'children'],
                    'consider' => ['brachycephalic_breathing', 'needs_company'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours, always with white — welfare breed → moderate face)', function () {
    it('samples Boston Terrier DNA inside the standards (brindle, black or seal, always with white; moderate muzzle; natural ears and tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('boston_terrier')
            ->and(PetDnaService::hasAppearance('boston_terrier'))->toBeTrue()
            ->and(config('breed_appearance.boston_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.boston_terrier.prompt_order'))->toContain('muzzle');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('boston_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('open nostrils')->toContain('moderate')
                ->and($traits['coat_color'])->toEndWith('and white')
                ->and($traits['coat_pattern'])->toContain('white muzzle band')
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['tail'])->toContain('undocked');

            $prompt = $prompts->imagePrompt('boston_terrier', $traits);
            expect($prompt)->toContain('Boston Terrier')
                ->not->toMatch('/\b(merle|liver|fawn|blue|solid|cropped ears|docked tail)\b/i')
                ->not->toMatch('/\b(flat face|flat-faced|pushed-in|brachycephalic)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black and white',
            'brindle and white',
            'seal and white',
        ]);
    });

    it('draws the register portrait brindle and white with a moderate muzzle and open nostrils', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BostonTerrier);

        expect($traits['coat_color'])->toBe('brindle and white')
            ->and($traits['muzzle'])->toContain('open nostrils')
            ->and($portrait->prompt(BreedType::BostonTerrier))->toContain('Boston Terrier')->toContain('brindle and white')
            ->toContain('open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::BostonTerrier))->toBe('dog/boston-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BostonTerrier);
        expect($v1['prompt_anchor'])->toContain('Boston Terrier')->toContain('brindle coat and white markings')
            ->toContain('open nostrils')->not->toContain('merle')->not->toContain('cropped ears')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['brindle and white', 'black and white', 'seal and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = btPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Boston Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; brachycephalic welfare breed, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the flat-face chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.boston_terrier'),
            config('breed_suitability.breeds.boston_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boston-terrier')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '11.8'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.boston_terrier.traits')))->not->toMatch('/\b(merle|liver|fawn|blue)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::BostonTerrier)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['brachycephalic_breathing', 'needs_company'])
            // Flat-faced with a heat warning (PDSA S259) → the flat-face chip, not the moderate short-muzzle one (Boxer, Shih Tzu).
            ->and($consider)->not->toContain('short_muzzle_breathing')
            ->and(btData('proposed_game_parameters.boston_terrier.marketing.source_id'))->toBe('S92')
            ->and(btData('proposed_game_parameters.boston_terrier.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and(btData('boston_terrier.health.brachycephaly_boas.source_id'))->toBe('S259');
    });
});
