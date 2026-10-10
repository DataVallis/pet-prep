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
use App\Services\ChildProfileService;
use App\Services\FalAiService;
use App\Services\LifeStageService;
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
| M5-R10-02 — Golden Retriever (second breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `golden_retriever` (S63–S75)
| and David's decisions of 2026-10-09 (~22:40) in
| `proposed_game_parameters.golden_retriever` (exercise 120 min, senior 90 min,
| stages 9 / 36 / 119, arrival 2 / 9 / 36 / 119, learning × 1.9, paid breed,
| Border Collie care rates, suitability incl. the new frequent_grooming).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function grData(string $path): mixed
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

/** @return array<string, mixed> the Golden Retriever seeder row of (stage, from, key) */
function grRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'golden-retriever'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("golden-retriever.{$stage}.{$from}.{$key->value}");

    return $row;
}

function grPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->goldenRetriever()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function grPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function grLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GoldenRetriever;
        $config = BreedConfig::where('breed_slug', 'golden-retriever')->sole();

        expect($breed->value)->toBe('golden_retriever')
            ->and($breed->slug())->toBe('golden-retriever')
            ->and(BreedType::fromSlug('golden-retriever'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 30, 'breeds.golden_retriever'])
            ->and($config->search_keywords)->toBe(['golden', 'golden retriever', 'zlati prinašalec', 'zlati prinasalec', 'retriever'])
            // Legacy fallback = the adult step goal David chose (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) grData('proposed_game_parameters.golden_retriever.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Golden Retriever pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = grPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('golden_retriever');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'golden']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_01_120000_add_golden_retriever_breed.php');
        // down() refuses while a Golden Retriever exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'golden_retriever']))->toThrow(QueryException::class);
        // The Labrador (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'labrador_retriever']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'golden_retriever']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GoldenRetriever);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'golden-retriever')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'golden-retriever')->count());

        BreedConfig::where('breed_slug', 'golden-retriever')->sole()->update(['daily_steps_required' => 8500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'golden-retriever')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'golden-retriever')->value('daily_steps_required'))->toBe(8500);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from David\'s 2026-10-09 decisions', function () {
        $pgp = 'proposed_game_parameters.golden_retriever';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(grRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(grData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(grRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(grData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(grRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(grData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.2 y (S15, Dogs Trust summary of McMillan 2024) = 118.8 → 119.
            ->and(grRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(grData('golden_retriever.lifespan.senior_from.months'))
            ->and(grRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(grData('golden_retriever.lifespan.senior_from.value') * 12));

        expect(grRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(grData("{$pgp}.exercise_minutes_adult.value"))
            ->and(grRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(grRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(grRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(grData("{$pgp}.exercise_minutes_senior.value"))
            ->and(grRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(grRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(grData("{$pgp}.learning_multiplier.value"))
            ->and(grRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.9);

        // Every Golden R10 decision row cites the data.json entry that records it.
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10 && $row['breed_slug'] === 'golden-retriever') {
                expect($row['ref'])->toStartWith('proposed_game_parameters.golden_retriever.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) grData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-09');
            }
        }
    });

    it('takes the breed-level numbers from the sourced golden_retriever entries', function () {
        $akc = grData('golden_retriever.adult_weight.akc.value');

        expect(grRow('all', 0, StageParamKey::AdultWeightKg)['value'])
            ->toBe([min($akc['female'][0], $akc['male'][0]), max($akc['female'][1], $akc['male'][1])])
            ->and(grRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(grData('golden_retriever.adult_weight.akc.source_id'))
            ->and(grRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(grData('golden_retriever.growth.adult_weight_reached.value'))->toBe('15–18 months')
            ->and(grRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(grData('golden_retriever.trainability.coren_rank.value'))
            ->and(grRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(grData('golden_retriever.lifespan.median_uk.value'))
            ->and(grRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S15')
            ->and(grRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(4);

        // Like the Border Collie: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'golden-retriever')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'golden-retriever')->where('verified', false)->pluck('key')->all())
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

        expect($general('golden-retriever'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = grPet($arrival);
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
        'adult 118 months (Labrador / Border Collie are senior here)' => [118, LifeStage::Adult, 120, 12000],
        'senior 119 months' => [119, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 119 months, one month after the Labrador', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 119))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('labrador-retriever', 118))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mutt', 108))->toBe(LifeStage::Senior);
    });

    it('learns 1.9× the mixed-breed speed with no individual factor (between the Labrador and the Border Collie)', function () {
        $training = app(TrainingService::class);
        $golden = grPet(36);
        $collie = disableHygieneEvents(Pet::factory()->borderCollie()->create([
            'user_id' => User::factory()->child()->create()->id, 'born_at' => now(), 'arrival_age_months' => 36,
        ]))->fresh();
        $date = $golden->localDate(now());

        expect($training->learningFactor($golden))->toBe(1.0)
            ->and($training->gainPerSuccess($golden, $date))->toBe(1.9)
            ->and($training->gainPerSuccess($collie, $collie->localDate(now())))->toBe(2.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'golden_retriever', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = grPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(grLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GoldenRetriever)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'golden_retriever'] as $breed) {
            $pin = grPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(grLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['golden_retriever']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['golden_retriever']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['golden_retriever']->arrival_age_months)->toBe(119)
            ->and($pets['golden_retriever']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Golden Retriever appearance data.
            ->and($pets['golden_retriever']->pet_dna['breed'])->toBe('golden_retriever')
            ->and((int) $pets['golden_retriever']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = grPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Golden Retriever after the Labrador with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle'])
            ->and($breeds[3])->toBe([
                'breed' => 'golden_retriever', 'slug' => 'golden-retriever', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.golden_retriever',
                'search_keywords' => BreedConfigsSeeder::configs()[3]['search_keywords'], 'sort_order' => 30,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'children', 'first_time_owner', 'large_home', 'other_pets'],
                    'consider' => ['long_daily_exercise', 'sheds', 'food_motivated_weight', 'frequent_grooming'],
                ],
            ])
            ->and($breeds[0]['suitability'])->toBe(['suits' => [], 'consider' => []]);
    });
});

describe('AI appearance', function () {
    it('samples Golden DNA inside the standard (gold or cream, never red or mahogany, feathered coat, dark eyes)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('golden_retriever')
            ->and(PetDnaService::hasAppearance('golden_retriever'))->toBeTrue()
            ->and(config('breed_appearance.golden_retriever.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('golden_retriever', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['eye_color'])->toBeIn(['dark brown', 'medium brown']);

            $prompt = $prompts->imagePrompt('golden_retriever', $traits);
            expect($prompt)->toContain('Golden Retriever')
                ->toContain('feathered')
                ->toContain('water-resistant')
                ->toContain('moderate-sized hanging ears')
                ->not->toContain('mahogany')
                ->not->toContain(' red')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['rich gold', 'light gold', 'deep gold', 'cream', 'pale cream']);
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GoldenRetriever);
        expect($v1['prompt_anchor'])->toContain('Golden Retriever')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['rich gold', 'light gold', 'cream']);

        $pet = grPet(119, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Golden Retriever')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app (David 2026-10-09)', function () {
    it('keeps the cancer figures in the research only', function () {
        $app = json_encode([config('breed_appearance.golden_retriever'), config('breed_suitability.breeds.golden_retriever'), BreedStageParamsSeeder::rows()], JSON_UNESCAPED_UNICODE);

        expect(strtolower((string) $app))->not->toContain('cancer')
            ->and(grData('golden_retriever.health._note'))->toContain('No cancer statistic');
    });
});
