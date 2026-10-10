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
| M5-R10 — Labrador Retriever (first breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `labrador_retriever` (S48–S62)
| and David's decisions of 2026-10-09 in
| `proposed_game_parameters.labrador_retriever` (exercise 90 min, senior 75 %,
| stages 9 / 36 / 118, arrival 2 / 9 / 36 / 118, learning × 1.8, paid breed).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function labData(string $path): mixed
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

/** @return array<string, mixed> the Labrador seeder row of (stage, from, key) */
function labRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'labrador-retriever'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("labrador-retriever.{$stage}.{$from}.{$key->value}");

    return $row;
}

function labPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->labradorRetriever()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function labPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function labLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::LabradorRetriever;
        $config = BreedConfig::where('breed_slug', 'labrador-retriever')->sole();

        expect($breed->value)->toBe('labrador_retriever')
            ->and($breed->slug())->toBe('labrador-retriever')
            ->and(BreedType::fromSlug('labrador-retriever'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 20, 'breeds.labrador_retriever'])
            ->and($config->search_keywords)->toContain('labrador', 'labradorec', 'labradorski prinašalec', 'lab', 'retriever')
            // Legacy fallback = the adult step goal David chose (90 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) labData('proposed_game_parameters.labrador_retriever.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(9000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Labrador pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = labPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('labrador_retriever');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'labrador']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_10_31_120000_add_labrador_retriever_breed.php');
        // down() refuses while a Labrador exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'labrador_retriever']))->toThrow(QueryException::class);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'labrador_retriever']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::LabradorRetriever);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'labrador-retriever')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'labrador-retriever')->count());

        BreedConfig::where('breed_slug', 'labrador-retriever')->sole()->update(['daily_steps_required' => 8500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'labrador-retriever')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'labrador-retriever')->value('daily_steps_required'))->toBe(8500);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from David\'s 2026-10-09 decisions', function () {
        $pgp = 'proposed_game_parameters.labrador_retriever';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(labRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(labData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(labRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(labData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(labRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(labData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × the McMillan 2024 median (S54), like the Border Collie.
            ->and(labRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(labData('labrador_retriever.lifespan.senior_from.months'))
            ->and(labRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(labData('labrador_retriever.lifespan.senior_from.value') * 12));

        expect(labRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(labData("{$pgp}.exercise_minutes_adult.value"))
            ->and(labRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // 75 % of 90 = 67.5; minutes are whole numbers → data.json's 68.
            ->and(labRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(labData("{$pgp}.exercise_minutes_senior.value"))
            ->and(labRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(labData("{$pgp}.learning_multiplier.value"))
            ->and(labRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.8);

        // Every R10 decision row cites the data.json entry that records it.
        foreach (BreedStageParamsSeeder::rows() as $row) {
            // M5-R10-02: the Golden Retriever rows share the constant (same day) — GoldenRetrieverBreedTest.
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10 && $row['breed_slug'] !== 'golden-retriever') {
                expect($row['breed_slug'])->toBe('labrador-retriever')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) labData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-09');
            }
        }
    });

    it('takes the breed-level numbers from the sourced labrador_retriever entries', function () {
        $akc = labData('labrador_retriever.adult_weight.akc.value');

        expect(labRow('all', 0, StageParamKey::AdultWeightKg)['value'])
            ->toBe([min($akc['female'][0], $akc['male'][0]), max($akc['female'][1], $akc['male'][1])])
            ->and(labRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(labData('labrador_retriever.adult_weight.akc.source_id'))
            ->and(labRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(labData('labrador_retriever.growth.adult_weight_reached.value'))->toBe('15–18 months')
            ->and(labRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(labData('labrador_retriever.trainability.coren_rank.value'))
            ->and(labRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(labData('labrador_retriever.lifespan.median_uk.value'))
            ->and(labRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S54');

        // Like the Border Collie: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'labrador-retriever')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'labrador-retriever')->where('verified', false)->pluck('key')->all())
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

        expect($general('labrador-retriever'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 90 minutes = 9,000 steps as young and adult dog and 68 minutes = 6,800 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = labPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 90, 9000],
        'adult 36 months' => [36, LifeStage::Adult, 90, 9000],
        'senior 118 months' => [118, LifeStage::Senior, 68, 6800],
    ]);

    it('becomes senior at 118 months, not at the mutt\'s 108', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('labrador-retriever', 117))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('labrador-retriever', 118))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mutt', 108))->toBe(LifeStage::Senior);
    });

    it('learns 1.8× the mixed-breed speed with no individual factor', function () {
        $training = app(TrainingService::class);
        $lab = labPet(36);
        $collie = disableHygieneEvents(Pet::factory()->borderCollie()->create([
            'user_id' => User::factory()->child()->create()->id, 'born_at' => now(), 'arrival_age_months' => 36,
        ]))->fresh();
        $date = $lab->localDate(now());

        expect($training->learningFactor($lab))->toBe(1.0)
            ->and($training->gainPerSuccess($lab, $date))->toBe(1.8)
            ->and($training->gainPerSuccess($collie, $collie->localDate(now())))->toBe(2.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'labrador_retriever', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = labPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(labLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::LabradorRetriever)
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
        foreach (['border_collie', 'labrador_retriever'] as $breed) {
            $pin = labPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(labLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['labrador_retriever']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['labrador_retriever']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['labrador_retriever']->arrival_age_months)->toBe(118)
            ->and($pets['labrador_retriever']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Labrador appearance data.
            ->and($pets['labrador_retriever']->pet_dna['breed'])->toBe('labrador_retriever')
            ->and((int) $pets['labrador_retriever']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = labPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Labrador after the Border Collie with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'])
            ->and($breeds[2])->toBe([
                'breed' => 'labrador_retriever', 'slug' => 'labrador-retriever', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.labrador_retriever',
                'search_keywords' => BreedConfigsSeeder::configs()[2]['search_keywords'], 'sort_order' => 20,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home', 'other_pets'],
                    'consider' => ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
                ],
            ])
            ->and($breeds[0]['suitability'])->toBe(['suits' => [], 'consider' => []]);
    });
});

describe('AI appearance', function () {
    it('samples Labrador DNA inside the standard (solid colours, hazel eyes only for chocolates, otter tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('labrador_retriever')
            ->and(PetDnaService::hasAppearance('labrador_retriever'))->toBeTrue()
            ->and(config('breed_appearance.labrador_retriever.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('labrador_retriever', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            if ($traits['eye_color'] === 'hazel') {
                expect($traits['coat_color'])->toBeIn(['chocolate brown', 'light liver brown']);
            }

            $prompt = $prompts->imagePrompt('labrador_retriever', $traits);
            expect($prompt)->toContain('Labrador Retriever')
                ->toContain('otter')
                ->toContain('close-hanging ears')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['black', 'yellow', 'light cream yellow', 'fox red yellow', 'chocolate brown', 'light liver brown']);
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::LabradorRetriever);
        expect($v1['prompt_anchor'])->toContain('Labrador Retriever')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid yellow', 'solid black', 'solid chocolate']);

        $pet = labPet(118, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Labrador Retriever')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});
