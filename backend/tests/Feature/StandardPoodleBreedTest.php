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
| M5-R10-07 — Standard Poodle (seventh breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `standard_poodle` (S118–S123)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.standard_poodle` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 108, arrival 2 / 9 / 36 / 108,
| learning × 2.0, paid breed, Border Collie care rates, suitability children /
| large_home / other_pets / low_shedding and frequent_grooming — never
| "hypoallergenic"; standard solid colours only in the AI art).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function stpData(string $path): mixed
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

/** @return array<string, mixed> the Standard Poodle seeder row of (stage, from, key) */
function stpRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'poodle-standard'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("standard_poodle.{$stage}.{$from}.{$key->value}");

    return $row;
}

function stpPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->standardPoodle()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function stpPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function stpLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::StandardPoodle;
        $config = BreedConfig::where('breed_slug', 'poodle-standard')->sole();

        expect($breed->value)->toBe('standard_poodle')
            ->and($breed->slug())->toBe('poodle-standard')
            ->and(BreedType::fromSlug('poodle-standard'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 80, 'breeds.standard_poodle'])
            ->and($config->search_keywords)->toBe(['poodle (standard)', 'standard poodle', 'poodle', 'veliki koder', 'koder', 'veliki pudelj', 'pudelj', 'standardni pudelj'])
            // Legacy fallback = the adult step goal David chose (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) stpData('proposed_game_parameters.standard_poodle.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = stpData('proposed_game_parameters.standard_poodle.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Standard Poodle pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = stpPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('standard_poodle');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_07_120000_add_standard_poodle_breed.php');
        // down() refuses while a Standard Poodle exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'standard_poodle']))->toThrow(QueryException::class);
        // The Beagle (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'beagle']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'standard_poodle']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::StandardPoodle);
    });

    it('moves the deployed keyword list to the Slovenian name "veliki koder" unless an admin edited it (David 2026-10-10)', function () {
        $migration = require database_path('migrations/2026_11_08_120000_standard_poodle_koder_keywords.php');
        $old = ['poodle (standard)', 'standard poodle', 'poodle', 'veliki pudelj', 'pudelj', 'standardni pudelj'];
        $keywords = fn () => BreedConfig::where('breed_slug', 'poodle-standard')->sole()->fresh()->search_keywords;
        $new = BreedConfigsSeeder::configs()[8]['search_keywords'];

        // The row as the first deploy (insert-only seeder) left it.
        BreedConfig::where('breed_slug', 'poodle-standard')->update(['search_keywords' => json_encode($old)]);
        $migration->up();
        expect($keywords())->toBe($new)->toContain('koder')->toContain('veliki koder')->toContain('pudelj');

        $migration->down();
        expect($keywords())->toBe($old);

        // An admin edit is never overwritten.
        BreedConfig::where('breed_slug', 'poodle-standard')->update(['search_keywords' => json_encode(['moj pudelj'])]);
        $migration->up();
        expect($keywords())->toBe(['moj pudelj']);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'poodle-standard')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->count());

        BreedConfig::where('breed_slug', 'poodle-standard')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'poodle-standard')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'poodle-standard')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'poodle-standard');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'dachshund', 'australian-shepherd', 'havanese'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.standard_poodle';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(stpRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(stpData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(stpRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stpData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(stpRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stpData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S120) = 108.
            ->and(stpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stpData('standard_poodle.lifespan.senior_from.months'))
            ->and(stpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * stpRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(stpData('standard_poodle.lifespan.senior_from.derived_from'))->toBe(['S11', 'S120'])
            ->and(stpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(stpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stpData("{$pgp}.exercise_minutes_adult.value"))
            ->and(stpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(stpRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(stpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stpData("{$pgp}.exercise_minutes_senior.value"))
            ->and(stpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(stpRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(stpData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(stpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(stpData("{$pgp}.learning_multiplier.value"))
            ->and(stpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(2.0);

        // Every Standard Poodle decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE) {
                $decided++;
                expect($row['breed_slug'])->toBe('poodle-standard')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.standard_poodle.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) stpData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Standard Poodle research.
        expect(json_encode([stpData('standard_poodle'), stpData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced standard_poodle entries', function () {
        $pdsa = stpData('standard_poodle.adult_weight.pdsa.value');

        // No weight in the FCI / RKC standards → PDSA; the row spans the lightest female to the heaviest male.
        expect(stpRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $pdsa['female'][0], (float) $pdsa['male'][1]])
            ->and(stpRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([21.0, 35.0])
            ->and(stpRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(stpData('standard_poodle.adult_weight.pdsa.source_id'))
            ->and(stpRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(stpData('standard_poodle.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(stpRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(stpData('standard_poodle.trainability.coren_rank.value'))
            ->and(stpRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(2)
            ->and(stpData('standard_poodle.lifespan.rkc.value'))->toBe('> 12')
            ->and(stpRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) ltrim((string) stpData('standard_poodle.lifespan.rkc.value'), '> '))
            ->and(stpRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('standard_poodle.lifespan.rkc')
            ->and(stpRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S120')
            ->and(stpRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            // The pooled "Poodle" 14.0 y (all varieties, S123) is never a runtime number.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->pluck('value')->flatten()->all())->not->toContain(14.0);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->where('verified', false)->pluck('key')->all())
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

        expect($general('poodle-standard'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = stpPet($arrival);
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
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, after the Beagle and before the Labrador', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('poodle-standard', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('poodle-standard', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('beagle', 102))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cavalier-king-charles-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('labrador-retriever', 108))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns at twice the mixed-breed speed (2.0, Coren Brightest tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $poodle = stpPet(36);
        $date = $poodle->localDate(now());

        expect($training->learningFactor($poodle))->toBe(1.0)
            ->and($training->gainPerSuccess($poodle, $date))->toBe(2.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'standard_poodle', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = stpPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(stpLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::StandardPoodle)
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
        foreach (['border_collie', 'standard_poodle'] as $breed) {
            $pin = stpPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(stpLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['standard_poodle']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['standard_poodle']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['standard_poodle']->arrival_age_months)->toBe(108)
            ->and($pets['standard_poodle']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Standard Poodle appearance data.
            ->and($pets['standard_poodle']->pet_dna['breed'])->toBe('standard_poodle')
            ->and((int) $pets['standard_poodle']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = stpPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Standard Poodle after the Beagle with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese'])
            ->and($breeds[8])->toBe([
                'breed' => 'standard_poodle', 'slug' => 'poodle-standard', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.standard_poodle',
                'search_keywords' => BreedConfigsSeeder::configs()[8]['search_keywords'], 'sort_order' => 80,
                'suitability' => [
                    'suits' => ['children', 'large_home', 'other_pets', 'low_shedding'],
                    'consider' => ['frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: standard solid colours, black portrait)', function () {
    it('samples Standard Poodle DNA inside the standard (five solid colours, no white marks, natural short trim)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('standard_poodle')
            ->and(PetDnaService::hasAppearance('standard_poodle'))->toBeTrue()
            ->and(config('breed_appearance.standard_poodle.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('standard_poodle', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_pattern'])->toBe('one solid colour, no white marks');

            $prompt = $prompts->imagePrompt('standard_poodle', $traits);
            expect($prompt)->toContain('Standard Poodle')
                ->toContain('curly')
                ->toContain('short, even all-over trim')
                ->toContain('undocked')
                ->not->toContain('merle')
                ->not->toContain('parti')
                ->not->toContain('apricot')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['solid black', 'solid white', 'solid brown', 'solid grey', 'solid fawn']);
    });

    it('draws the register portrait solid black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::StandardPoodle);

        expect($traits['coat_color'])->toBe('solid black')
            ->and($traits['coat_pattern'])->toBe('one solid colour, no white marks')
            ->and($portrait->prompt(BreedType::StandardPoodle))->toContain('Standard Poodle')->toContain('solid black')->not->toContain('merle')
            ->and(BreedPortraitService::relativeFile(BreedType::StandardPoodle))->toBe('dog/poodle-standard.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::StandardPoodle);
        expect($v1['prompt_anchor'])->toContain('Standard Poodle')->toContain('solid black')->toContain('short, even all-over trim')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid black', 'solid white', 'solid brown']);

        $pet = stpPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Standard Poodle')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics and no "hypoallergenic" in the app (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and of the word hypoallergenic; not a marketing-excluded breed', function () {
        $app = json_encode([
            config('breed_appearance.standard_poodle'),
            config('breed_suitability.breeds.standard_poodle'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'poodle-standard')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'Hypoallergenic', '10%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(stpData('standard_poodle.health._note'))->toContain('never shown in the app')
            ->and(stpData('proposed_game_parameters.standard_poodle.marketing.decision'))->toContain('may appear in marketing');
    });
});
