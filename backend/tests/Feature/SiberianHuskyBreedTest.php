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
| M5-R10-13 — Siberian Husky (thirteenth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `siberian_husky` (S158–S165)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.siberian_husky` (exercise 120 min, senior 90 min,
| puppy 10 min × age capped at 120, stages 9 / 36 / 90 — senior from the RKC lower
| bound "Over 10 years" —, arrival 2 / 9 / 36 / 90, learning × 1.0, paid breed,
| Border Collie care rates, suitability active_family / large_home +
| long_daily_exercise / secure_fencing (new key) / chews_when_bored / sheds /
| frequent_grooming; standard colours only — never merle or brindle — and a grey
| and white portrait).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function husData(string $path): mixed
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

/** @return array<string, mixed> the Siberian Husky seeder row of (stage, from, key) */
function husRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'siberian-husky'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("siberian_husky.{$stage}.{$from}.{$key->value}");

    return $row;
}

function husPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->siberianHusky()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function husPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function husLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SiberianHusky;
        $config = BreedConfig::where('breed_slug', 'siberian-husky')->sole();

        expect($breed->value)->toBe('siberian_husky')
            ->and($breed->slug())->toBe('siberian-husky')
            ->and(BreedType::fromSlug('siberian-husky'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 140, 'breeds.siberian_husky'])
            ->and($config->search_keywords)->toBe(['siberian husky', 'husky', 'huskie', 'sibirski haski', 'haski', 'sibirski husky', 'sibirec'])
            // Legacy fallback = the adult step goal David chose (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) husData('proposed_game_parameters.siberian_husky.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = husData('proposed_game_parameters.siberian_husky.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Siberian Husky pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = husPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('siberian_husky');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_14_120000_add_siberian_husky_breed.php');
        // down() refuses while a Siberian Husky exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'siberian_husky']))->toThrow(QueryException::class);
        // The Bernese Mountain Dog (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bernese_mountain_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_husky']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SiberianHusky);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'siberian-husky')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->count());

        BreedConfig::where('breed_slug', 'siberian-husky')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'siberian-husky')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'siberian-husky')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'siberian-husky');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_HUSKY)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BERNESE_MOUNTAIN_DOG)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BERNESE_SENIOR)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.siberian_husky';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(husRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(husData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(husRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(husData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(husRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(husData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S160) = 90.
            ->and(husRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(husData('siberian_husky.lifespan.senior_from.months'))
            ->and(husRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * husRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(husData('siberian_husky.lifespan.senior_from.derived_from'))->toBe(['S11', 'S160'])
            ->and(husRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(husRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(husData("{$pgp}.exercise_minutes_adult.value"))
            ->and(husRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(husRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(husRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(husData("{$pgp}.exercise_minutes_senior.value"))
            ->and(husRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(husRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(husData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(husRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(husData("{$pgp}.learning_multiplier.value"))
            ->and(husRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Siberian Husky decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_HUSKY) {
                $decided++;
                expect($row['breed_slug'])->toBe('siberian-husky')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.siberian_husky.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) husData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Siberian Husky research.
        expect(json_encode([husData('siberian_husky'), husData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced siberian_husky entries', function () {
        $fci = husData('siberian_husky.adult_weight.fci.value');

        // FCI standard (S158): dogs 20.5–28 kg, females 15.5–23 kg → overall 15.5–28 kg.
        expect(husRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) min($fci['female'][0], $fci['male'][0]), (float) max($fci['female'][1], $fci['male'][1])])
            ->and(husRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([15.5, 28.0])
            ->and(husRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(husData('siberian_husky.adult_weight.fci.source_id'))
            ->and(husRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(husData('siberian_husky.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(husRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(husData('siberian_husky.trainability.coren_rank.value'))
            ->and(husRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(45)
            ->and(husData('siberian_husky.lifespan.rkc.value'))->toBe('> 10')
            ->and(husRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) ltrim((string) husData('siberian_husky.lifespan.rkc.value'), '> '))
            ->and(husRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('siberian_husky.lifespan.rkc')
            ->and(husRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S160')
            ->and(husRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            // No McMillan 2024 value was reachable (not in the article text, S135) — nothing unsourced at runtime;
            // the Finnish average (S165) is an alternative only.
            ->and(husData('siberian_husky.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(husData('siberian_husky.lifespan.finland_average.value'))->toBe(9.75);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->where('verified', false)->pluck('key')->all())
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

        expect($general('siberian-husky'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = husPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age, below the cap)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months like the Australian Shepherd, before the German Shepherd and the Beagle', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('siberian-husky', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('siberian-husky', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-shepherd-dog', 92))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('beagle', 101))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('border-collie', 90))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (1.0, Coren Average tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $husky = husPet(36);
        $date = $husky->localDate(now());

        expect($training->learningFactor($husky))->toBe(1.0)
            ->and($training->gainPerSuccess($husky, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'siberian_husky', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = husPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(husLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SiberianHusky)
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
        foreach (['border_collie', 'siberian_husky'] as $breed) {
            $pin = husPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(husLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['siberian_husky']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['siberian_husky']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['siberian_husky']->arrival_age_months)->toBe(90)
            ->and($pets['siberian_husky']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Siberian Husky appearance data.
            ->and($pets['siberian_husky']->pet_dna['breed'])->toBe('siberian_husky')
            ->and((int) $pets['siberian_husky']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = husPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Siberian Husky after the Bernese Mountain Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'])
            ->and($breeds[14])->toBe([
                'breed' => 'siberian_husky', 'slug' => 'siberian-husky', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.siberian_husky',
                'search_keywords' => BreedConfigsSeeder::configs()[14]['search_keywords'], 'sort_order' => 140,
                'suitability' => [
                    'suits' => ['active_family', 'large_home'],
                    'consider' => ['long_daily_exercise', 'secure_fencing', 'chews_when_bored', 'sheds', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: standard colours only — never merle or brindle — grey and white portrait)', function () {
    it('samples Siberian Husky DNA inside the standard (black, grey, agouti, sable, red and white; blue or brown eyes)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('siberian_husky')
            ->and(PetDnaService::hasAppearance('siberian_husky'))->toBeTrue()
            ->and(config('breed_appearance.siberian_husky.verified'))->toBeFalse();

        $colours = [];
        $eyes = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('siberian_husky', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $eyes[$traits['eye_color']] = true;
            expect($traits['tail'])->toBe('well-furred fox-brush tail')
                ->and($traits['ear_carriage'])->toContain('erect');

            $prompt = $prompts->imagePrompt('siberian_husky', $traits);
            expect($prompt)->toContain('Siberian Husky')
                ->toContain('medium-length')
                ->not->toContain('merle')
                ->not->toContain('brindle')
                ->not->toContain('heavy-boned')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['grey and white with a white face mask', 'black and white with a white face mask', 'red and white with a white face mask', 'agouti', 'sable and white', 'pure white'])
            ->and(array_keys($eyes))->toEqualCanonicalizing(['blue, almond-shaped', 'brown, almond-shaped', 'one blue and one brown, almond-shaped']);
    });

    it('draws the register portrait grey and white with blue eyes', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SiberianHusky);

        expect($traits['coat_color'])->toBe('grey and white with a white face mask')
            ->and($traits['eye_color'])->toBe('blue, almond-shaped')
            ->and($portrait->prompt(BreedType::SiberianHusky))->toContain('Siberian Husky')->toContain('grey and white')->toContain('fox-brush tail')
            ->and(BreedPortraitService::relativeFile(BreedType::SiberianHusky))->toBe('dog/siberian-husky.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SiberianHusky);
        expect($v1['prompt_anchor'])->toContain('Siberian Husky')->toContain('grey and white')->toContain('fox-brush tail')->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['grey and white', 'black and white', 'red and white'])
            ->and($v1['visual_traits']['eye_color'])->toBeIn(['blue', 'brown']);

        $pet = husPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Siberian Husky')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a conformation welfare-concern breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.siberian_husky'),
            config('breed_suitability.breeds.siberian_husky'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-husky')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '9 years 9 months', '85-95'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        // Merle / brindle appear only in the quoted standard (disqualifying), never as a drawable trait.
        expect((string) json_encode(config('breed_appearance.siberian_husky.traits')))->not->toContain('merle')->not->toContain('brindle');
        $consider = app(BreedSuitability::class)->for(BreedType::SiberianHusky)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'hips_hind_legs', 'heart_and_spine', 'back_spine', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            // Breed Watch Category 2 lists only body condition ("Too fat" / "Too thin", S164) — not conformation.
            ->and(husData('siberian_husky.health._note'))->toContain('Not a conformation welfare-concern breed')
            ->and(husData('siberian_husky.health.breed_watch.quote'))->toBe('Too fat')
            ->and(husData('proposed_game_parameters.siberian_husky.marketing.decision'))->toContain('in marketing yes');
    });
});
