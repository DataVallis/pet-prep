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
| M5-R10-12 — Bernese Mountain Dog (twelfth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bernese_mountain_dog`
| (S150–S157) and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.bernese_mountain_dog` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 76 (senior from the Swiss breed
| median 8.4 y, S156 — provisional), arrival 2 / 9 / 36 / 76, learning × 1.5 (Coren 22,
| Excellent), paid breed, Border Collie care rates, suitability family_pet / large_home
| + shorter_lifespan (new key) / sheds / frequent_grooming; tricolour is the only
| standard colour → a tricolour portrait).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bmdData(string $path): mixed
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

/** @return array<string, mixed> the Bernese Mountain Dog seeder row of (stage, from, key) */
function bmdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bernese-mountain-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bernese-mountain-dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bmdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->berneseMountainDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bmdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bmdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BerneseMountainDog;
        $config = BreedConfig::where('breed_slug', 'bernese-mountain-dog')->sole();

        expect($breed->value)->toBe('bernese_mountain_dog')
            ->and($breed->slug())->toBe('bernese-mountain-dog')
            ->and(BreedType::fromSlug('bernese-mountain-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 130, 'breeds.bernese_mountain_dog'])
            ->and($config->search_keywords)->toBe(['bernese mountain dog', 'berner', 'berner sennenhund', 'bernese', 'bernski planšarski pes', 'bernski plansarski pes', 'bernski planšar', 'bernski plansar'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bmdData('proposed_game_parameters.bernese_mountain_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bmdData('proposed_game_parameters.bernese_mountain_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bernese Mountain Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bmdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bernese_mountain_dog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'berner']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_13_120000_add_bernese_mountain_dog_breed.php');
        // down() refuses while a Bernese Mountain Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bernese_mountain_dog']))->toThrow(QueryException::class);
        // The West Highland White Terrier (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'west_highland_white_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bernese_mountain_dog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BerneseMountainDog);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bernese-mountain-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bernese-mountain-dog')->count());

        BreedConfig::where('breed_slug', 'bernese-mountain-dog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bernese-mountain-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bernese-mountain-dog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $rows = collect(BreedStageParamsSeeder::rows());
        $others = $rows->where('breed_slug', '!=', 'bernese-mountain-dog');
        $mine = $rows->where('breed_slug', 'bernese-mountain-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BERNESE_MOUNTAIN_DOG)->all())->toBe([]);
        foreach ([BreedStageParamsSeeder::CONFIRMED_R10, BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER, BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE,
            BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE, BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND,
            BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD, BreedStageParamsSeeder::CONFIRMED_R10_HAVANESE,
            BreedStageParamsSeeder::CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER] as $other) {
            expect($mine->where('decision', $other)->all())->toBe([]);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bernese_mountain_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bmdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bmdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bmdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 8.4 y (Swiss breed median, S156 — closest rule row, provisional) = 75.6 → 76.
            ->and(bmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmdData('bernese_mountain_dog.lifespan.senior_from.months'))
            ->and(bmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bmdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bmdData('bernese_mountain_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S156'])
            ->and(bmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(76);

        expect(bmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bmdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe((int) round(0.75 * 60, 0, PHP_ROUND_HALF_UP))
            ->and(bmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bmdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bmdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bmdData("{$pgp}.learning_multiplier.value"))
            ->and(bmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BERNESE_MOUNTAIN_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('bernese-mountain-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bernese_mountain_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bmdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the research.
        expect(json_encode([bmdData('bernese_mountain_dog'), bmdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced bernese_mountain_dog entries', function () {
        $pdsa = bmdData('bernese_mountain_dog.adult_weight.pdsa.value');

        // PDSA "42-53kg in females and 48-63kg in males" (S154) → overall range (the FCI / RKC standards give height only).
        expect(bmdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $pdsa['female'][0], (float) $pdsa['male'][1]])
            ->and(bmdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([42.0, 63.0])
            ->and(bmdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bmdData('bernese_mountain_dog.adult_weight.pdsa.source_id'))
            ->and(bmdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(bmdData('bernese_mountain_dog.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 22 (S35, "Excellent").
            ->and(bmdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(22)
            ->and(bmdData('bernese_mountain_dog.trainability.coren_rank.value'))->toBe(22)
            ->and(bmdData('bernese_mountain_dog.trainability.coren_tier.value'))->toBe('Excellent')
            ->and(bmdRow('all', 0, StageParamKey::CorenRank)['source_id'])->toBe('S35')
            // Klopfenstein et al. 2016 breed median 8.4 y (S156, Switzerland).
            ->and(bmdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) bmdData('bernese_mountain_dog.lifespan.median_ch.value'))
            ->and(bmdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bernese_mountain_dog.lifespan.median_ch')
            ->and(bmdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S156')
            ->and(bmdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(8.4)
            // McMillan 2024 only via Wikipedia (tier C) — an alternative, never a runtime number.
            ->and(bmdData('bernese_mountain_dog.lifespan.mcmillan_2024.source_id'))->toBe('S157')
            ->and(bmdData('bernese_mountain_dog.lifespan.senior_from.alternative.months'))->toBe(91);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bernese-mountain-dog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bernese-mountain-dog')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bernese-mountain-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bmdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 75 months' => [75, LifeStage::Adult, 60, 6000],
        'senior 76 months' => [76, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 76 months — the earliest of all dogs (French Bulldog 88)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bernese-mountain-dog', 75))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bernese-mountain-dog', 76))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('french-bulldog', 87))->toBe(LifeStage::Adult);
    });

    it('learns 1.5× faster than the mixed breed (Coren Excellent) with no individual variation', function () {
        $training = app(TrainingService::class);
        $berner = bmdPet(36);
        $date = $berner->localDate(now());

        // Individual factor 1.0 (pedigree, no variation row); the breed multiplier 1.5 scales the gain.
        expect($training->learningFactor($berner))->toBe(1.0)
            ->and($training->gainPerSuccess($berner, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bernese_mountain_dog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bmdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bmdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BerneseMountainDog)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 76 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bernese_mountain_dog'] as $breed) {
            $pin = bmdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bmdLogin($pin)->assertOk()->json('pet.id'));
        }
        $berner = $pets['bernese_mountain_dog'];

        expect($berner->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($berner->plan)->toBe(PetPlan::Challenge)
            ->and($berner->arrival_age_months)->toBe(76)
            ->and($berner->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($berner->pet_dna['breed'])->toBe('bernese_mountain_dog')
            ->and((int) $berner->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bmdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bernese Mountain Dog after the West Highland White Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog'])
            ->and($breeds[13])->toBe([
                'breed' => 'bernese_mountain_dog', 'slug' => 'bernese-mountain-dog', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bernese_mountain_dog',
                'search_keywords' => BreedConfigsSeeder::configs()[13]['search_keywords'], 'sort_order' => 130,
                'suitability' => [
                    'suits' => ['family_pet', 'large_home'],
                    'consider' => ['shorter_lifespan', 'sheds', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: tricolour only)', function () {
    it('samples DNA inside the standard (tricolour, long coat, hanging ears)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bernese_mountain_dog')
            ->and(PetDnaService::hasAppearance('bernese_mountain_dog'))->toBeTrue()
            ->and(config('breed_appearance.bernese_mountain_dog.verified'))->toBeFalse();

        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('bernese_mountain_dog', $seed)['traits'];
            expect($traits['coat_color'])->toStartWith('tricolour: jet black')
                ->and($traits['ear_carriage'])->toContain('hanging flat')
                ->and($traits['coat_length'])->toContain('long');

            $prompt = $prompts->imagePrompt('bernese_mountain_dog', $traits);
            expect($prompt)->toContain('Bernese Mountain Dog')
                ->toContain('tricolour')
                ->toContain('white blaze')
                ->not->toContain('merle')
                ->not->toContain('brindle')
                ->not->toContain('short coat')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
    });

    it('draws the register portrait tricolour', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BerneseMountainDog);

        expect($traits['coat_color'])->toStartWith('tricolour: jet black')
            ->and($portrait->prompt(BreedType::BerneseMountainDog))->toContain('Bernese Mountain Dog')->toContain('tricolour')
            ->and(BreedPortraitService::relativeFile(BreedType::BerneseMountainDog))->toBe('dog/bernese-mountain-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BerneseMountainDog);
        expect($v1['prompt_anchor'])->toContain('Bernese Mountain Dog')->toContain('tricolour')->toContain('white blaze')
            ->and($v1['visual_traits']['color_scheme'])->toBe('tricolour: jet black, rich tan and white');

        $pet = bmdPet(76, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bernese Mountain Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; shorter_lifespan chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the shorter_lifespan chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bernese_mountain_dog'),
            config('breed_suitability.breeds.bernese_mountain_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bernese-mountain-dog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '95% CI', '%)', '58.3'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::BerneseMountainDog)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toContain('shorter_lifespan')
            // Not a conformation welfare breed (RKC Breed Watch Category 1).
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'hips_hind_legs', 'heart_and_spine', 'back_spine', 'sensitive_skin']))->toBe([])
            ->and(bmdData('bernese_mountain_dog.health._note'))->toContain("Breed Watch 'Category 1'")
            ->and(bmdData('proposed_game_parameters.bernese_mountain_dog.marketing.decision'))->toContain('in marketing yes');
    });
});
