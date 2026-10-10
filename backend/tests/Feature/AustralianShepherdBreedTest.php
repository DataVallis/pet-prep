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
| M5-R10-09 — Australian Shepherd (ninth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `australian_shepherd` (S131–S135)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.australian_shepherd` (exercise 120 min, senior 90 min,
| puppy 10 min × age capped at 120, stages 9 / 36 / 90, arrival 2 / 9 / 36 / 90,
| learning × 1.0, paid breed, Border Collie care rates, suitability active_family /
| family_pet / large_home + long_daily_exercise / needs_mental_stimulation /
| may_herd_children / chews_when_bored / sheds / frequent_grooming; standard
| colours only — merle IS standard here — and a blue merle portrait).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ausData(string $path): mixed
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

/** @return array<string, mixed> the Australian Shepherd seeder row of (stage, from, key) */
function ausRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'australian-shepherd'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("australian_shepherd.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ausPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->australianShepherd()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ausPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ausLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AustralianShepherd;
        $config = BreedConfig::where('breed_slug', 'australian-shepherd')->sole();

        expect($breed->value)->toBe('australian_shepherd')
            ->and($breed->slug())->toBe('australian-shepherd')
            ->and(BreedType::fromSlug('australian-shepherd'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 100, 'breeds.australian_shepherd'])
            ->and($config->search_keywords)->toBe(['australian shepherd', 'aussie', 'avstralski ovčar', 'avstralski ovcar'])
            // Legacy fallback = the adult step goal David chose (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ausData('proposed_game_parameters.australian_shepherd.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ausData('proposed_game_parameters.australian_shepherd.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Australian Shepherd pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ausPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('australian_shepherd');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_10_120000_add_australian_shepherd_breed.php');
        // down() refuses while an Australian Shepherd exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'australian_shepherd']))->toThrow(QueryException::class);
        // The Dachshund (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dachshund']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_shepherd']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AustralianShepherd);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'australian-shepherd')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->count());

        BreedConfig::where('breed_slug', 'australian-shepherd')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'australian-shepherd')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'australian-shepherd')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'australian-shepherd');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.australian_shepherd';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ausRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ausData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ausRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ausData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ausRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ausData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S133) = 90.
            ->and(ausRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ausData('australian_shepherd.lifespan.senior_from.months'))
            ->and(ausRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ausRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ausData('australian_shepherd.lifespan.senior_from.derived_from'))->toBe(['S11', 'S133'])
            ->and(ausRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(ausRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ausData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ausRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(ausRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(ausRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ausData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ausRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ausRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ausData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(ausRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ausData("{$pgp}.learning_multiplier.value"))
            ->and(ausRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Australian Shepherd decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD) {
                $decided++;
                expect($row['breed_slug'])->toBe('australian-shepherd')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.australian_shepherd.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ausData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Australian Shepherd research.
        expect(json_encode([ausData('australian_shepherd'), ausData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced australian_shepherd entries', function () {
        $pdsa = ausData('australian_shepherd.adult_weight.pdsa.value');

        // PDSA "18-29 kg" (S134), one range for both sexes (the FCI standard gives height only).
        expect(ausRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $pdsa[0], (float) $pdsa[1]])
            ->and(ausRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([18.0, 29.0])
            ->and(ausRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ausData('australian_shepherd.adult_weight.pdsa.source_id'))
            ->and(ausRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(ausData('australian_shepherd.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(ausRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(ausData('australian_shepherd.trainability.coren_rank.value'))
            ->and(ausRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(42)
            ->and(ausData('australian_shepherd.lifespan.rkc.value'))->toBe('> 10')
            ->and(ausRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) ltrim((string) ausData('australian_shepherd.lifespan.rkc.value'), '> '))
            ->and(ausRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('australian_shepherd.lifespan.rkc')
            ->and(ausRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S133')
            ->and(ausRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            // McMillan 2024 has no Australian Shepherd value (S135) — nothing unsourced at runtime.
            ->and(ausData('australian_shepherd.lifespan.mcmillan_2024.value'))->toBeNull();

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->where('verified', false)->pluck('key')->all())
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

        expect($general('australian-shepherd'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ausPet($arrival);
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

    it('becomes senior at 90 months like the Cavalier, before the German Shepherd and the Beagle', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('australian-shepherd', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cavalier-king-charles-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-shepherd-dog', 92))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('beagle', 101))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('border-collie', 90))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (1.0, Coren Average tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $aussie = ausPet(36);
        $date = $aussie->localDate(now());

        expect($training->learningFactor($aussie))->toBe(1.0)
            ->and($training->gainPerSuccess($aussie, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'australian_shepherd', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ausPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ausLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AustralianShepherd)
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
        foreach (['border_collie', 'australian_shepherd'] as $breed) {
            $pin = ausPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ausLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['australian_shepherd']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['australian_shepherd']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['australian_shepherd']->arrival_age_months)->toBe(90)
            ->and($pets['australian_shepherd']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Australian Shepherd appearance data.
            ->and($pets['australian_shepherd']->pet_dna['breed'])->toBe('australian_shepherd')
            ->and((int) $pets['australian_shepherd']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ausPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Australian Shepherd after the Dachshund with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd'])
            ->and($breeds[10])->toBe([
                'breed' => 'australian_shepherd', 'slug' => 'australian-shepherd', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.australian_shepherd',
                'search_keywords' => BreedConfigsSeeder::configs()[10]['search_keywords'], 'sort_order' => 100,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored', 'sheds', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: standard colours — merle is standard here — blue merle portrait)', function () {
    it('samples Australian Shepherd DNA inside the standard (blue merle / black / red merle / red, white within limits, natural tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('australian_shepherd')
            ->and(PetDnaService::hasAppearance('australian_shepherd'))->toBeTrue()
            ->and(config('breed_appearance.australian_shepherd.verified'))->toBeFalse();

        $colours = [];
        $eyes = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('australian_shepherd', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $eyes[$traits['eye_color']] = true;
            expect($traits['coat_pattern'])->toContain('eyes fully surrounded by colour')
                ->and($traits['tail'])->toBe('natural long tail');

            $prompt = $prompts->imagePrompt('australian_shepherd', $traits);
            expect($prompt)->toContain('Australian Shepherd')
                ->toContain('moderate mane')
                ->not->toContain('white body')
                ->not->toContain('docked')
                ->not->toContain('double merle')
                ->not->toContain('sable')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['blue merle', 'black', 'red merle', 'red'])
            ->and(array_keys($eyes))->toEqualCanonicalizing(['brown', 'blue', 'amber']);
    });

    it('draws the register portrait blue merle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AustralianShepherd);

        expect($traits['coat_color'])->toBe('blue merle')
            ->and($traits['eye_color'])->toBe('brown')
            ->and($portrait->prompt(BreedType::AustralianShepherd))->toContain('Australian Shepherd')->toContain('blue merle')->toContain('natural long tail')
            ->and(BreedPortraitService::relativeFile(BreedType::AustralianShepherd))->toBe('dog/australian-shepherd.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AustralianShepherd);
        expect($v1['prompt_anchor'])->toContain('Australian Shepherd')->toContain('blue merle')->toContain('natural long tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['blue merle', 'black', 'red merle']);

        $pet = ausPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Australian Shepherd')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare-concern breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.australian_shepherd'),
            config('breed_suitability.breeds.australian_shepherd'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-shepherd')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'double merle'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::AustralianShepherd)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'hips_hind_legs', 'heart_and_spine', 'back_spine']))->toBe([])
            ->and(ausData('australian_shepherd.health._note'))->toContain('Not a welfare-concern breed')
            ->and(ausData('proposed_game_parameters.australian_shepherd.marketing.decision'))->toContain('may appear in marketing');
    });
});
