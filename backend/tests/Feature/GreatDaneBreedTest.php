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
| M5-R10-29 — Great Dane (appended after the Shih Tzu: AKC 2024 No. 21)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `great_dane` (S239–S244)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.great_dane` (exercise 120 min, senior 90 min, puppy
| 10 min × age capped at 120, stages 9 / 36 / 54 — senior from the VetCompass median
| 6.0 y, S212 (23 deaths), provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 54,
| learning × 1.0, paid breed, Border Collie care rates, brushing 1× a week (M5-R13),
| 60-minute meal / walk warning (M5-R16), puppy socialisation with the PDSA quote (M5-R17),
| suitability: suits active_family / family_pet / large_home, consider long_daily_exercise /
| not_for_small_children / shorter_lifespan / hips_hind_legs / eyelid_problems (new, welfare) /
| chews_when_bored / sheds / drools / chases_small_pets; fawn with a black mask (portrait),
| brindle, harlequin, black or blue — never merle —, tight eyelids, natural ears; Breed Watch
| Category 2 welfare chips, in marketing (no veterinary advice against advertising the type)).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gdData(string $path): mixed
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

/** @return array<string, mixed> the Great Dane seeder row of (stage, from, key) */
function gdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'great-dane'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("great_dane.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->greatDane()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GreatDane;
        $config = BreedConfig::where('breed_slug', 'great-dane')->sole();

        expect($breed->value)->toBe('great_dane')
            ->and($breed->slug())->toBe('great-dane')
            ->and(BreedType::fromSlug('great-dane'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 250, 'breeds.great_dane'])
            ->and($config->search_keywords)->toBe(['great dane', 'nemška doga', 'nemska doga', 'doga', 'deutsche dogge', 'dogge', 'dane'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gdData('proposed_game_parameters.great_dane.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gdData('proposed_game_parameters.great_dane.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Great Dane pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('great_dane');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'deutsche_dogge']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_06_120000_add_great_dane_breed.php');
        // down() refuses while a Great Dane exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'great_dane']))->toThrow(QueryException::class);
        // The Shih Tzu (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shih_tzu']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'great_dane']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GreatDane);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'great-dane')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'great-dane')->count());

        BreedConfig::where('breed_slug', 'great-dane')->sole()->update(['daily_steps_required' => 10000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'great-dane')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'great-dane')->value('daily_steps_required'))->toBe(10000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'great-dane');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'great-dane');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GREAT_DANE)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Great Dane's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_GREAT_DANE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.great_dane';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 6.0 y (VetCompass median, S212) = 54 (provisional until McMillan 2024 is checked).
            ->and(gdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gdData('great_dane.lifespan.senior_from.months'))
            ->and(gdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gdData('great_dane.lifespan.senior_from.derived_from'))->toBe(['S11', 'S212'])
            ->and(gdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(54);

        expect(gdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(gdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gdData("{$pgp}.learning_multiplier.value"))
            ->and(gdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Great Dane decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GREAT_DANE) {
                $decided++;
                expect($row['breed_slug'])->toBe('great-dane')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.great_dane.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; every sourced
        // need is built (brushing M5-R13, meal gap M5-R16, socialisation M5-R17).
        expect(json_encode([gdData('great_dane'), gdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built')
            ->and(gdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(gdData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(gdData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(gdData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['great_dane'][1])
            ->and(gdData('great_dane.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['great_dane'][1])
            ->and(gdData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['great_dane'][1])
            ->and(gdData('great_dane.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['great_dane'][1]);
    });

    it('takes the breed-level numbers from the sourced great_dane entries', function () {
        // PDSA "Average weight | 46-54 kg" (S243); the RKC standard's minimums are the same numbers (S242).
        expect(gdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([46.0, 54.0])
            ->and(gdData('great_dane.adult_weight.pdsa.value'))->toEqual(['male' => [46, 54], 'female' => [46, 54]])
            ->and(gdData('great_dane.adult_weight.rkc_minimum.value'))->toEqual(['male' => 54, 'female' => 46])
            ->and(gdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gdData('great_dane.adult_weight.pdsa.source_id'))
            ->and(gdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(gdData('great_dane.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            ->and(gdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(gdData('great_dane.trainability.coren_rank.value'))
            ->and(gdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(48)
            ->and(gdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('great_dane.lifespan.median_uk')
            ->and(gdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S212')
            ->and(gdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(6.0)
            ->and(gdData('great_dane.lifespan.median_uk.quote'))->toBe('Great Dane 6.0 4.0-9.0 0.0-11.0 23')
            ->and(gdData('great_dane.lifespan.rkc.quote'))->toBe('Lifespan: Under 10 years');

        // Brushing once a week (RKC "Grooming: Once a week" → 1), sourced and confirmed by the runbook rule.
        expect(gdRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(1)
            ->and(gdRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S241')
            ->and(gdRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice (GDV).
            ->and(gdRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(gdRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S243')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(gdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(gdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S243');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'great-dane')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'great-dane')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" with the
            // breed's own PDSA quotes (S243).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('great-dane'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (reaches the cap)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 53 months' => [53, LifeStage::Adult, 120, 12000],
        'senior 54 months' => [54, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 54 months, before every other dog (the Bernese Mountain Dog at 76)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('great-dane', 53))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('great-dane', 54))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bernese-mountain-dog', 75))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bernese-mountain-dog', 76))->toBe(LifeStage::Senior);

        $seniors = collect(BreedStageParamsSeeder::rows())
            ->where('stage', 'senior')->where('key', StageParamKey::StartsAtMonths->value)
            ->pluck('value', 'breed_slug');
        expect($seniors->min())->toBe(54)
            ->and($seniors->filter(fn ($months) => $months <= 54)->keys()->all())->toBe(['great-dane']);
    });

    it('learns at the mixed-breed speed (Coren Average tier, rank 48) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'great_dane', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GreatDane)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 54 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'great_dane'] as $breed) {
            $pin = gdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['great_dane'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(54)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Great Dane appearance data.
            ->and($dog->pet_dna['breed'])->toBe('great_dane')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Great Dane after the Shih Tzu with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua'])
            ->and($breeds[25])->toBe([
                'breed' => 'great_dane', 'slug' => 'great-dane', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.great_dane',
                'search_keywords' => BreedConfigsSeeder::configs()[25]['search_keywords'], 'sort_order' => 250,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'not_for_small_children', 'shorter_lifespan', 'hips_hind_legs', 'eyelid_problems', 'chews_when_bored', 'sheds', 'drools', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: colours both standards list — never merle; welfare → tight eyelids)', function () {
    it('samples Great Dane DNA inside the standards (fawn, brindle, harlequin, black or blue; never merle or mantle; tight eyelids)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('great_dane')
            ->and(PetDnaService::hasAppearance('great_dane'))->toBeTrue()
            ->and(config('breed_appearance.great_dane.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('great_dane', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['eye_color'])->toContain('tight eyelids')
                ->and($traits['ear_carriage'])->toContain('natural uncropped')
                ->and($traits['coat_length'])->toContain('short');

            $prompt = $prompts->imagePrompt('great_dane', $traits);
            expect($prompt)->toContain('Great Dane')
                ->not->toMatch('/\b(merle|mantle|albino|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black',
            'brindle with a black mask',
            'fawn with a black mask',
            'harlequin (white with torn black patches)',
            'steel blue',
        ]);
    });

    it('draws the register portrait fawn with a black mask and tight eyelids', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GreatDane);

        expect($traits['coat_color'])->toBe('fawn with a black mask')
            ->and($portrait->prompt(BreedType::GreatDane))->toContain('Great Dane')->toContain('fawn with a black mask')
            ->toContain('tight eyelids')
            ->and(BreedPortraitService::relativeFile(BreedType::GreatDane))->toBe('dog/great-dane.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GreatDane);
        expect($v1['prompt_anchor'])->toContain('Great Dane')->toContain('fawn coat and a black mask')
            ->toContain('tight eyelids')->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn', 'brindle', 'harlequin'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = gdPet(54, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Great Dane')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; Breed Watch welfare chips, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the welfare chips and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.great_dane'),
            config('breed_suitability.breeds.great_dane'),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '6.0 y', '23 deaths'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.great_dane.traits')))->not->toMatch('/\bmerle\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::GreatDane)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(config('breed_suitability.vocabulary.eyelid_problems'))->toBe('consider')
            ->and($consider)->toContain('hips_hind_legs')->toContain('eyelid_problems')->toContain('shorter_lifespan')
            // Not flat-faced → no breathing chip.
            ->and($consider)->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(gdData('proposed_game_parameters.great_dane.marketing.source_id'))->toBe('S244')
            ->and(gdData('proposed_game_parameters.great_dane.marketing.value'))->toContain('in marketing: yes')
            ->and(gdData('great_dane.health.breed_watch.source_id'))->toBe('S244');
    });
});
