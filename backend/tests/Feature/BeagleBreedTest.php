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
| M5-R10-06 — Beagle (sixth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `beagle` (S111–S117) and the
| runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.beagle` (exercise 60 min, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 102, arrival 2 / 9 / 36 / 102,
| learning × 0.5, paid breed, Border Collie care rates, suitability family_pet /
| sheds, chews_when_bored; standard colours only in the AI art).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bgData(string $path): mixed
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

/** @return array<string, mixed> the Beagle seeder row of (stage, from, key) */
function bgRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'beagle'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("beagle.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bgPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->beagle()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bgPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bgLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Beagle;
        $config = BreedConfig::where('breed_slug', 'beagle')->sole();

        expect($breed->value)->toBe('beagle')
            ->and($breed->slug())->toBe('beagle')
            ->and(BreedType::fromSlug('beagle'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 70, 'breeds.beagle'])
            ->and($config->search_keywords)->toBe(['beagle', 'bigl'])
            // Legacy fallback = the adult step goal David chose (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bgData('proposed_game_parameters.beagle.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bgData('proposed_game_parameters.beagle.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Beagle pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bgPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('beagle');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_06_120000_add_beagle_breed.php');
        // down() refuses while a Beagle exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'beagle']))->toThrow(QueryException::class);
        // The Cavalier (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cavalier_king_charles_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'beagle']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Beagle);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'beagle')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')->count());

        BreedConfig::where('breed_slug', 'beagle')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'beagle')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'beagle')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'beagle');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.beagle';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bgRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bgData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bgRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bgData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bgRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bgData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.28 y (S116, VetCompass) = 101.52 → 102.
            ->and(bgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bgData('beagle.lifespan.senior_from.months'))
            ->and(bgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bgData('beagle.lifespan.median_uk.value') * 12))
            ->and(bgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(102);

        expect(bgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bgData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bgRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(bgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bgData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bgRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bgData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bgData("{$pgp}.learning_multiplier.value"))
            ->and(bgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every Beagle decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE) {
                $decided++;
                expect($row['breed_slug'])->toBe('beagle')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.beagle.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bgData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Beagle research.
        expect(json_encode([bgData('beagle'), bgData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced beagle entries', function () {
        $pdsa = bgData('beagle.adult_weight.pdsa.value');

        // No weight in the FCI / RKC standards → PDSA, one range for both sexes.
        expect(bgRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual($pdsa)
            ->and(bgRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.0, 11.0])
            ->and(bgRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bgData('beagle.adult_weight.pdsa.source_id'))
            ->and(bgRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(bgData('beagle.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(bgRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(bgData('beagle.trainability.coren_rank.value'))
            ->and(bgRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(72)
            ->and(bgRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(bgData('beagle.lifespan.median_uk.value'))
            ->and(bgRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S116')
            ->and(bgRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.28);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')->where('verified', false)->pluck('key')->all())
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

        expect($general('beagle'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bgPet($arrival);
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
        'adult 101 months' => [101, LifeStage::Adult, 60, 6000],
        'senior 102 months' => [102, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 102 months, after the German Shepherd and before the Labrador', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('beagle', 101))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('beagle', 102))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-shepherd-dog', 93))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cavalier-king-charles-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('labrador-retriever', 102))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns at half the mixed-breed speed (0.5, Coren Lowest tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $beagle = bgPet(36);
        $date = $beagle->localDate(now());

        expect($training->learningFactor($beagle))->toBe(1.0)
            ->and($training->gainPerSuccess($beagle, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'beagle', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bgPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bgLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Beagle)
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
        foreach (['border_collie', 'beagle'] as $breed) {
            $pin = bgPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bgLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['beagle']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['beagle']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['beagle']->arrival_age_months)->toBe(102)
            ->and($pets['beagle']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Beagle appearance data.
            ->and($pets['beagle']->pet_dna['breed'])->toBe('beagle')
            ->and((int) $pets['beagle']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bgPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Beagle after the Cavalier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle'])
            ->and($breeds[7])->toBe([
                'breed' => 'beagle', 'slug' => 'beagle', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.beagle',
                'search_keywords' => BreedConfigsSeeder::configs()[7]['search_keywords'], 'sort_order' => 70,
                'suitability' => [
                    'suits' => ['family_pet'],
                    'consider' => ['sheds', 'chews_when_bored'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: standard colours, tricolour portrait)', function () {
    it('samples Beagle DNA inside the standard (tricolour / tan, lemon or red and white, white tail tip)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('beagle')
            ->and(PetDnaService::hasAppearance('beagle'))->toBeTrue()
            ->and(config('breed_appearance.beagle.verified'))->toBeFalse();

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('beagle', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $patterns[$traits['coat_pattern']] = true;
            if ($traits['coat_pattern'] === 'black saddle, tan head and ears, white legs, chest and tail tip') {
                expect($traits['coat_color'])->toBe('tricolour (black, tan and white)');
            }
            if ($traits['coat_pattern'] === 'pale lemon patches on white, white tail tip') {
                expect($traits['coat_color'])->toBe('lemon and white');
            }

            $prompt = $prompts->imagePrompt('beagle', $traits);
            expect($prompt)->toContain('Beagle')
                ->toContain('tail tip')
                ->toContain('long, low-set')
                ->toContain('wide nostrils')
                ->not->toContain('merle')
                ->not->toContain('chocolate')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['tricolour (black, tan and white)', 'tan and white', 'lemon and white', 'red and white'])
            ->and(count($patterns))->toBe(4);
    });

    it('draws the register portrait tricolour', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Beagle);

        expect($traits['coat_color'])->toBe('tricolour (black, tan and white)')
            ->and($traits['coat_pattern'])->toBe('black saddle, tan head and ears, white legs, chest and tail tip')
            ->and($portrait->prompt(BreedType::Beagle))->toContain('Beagle')->toContain('tricolour')->not->toContain('merle')
            ->and(BreedPortraitService::relativeFile(BreedType::Beagle))->toBe('dog/beagle.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Beagle);
        expect($v1['prompt_anchor'])->toContain('Beagle')->toContain('tricolour')->toContain('white tip')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['tricolour (black, tan and white)', 'tan and white', 'lemon and white']);

        $pet = bgPet(102, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Beagle')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app (runbook §3)', function () {
    it('keeps the percentages and the measured weight in the research only; not a marketing-excluded breed', function () {
        $app = json_encode([
            config('breed_appearance.beagle'),
            config('breed_suitability.breeds.beagle'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'beagle')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['24.27', '17.78', 'odds ratio', '19.70'] as $statistic) {
            expect((string) $app)->not->toContain($statistic);
        }
        expect(bgData('beagle.health._note'))->toContain('never shown in the app')
            ->and(bgData('proposed_game_parameters.beagle.marketing.decision'))->toContain('may appear in marketing');
    });
});
