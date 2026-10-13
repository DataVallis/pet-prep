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
| M5-R10-26 — Boxer (appended after the Dobermann: AKC 2024 No. 17)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `boxer` (S213–S220) and the
| runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.boxer` (exercise 120 min, senior 90 min, puppy 10 min ×
| age capped at 120, stages 9 / 36 / 102 — senior from the McMillan 2024 median 11.3 y
| via the Dogs Trust table S123 —, arrival 2 / 9 / 36 / 102, learning × 1.0, paid breed,
| Border Collie care rates, suitability: suits active_family / family_pet / large_home,
| consider long_daily_exercise / short_muzzle_breathing (new) / not_for_small_children /
| experienced_owner / needs_company (new) / chews_when_bored / sheds / chases_small_pets;
| fawn or brindle with a black mask, natural ears and tail, moderate muzzle with open
| nostrils; welfare breed → not in marketing, BVA S92).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bxData(string $path): mixed
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

/** @return array<string, mixed> the Boxer seeder row of (stage, from, key) */
function bxRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'boxer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("boxer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bxPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->boxer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bxPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bxLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Boxer;
        $config = BreedConfig::where('breed_slug', 'boxer')->sole();

        expect($breed->value)->toBe('boxer')
            ->and($breed->slug())->toBe('boxer')
            ->and(BreedType::fromSlug('boxer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 220, 'breeds.boxer'])
            ->and($config->search_keywords)->toBe(['boxer', 'boksar', 'deutscher boxer'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bxData('proposed_game_parameters.boxer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bxData('proposed_game_parameters.boxer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Boxer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bxPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('boxer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'boksar']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_02_120000_add_boxer_breed.php');
        // down() refuses while a Boxer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'boxer']))->toThrow(QueryException::class);
        // The Dobermann (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dobermann']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'boxer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Boxer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'boxer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boxer')->count());

        BreedConfig::where('breed_slug', 'boxer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'boxer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'boxer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'boxer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boxer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BOXER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Boxer's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BOXER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.boxer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bxRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bxData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bxRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bxData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bxRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bxData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.3 y (McMillan 2024 via the Dogs Trust table, S123) = 101.7 → 102.
            ->and(bxRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bxData('boxer.lifespan.senior_from.months'))
            ->and(bxRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bxRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bxData('boxer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(bxRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(102);

        expect(bxRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bxData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bxRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(bxRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(bxRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bxData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bxRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bxRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bxData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(bxRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bxData("{$pgp}.learning_multiplier.value"))
            ->and(bxRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Boxer decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BOXER) {
                $decided++;
                expect($row['breed_slug'])->toBe('boxer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.boxer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bxData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs point at their ROADMAP tasks (brushing M5-R13, meal gap M5-R16 and socialisation M5-R17 are
        // built; face-fold care is ROADMAP M5-R14, not built — needs David).
        expect(json_encode([bxData('boxer'), bxData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bxData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bxData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(bxData("{$pgp}.meal_exercise_gap.decision"))->toContain('ROADMAP M5-R16')
            ->and(bxData("{$pgp}.socialisation.decision"))->toContain('ROADMAP M5-R17')
            ->and(bxData("{$pgp}.face_fold_care.decision"))->toContain('ROADMAP M5-R14');
    });

    it('takes the breed-level numbers from the sourced boxer entries', function () {
        // RKC standard dogs about 30–32 kg, bitches about 25–27 kg (S217) → game range 25–32 kg; PDSA the same.
        expect(bxRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([25.0, 32.0])
            ->and(bxData('boxer.adult_weight.rkc.value'))->toEqual(['male' => [30, 32], 'female' => [25, 27]])
            ->and(bxData('boxer.adult_weight.pdsa.value'))->toEqual(['male' => [30, 32], 'female' => [25, 27]])
            ->and(bxRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bxData('boxer.adult_weight.rkc.source_id'))
            ->and(bxRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(bxData('boxer.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(bxRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(bxData('boxer.trainability.coren_rank.value'))
            ->and(bxRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(48)
            ->and(bxRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('boxer.lifespan.median_uk')
            ->and(bxRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(bxRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.3)
            ->and(bxData('boxer.lifespan.median_uk.quote'))->toBe('Boxer | 11.3 | 11.3 - 11.5 | 11294 | 5925')
            ->and(bxData('boxer.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boxer')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boxer')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (sourced socialisation advice; the Border Collie has none).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('boxer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bxPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (10 min × age)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (reaches the cap)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 101 months' => [101, LifeStage::Adult, 120, 12000],
        'senior 102 months' => [102, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 102 months, the same month as the Beagle and much later than the Dobermann (83)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('boxer', 101))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('boxer', 102))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('beagle', 102))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('dobermann', 101))->toBe(LifeStage::Senior);
    });

    it('learns at 1.0 × the mixed-breed speed (Coren Average tier, rank 48) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bxPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'boxer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bxPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bxLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Boxer)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 102 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'boxer'] as $breed) {
            $pin = bxPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bxLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['boxer'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(102)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Boxer appearance data.
            ->and($dog->pet_dna['breed'])->toBe('boxer')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bxPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Boxer after the Dobermann with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian'])
            ->and($breeds[22])->toBe([
                'breed' => 'boxer', 'slug' => 'boxer', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.boxer',
                'search_keywords' => BreedConfigsSeeder::configs()[22]['search_keywords'], 'sort_order' => 220,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'chews_when_bored', 'sheds', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural ears and tail; welfare breed → moderate muzzle)', function () {
    it('samples Boxer DNA inside the standard (fawn or brindle with a black mask, natural ears and tail, open nostrils)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('boxer')
            ->and(PetDnaService::hasAppearance('boxer'))->toBeTrue()
            ->and(config('breed_appearance.boxer.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('boxer', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['muzzle'])->toContain('open nostrils')->toContain('never extremely short');

            $prompt = $prompts->imagePrompt('boxer', $traits);
            expect($prompt)->toContain('Boxer')->toContain('open nostrils')
                ->not->toMatch('/\b(merle|blue|liver|all white|white coat|black coat)\b/i')
                ->not->toMatch('/\b(cropped ears|docked tail|flat face)\b/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'brindle (black stripes on fawn) with a black mask',
            'golden to deer-red fawn with a black mask',
        ]);
    });

    it('draws the register portrait fawn with a black mask and a moderate muzzle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Boxer);

        expect($traits['coat_color'])->toStartWith('golden to deer-red fawn')
            ->and($portrait->prompt(BreedType::Boxer))->toContain('Boxer')->toContain('fawn')->toContain('black mask')
            ->toContain('undocked')->toContain('uncropped')->toContain('open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::Boxer))->toBe('dog/boxer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Boxer);
        expect($v1['prompt_anchor'])->toContain('Boxer')->toContain('golden fawn coat and a black mask')
            ->toContain('natural uncropped ears')->toContain('natural undocked tail')->toContain('wide open nostrils')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with a black mask', 'brindle with a black mask'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = bxPet(102, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Boxer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the short-face chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.boxer'),
            config('breed_suitability.breeds.boxer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'boxer')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '10.71', '17.74'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.boxer.traits')))->not->toMatch('/\b(cropped ears|docked tail)\b/')->not->toMatch('/\b(merle|blue|liver)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::Boxer)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(config('breed_suitability.vocabulary.needs_company'))->toBe('consider')
            ->and(config('breed_suitability.vocabulary.short_muzzle_breathing'))->toBe('consider')
            ->and($consider)->toContain('short_muzzle_breathing')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->toContain('needs_company')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('heart_and_spine')
            ->and($consider)->not->toContain('sensitive_skin')
            ->and($consider)->not->toContain('shorter_lifespan')
            ->and(bxData('proposed_game_parameters.boxer.marketing.source_id'))->toBe('S92')
            ->and(bxData('proposed_game_parameters.boxer.marketing.decision'))->toContain('v marketingu / posnetkih zaslona ne');
    });
});
