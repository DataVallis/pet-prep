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
| M5-R10-03 — French Bulldog (third breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `french_bulldog` (S76–S94)
| and David's decisions of 2026-10-10 (~06:20) in
| `proposed_game_parameters.french_bulldog` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 88, arrival 2 / 9 / 36 / 88,
| learning × 0.7, paid breed, Border Collie care rates, suitability incl. the
| new brachycephalic_breathing; moderate AI face, standard colours only).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function fbData(string $path): mixed
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

/** @return array<string, mixed> the French Bulldog seeder row of (stage, from, key) */
function fbRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'french-bulldog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("french-bulldog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function fbPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->frenchBulldog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function fbPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function fbLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::FrenchBulldog;
        $config = BreedConfig::where('breed_slug', 'french-bulldog')->sole();

        expect($breed->value)->toBe('french_bulldog')
            ->and($breed->slug())->toBe('french-bulldog')
            ->and(BreedType::fromSlug('french-bulldog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 40, 'breeds.french_bulldog'])
            ->and($config->search_keywords)->toBe(['french bulldog', 'frenchie', 'french', 'bulldog', 'francoski buldog', 'buldog'])
            // Legacy fallback = the adult step goal David chose (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) fbData('proposed_game_parameters.french_bulldog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = fbData('proposed_game_parameters.french_bulldog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a French Bulldog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = fbPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('french_bulldog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'frenchie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_02_120000_add_french_bulldog_breed.php');
        // down() refuses while a French Bulldog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'french_bulldog']))->toThrow(QueryException::class);
        // The Golden Retriever (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'golden_retriever']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'french_bulldog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::FrenchBulldog);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'french-bulldog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'french-bulldog')->count());

        BreedConfig::where('breed_slug', 'french-bulldog')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'french-bulldog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'french-bulldog')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'french-bulldog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'german-shepherd-dog'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_FRENCH_BULLDOG)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'french-bulldog')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from David\'s 2026-10-10 decisions', function () {
        $pgp = 'proposed_game_parameters.french_bulldog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(fbRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(fbData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(fbRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(fbData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(fbRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(fbData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.8 y (S54, McMillan 2024) = 88.2 → 88.
            ->and(fbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(fbData('french_bulldog.lifespan.senior_from.months'))
            ->and(fbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * fbData('french_bulldog.lifespan.median_uk.value') * 12))
            ->and(fbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(88);

        expect(fbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(fbData("{$pgp}.exercise_minutes_adult.value"))
            ->and(fbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(fbRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(fbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(fbData("{$pgp}.exercise_minutes_senior.value"))
            ->and(fbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes (David 2026-10-10).
            ->and(fbRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(fbData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(fbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(fbData("{$pgp}.learning_multiplier.value"))
            ->and(fbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every French Bulldog decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_FRENCH_BULLDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('french-bulldog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.french_bulldog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) fbData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the French Bulldog research.
        expect(json_encode([fbData('french_bulldog'), fbData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced french_bulldog entries', function () {
        $fci = fbData('french_bulldog.adult_weight.fci.value');

        expect(fbRow('all', 0, StageParamKey::AdultWeightKg)['value'])
            ->toEqual([min($fci['female'][0], $fci['male'][0]), max($fci['female'][1], $fci['male'][1])])
            ->and(fbRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(fbData('french_bulldog.adult_weight.fci.source_id'))
            ->and(fbRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 15])
            ->and(fbData('french_bulldog.growth.adult_weight_reached.value'))->toStartWith('9–15 months')
            ->and(fbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(fbData('french_bulldog.trainability.coren_rank.value'))
            ->and(fbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(58)
            ->and(fbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(fbData('french_bulldog.lifespan.median_uk.value'))
            ->and(fbRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S54');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'french-bulldog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'french-bulldog')->where('verified', false)->pluck('key')->all())
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

        expect($general('french-bulldog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = fbPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (cap reached)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped at adult)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 87 months' => [87, LifeStage::Adult, 60, 6000],
        'senior 88 months' => [88, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 88 months, earlier than the other dogs', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('french-bulldog', 87))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('french-bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mutt', 88))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns 0.7× the mixed-breed speed with no individual factor', function () {
        $training = app(TrainingService::class);
        $frenchie = fbPet(36);
        $date = $frenchie->localDate(now());

        expect($training->learningFactor($frenchie))->toBe(1.0)
            ->and($training->gainPerSuccess($frenchie, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'french_bulldog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = fbPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(fbLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::FrenchBulldog)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 88 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'french_bulldog'] as $breed) {
            $pin = fbPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(fbLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['french_bulldog']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['french_bulldog']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['french_bulldog']->arrival_age_months)->toBe(88)
            ->and($pets['french_bulldog']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the French Bulldog appearance data.
            ->and($pets['french_bulldog']->pet_dna['breed'])->toBe('french_bulldog')
            ->and((int) $pets['french_bulldog']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = fbPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the French Bulldog after the Golden Retriever with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd'])
            ->and($breeds[4])->toBe([
                'breed' => 'french_bulldog', 'slug' => 'french-bulldog', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.french_bulldog',
                'search_keywords' => BreedConfigsSeeder::configs()[4]['search_keywords'], 'sort_order' => 40,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'children'],
                    'consider' => ['brachycephalic_breathing'],
                ],
            ]);
    });
});

describe('AI appearance (David 2026-10-10: moderate face, standard colours)', function () {
    it('samples French Bulldog DNA inside the standard (fawn / brindle / pied, never merle or blue, open nostrils)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('french_bulldog')
            ->and(PetDnaService::hasAppearance('french_bulldog'))->toBeTrue()
            ->and(config('breed_appearance.french_bulldog.verified'))->toBeFalse();

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('french_bulldog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $patterns[$traits['coat_pattern']] = true;
            if ($traits['coat_pattern'] === 'with a dark mask') {
                expect($traits['coat_color'])->toBeIn(['fawn', 'light fawn']);
            }

            $prompt = $prompts->imagePrompt('french_bulldog', $traits);
            expect($prompt)->toContain('French Bulldog')
                ->toContain('bat')
                ->toContain('visibly open nostrils')
                ->toContain('not exaggerated')
                ->not->toContain('merle')
                ->not->toContain('blue')
                ->not->toContain('black and tan')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['fawn', 'brindle', 'light fawn'])
            ->and(count($patterns))->toBe(4);
    });

    it('draws the register portrait fawn and solid with the moderate muzzle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::FrenchBulldog);

        expect($traits['coat_color'])->toBe('fawn')
            ->and($traits['coat_pattern'])->toBe('solid')
            ->and($portrait->prompt(BreedType::FrenchBulldog))->toContain('French Bulldog')->toContain('visibly open nostrils')->not->toContain('merle')
            ->and(BreedPortraitService::relativeFile(BreedType::FrenchBulldog))->toBe('dog/french-bulldog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::FrenchBulldog);
        expect($v1['prompt_anchor'])->toContain('French Bulldog')->toContain('visibly open nostrils')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn', 'brindle', 'pied (white with fawn patches)']);

        $pet = fbPet(88, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('French Bulldog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app (David 2026-10-10)', function () {
    it('keeps the odds ratios and percentages in the research only', function () {
        $app = json_encode([
            config('breed_appearance.french_bulldog'),
            config('breed_suitability.breeds.french_bulldog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'french-bulldog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['30.89', '42.14', '2.10', '14.18', 'odds ratio', 'BOAS', '3104'] as $statistic) {
            expect((string) $app)->not->toContain($statistic);
        }
        expect(fbData('french_bulldog.health._note'))->toContain('do not show any of them to children')
            ->and(fbData('proposed_game_parameters.french_bulldog.marketing.decision'))->toContain('NOT in marketing');
    });
});
