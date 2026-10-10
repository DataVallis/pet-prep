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
| M5-R10-10 — Havanese (tenth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `havanese` (S136–S141)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.havanese` (exercise 30 min, senior 23 min,
| puppy 10 min × age capped at 30, stages 9 / 36 / 108, arrival 2 / 9 / 36 / 108,
| learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates,
| suitability apartment / family_pet / children / low_shedding + frequent_grooming;
| FCI colours only — never merle — and a fawn portrait).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function havData(string $path): mixed
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

/** @return array<string, mixed> the Havanese seeder row of (stage, from, key) */
function havRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'havanese'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("havanese.{$stage}.{$from}.{$key->value}");

    return $row;
}

function havPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->havanese()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function havPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function havLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Havanese;
        $config = BreedConfig::where('breed_slug', 'havanese')->sole();

        expect($breed->value)->toBe('havanese')
            ->and($breed->slug())->toBe('havanese')
            ->and(BreedType::fromSlug('havanese'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 110, 'breeds.havanese'])
            ->and($config->search_keywords)->toBe(['havanese', 'havanski bišon', 'havanski bison', 'bichon havanais', 'havanez'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) havData('proposed_game_parameters.havanese.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = havData('proposed_game_parameters.havanese.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Havanese pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = havPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('havanese');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_11_120000_add_havanese_breed.php');
        // down() refuses while a Havanese exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'havanese']))->toThrow(QueryException::class);
        // The Australian Shepherd (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_shepherd']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'havanese']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Havanese);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'havanese')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->count());

        BreedConfig::where('breed_slug', 'havanese')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'havanese')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'havanese')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'havanese');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_HAVANESE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.havanese';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(havRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(havData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(havRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(havData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(havRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(havData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S138) = 108.
            ->and(havRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(havData('havanese.lifespan.senior_from.months'))
            ->and(havRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * havRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(havData('havanese.lifespan.senior_from.derived_from'))->toBe(['S11', 'S138'])
            ->and(havRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(havRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(havData("{$pgp}.exercise_minutes_adult.value"))
            ->and(havRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(havRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(havRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(havData("{$pgp}.exercise_minutes_senior.value"))
            ->and(havRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe((int) round(0.75 * 30, 0, PHP_ROUND_HALF_UP))
            ->and(havRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(havRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(havData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(havRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(havData("{$pgp}.learning_multiplier.value"))
            ->and(havRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Havanese decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_HAVANESE) {
                $decided++;
                expect($row['breed_slug'])->toBe('havanese')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.havanese.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) havData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Havanese research.
        expect(json_encode([havData('havanese'), havData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced havanese entries', function () {
        $pdsa = havData('havanese.adult_weight.pdsa.value');

        // PDSA "3-6 kg" (S140), one range for both sexes (the FCI / RKC / AKC standards give height only).
        expect(havRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $pdsa[0], (float) $pdsa[1]])
            ->and(havRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.0, 6.0])
            ->and(havRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(havData('havanese.adult_weight.pdsa.source_id'))
            ->and(havRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(havData('havanese.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Not ranked by Coren (S35) → no rank, like the mutt.
            ->and(havRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(havData('havanese.trainability.coren_rank.value'))->toBeNull()
            ->and(havRow('all', 0, StageParamKey::CorenRank)['source_id'])->toBe('S35')
            ->and(havData('havanese.lifespan.rkc.value'))->toBe('> 12')
            ->and(havRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) ltrim((string) havData('havanese.lifespan.rkc.value'), '> '))
            ->and(havRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('havanese.lifespan.rkc')
            ->and(havRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S138')
            ->and(havRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            // No McMillan 2024 value was reachable — nothing unsourced at runtime.
            ->and(havData('havanese.lifespan.mcmillan_2024.value'))->toBeNull();

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->where('verified', false)->pluck('key')->all())
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

        expect($general('havanese'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = havPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (reaches the cap)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months like the Standard Poodle and the Dachshund', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('havanese', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('poodle-standard', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('dachshund', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (1.0, not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $havanese = havPet(36);
        $date = $havanese->localDate(now());

        expect($training->learningFactor($havanese))->toBe(1.0)
            ->and($training->gainPerSuccess($havanese, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'havanese', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = havPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(havLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Havanese)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 108 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'havanese'] as $breed) {
            $pin = havPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(havLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['havanese']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['havanese']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['havanese']->arrival_age_months)->toBe(108)
            ->and($pets['havanese']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Havanese appearance data.
            ->and($pets['havanese']->pet_dna['breed'])->toBe('havanese')
            ->and((int) $pets['havanese']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = havPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Havanese after the Australian Shepherd with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese'])
            ->and($breeds[11])->toBe([
                'breed' => 'havanese', 'slug' => 'havanese', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.havanese',
                'search_keywords' => BreedConfigsSeeder::configs()[11]['search_keywords'], 'sort_order' => 110,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'children', 'low_shedding'],
                    'consider' => ['frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: FCI colours only — never merle — fawn portrait)', function () {
    it('samples Havanese DNA inside the standard (FCI colours, natural long coat, tail over the back)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('havanese')
            ->and(PetDnaService::hasAppearance('havanese'))->toBeTrue()
            ->and(config('breed_appearance.havanese.verified'))->toBeFalse();

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('havanese', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $patterns[$traits['coat_pattern']] = true;
            expect($traits['tail'])->toContain('curled over the back')
                ->and($traits['coat_length'])->toContain('very long');

            $prompt = $prompts->imagePrompt('havanese', $traits);
            expect($prompt)->toContain('Havanese')
                ->toContain('natural coat')
                ->not->toContain('merle')
                ->not->toContain('brindle')
                ->not->toContain('clipped')
                ->not->toContain('trimmed')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['fawn', 'black', 'havana brown', 'tobacco', 'reddish brown', 'white'])
            ->and(array_keys($patterns))->toEqualCanonicalizing(['solid colour', 'with white patches', 'with tan markings']);
    });

    it('draws the register portrait fawn', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Havanese);

        expect($traits['coat_color'])->toBe('fawn')
            ->and($traits['coat_pattern'])->toBe('solid colour')
            ->and($portrait->prompt(BreedType::Havanese))->toContain('Havanese')->toContain('fawn')->toContain('curled over the back')
            ->and(BreedPortraitService::relativeFile(BreedType::Havanese))->toBe('dog/havanese.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Havanese);
        expect($v1['prompt_anchor'])->toContain('Havanese')->toContain('fawn')->toContain('curled over the back')->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn', 'black', 'havana brown']);

        $pet = havPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Havanese')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare-concern breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.havanese'),
            config('breed_suitability.breeds.havanese'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'havanese')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'double merle'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::Havanese)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'hips_hind_legs', 'heart_and_spine', 'back_spine']))->toBe([])
            ->and(havData('havanese.health._note'))->toContain('Not a welfare-concern breed')
            ->and(havData('proposed_game_parameters.havanese.marketing.decision'))->toContain('may appear in marketing');
    });
});
