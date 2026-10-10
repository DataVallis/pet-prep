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
| M5-R10-04 — German Shepherd Dog (fourth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `german_shepherd` (S95–S102)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.german_shepherd` (exercise 120 min, senior 90 min,
| puppy 10 min × age capped at 120, stages 9 / 36 / 93, arrival 2 / 9 / 36 / 93,
| learning × 1.9, paid breed, Border Collie care rates, suitability incl. the
| new hips_hind_legs; level back in the AI art, standard colours only).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gsData(string $path): mixed
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

/** @return array<string, mixed> the German Shepherd seeder row of (stage, from, key) */
function gsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'german-shepherd-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("german-shepherd-dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->germanShepherd()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GermanShepherd;
        $config = BreedConfig::where('breed_slug', 'german-shepherd-dog')->sole();

        expect($breed->value)->toBe('german_shepherd')
            ->and($breed->slug())->toBe('german-shepherd-dog')
            ->and(BreedType::fromSlug('german-shepherd-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 50, 'breeds.german_shepherd'])
            ->and($config->search_keywords)->toBe(['german shepherd', 'german shepherd dog', 'gsd', 'alsatian', 'nemški ovčar', 'nemski ovcar', 'ovčar', 'ovcar'])
            // Legacy fallback = the adult step goal David chose (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gsData('proposed_game_parameters.german_shepherd.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gsData('proposed_game_parameters.german_shepherd.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a German Shepherd pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('german_shepherd');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_03_120000_add_german_shepherd_breed.php');
        // down() refuses while a German Shepherd exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'german_shepherd']))->toThrow(QueryException::class);
        // The French Bulldog (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'french_bulldog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_shepherd']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GermanShepherd);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'german-shepherd-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shepherd-dog')->count());

        BreedConfig::where('breed_slug', 'german-shepherd-dog')->sole()->update(['daily_steps_required' => 11000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'german-shepherd-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'german-shepherd-dog')->value('daily_steps_required'))->toBe(11000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'german-shepherd-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_SHEPHERD)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shepherd-dog')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.german_shepherd';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10.3 y (S101, VetCompass) = 92.7 → 93.
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gsData('german_shepherd.lifespan.senior_from.months'))
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gsData('german_shepherd.lifespan.median_uk.value') * 12))
            ->and(gsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(93);

        expect(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(gsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gsData("{$pgp}.learning_multiplier.value"))
            ->and(gsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.9);

        // Every German Shepherd decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_SHEPHERD) {
                $decided++;
                expect($row['breed_slug'])->toBe('german-shepherd-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.german_shepherd.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the German Shepherd research.
        expect(json_encode([gsData('german_shepherd'), gsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced german_shepherd entries', function () {
        $fci = gsData('german_shepherd.adult_weight.fci.value');

        expect(gsRow('all', 0, StageParamKey::AdultWeightKg)['value'])
            ->toEqual([min($fci['female'][0], $fci['male'][0]), max($fci['female'][1], $fci['male'][1])])
            ->and(gsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gsData('german_shepherd.adult_weight.fci.source_id'))
            ->and(gsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(gsData('german_shepherd.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(gsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(gsData('german_shepherd.trainability.coren_rank.value'))
            ->and(gsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(3)
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(gsData('german_shepherd.lifespan.median_uk.value'))
            ->and(gsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S101');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shepherd-dog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shepherd-dog')->where('verified', false)->pluck('key')->all())
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

        expect($general('german-shepherd-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gsPet($arrival);
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
        'adult 92 months' => [92, LifeStage::Adult, 120, 12000],
        'senior 93 months' => [93, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 93 months, between the French Bulldog and the other dogs', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('german-shepherd-dog', 92))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('german-shepherd-dog', 93))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('french-bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mutt', 93))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns 1.9× the mixed-breed speed with no individual factor', function () {
        $training = app(TrainingService::class);
        $shepherd = gsPet(36);
        $date = $shepherd->localDate(now());

        expect($training->learningFactor($shepherd))->toBe(1.0)
            ->and($training->gainPerSuccess($shepherd, $date))->toBe(1.9);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'german_shepherd', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GermanShepherd)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 93 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'german_shepherd'] as $breed) {
            $pin = gsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gsLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['german_shepherd']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['german_shepherd']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['german_shepherd']->arrival_age_months)->toBe(93)
            ->and($pets['german_shepherd']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the German Shepherd appearance data.
            ->and($pets['german_shepherd']->pet_dna['breed'])->toBe('german_shepherd')
            ->and((int) $pets['german_shepherd']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the German Shepherd after the French Bulldog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund'])
            ->and($breeds[5])->toBe([
                'breed' => 'german_shepherd', 'slug' => 'german-shepherd-dog', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.german_shepherd',
                'search_keywords' => BreedConfigsSeeder::configs()[5]['search_keywords'], 'sort_order' => 50,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'sheds', 'frequent_grooming', 'chews_when_bored', 'hips_hind_legs'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: level back, standard colours)', function () {
    it('samples German Shepherd DNA inside the standard (black-and-tan / sable / black, never white, level back)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('german_shepherd')
            ->and(PetDnaService::hasAppearance('german_shepherd'))->toBeTrue()
            ->and(config('breed_appearance.german_shepherd.verified'))->toBeFalse();

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('german_shepherd', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $patterns[$traits['coat_pattern']] = true;
            if ($traits['coat_pattern'] === 'darker tips and a dark mask') {
                expect($traits['coat_color'])->toBe('sable');
            }
            if ($traits['coat_pattern'] === 'solid') {
                expect($traits['coat_color'])->toBe('solid black');
            }

            $prompt = $prompts->imagePrompt('german_shepherd', $traits);
            expect($prompt)->toContain('German Shepherd')
                ->toContain('erect')
                ->toContain('level back')
                ->not->toContain('white')
                ->not->toContain('blue')
                ->not->toContain('liver')
                ->not->toContain('sloping')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['black and tan', 'black and gold', 'sable', 'solid black'])
            ->and(count($patterns))->toBe(3);
    });

    it('draws the register portrait black and tan with a saddle and a level back', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GermanShepherd);

        expect($traits['coat_color'])->toBe('black and tan')
            ->and($traits['coat_pattern'])->toBe('black saddle with tan legs, chest and face')
            ->and($portrait->prompt(BreedType::GermanShepherd))->toContain('German Shepherd')->toContain('level back')->not->toContain('white')
            ->and(BreedPortraitService::relativeFile(BreedType::GermanShepherd))->toBe('dog/german-shepherd-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GermanShepherd);
        expect($v1['prompt_anchor'])->toContain('German Shepherd')->toContain('level back')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black and tan', 'sable', 'solid black']);

        $pet = gsPet(93, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('German Shepherd')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app (runbook §3)', function () {
    it('keeps the percentages in the research only and records the marketing exclusion', function () {
        $app = json_encode([
            config('breed_appearance.german_shepherd'),
            config('breed_suitability.breeds.german_shepherd'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shepherd-dog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['16.3', '14.9', '5.18', '4.76', '6.75', 'odds ratio', 'Category Three'] as $statistic) {
            expect((string) $app)->not->toContain($statistic);
        }
        expect(gsData('german_shepherd.health._note'))->toContain('never shown in the app')
            ->and(gsData('proposed_game_parameters.german_shepherd.marketing.decision'))->toContain('in marketing YES');
    });
});
