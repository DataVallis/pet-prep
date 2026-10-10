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
| M5-R10-08 — Dachshund, standard size (eighth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `dachshund` (S124–S130)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.dachshund` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 108, arrival 2 / 9 / 36 / 108,
| learning × 1.0, paid breed, Border Collie care rates, suitability children /
| back_spine (new, welfare rule) / sheds / needs_mental_stimulation; standard
| colours only — never dapple — and a moderate body length in the AI art).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function dchData(string $path): mixed
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

/** @return array<string, mixed> the Dachshund seeder row of (stage, from, key) */
function dchRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'dachshund'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("dachshund.{$stage}.{$from}.{$key->value}");

    return $row;
}

function dchPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->dachshund()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function dchPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function dchLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Dachshund;
        $config = BreedConfig::where('breed_slug', 'dachshund')->sole();

        expect($breed->value)->toBe('dachshund')
            ->and($breed->slug())->toBe('dachshund')
            ->and(BreedType::fromSlug('dachshund'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 90, 'breeds.dachshund'])
            ->and($config->search_keywords)->toBe(['dachshund', 'sausage dog', 'teckel', 'jazbečar', 'jazbecar'])
            // Legacy fallback = the adult step goal David chose (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) dchData('proposed_game_parameters.dachshund.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = dchData('proposed_game_parameters.dachshund.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Dachshund pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = dchPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('dachshund');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_09_120000_add_dachshund_breed.php');
        // down() refuses while a Dachshund exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'dachshund']))->toThrow(QueryException::class);
        // The Standard Poodle (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'standard_poodle']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dachshund']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Dachshund);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'dachshund')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->count());

        BreedConfig::where('breed_slug', 'dachshund')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'dachshund')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'dachshund')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'dachshund');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.dachshund';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(dchRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(dchData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(dchRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(dchData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(dchRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(dchData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S125) = 108.
            ->and(dchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(dchData('dachshund.lifespan.senior_from.months'))
            ->and(dchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * dchRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(dchData('dachshund.lifespan.senior_from.derived_from'))->toBe(['S11', 'S125'])
            ->and(dchRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(dchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(dchData("{$pgp}.exercise_minutes_adult.value"))
            ->and(dchRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(dchRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(dchRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(dchData("{$pgp}.exercise_minutes_senior.value"))
            ->and(dchRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(dchRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(dchData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(dchRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(dchData("{$pgp}.learning_multiplier.value"))
            ->and(dchRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Dachshund decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('dachshund')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.dachshund.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) dchData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Dachshund research.
        expect(json_encode([dchData('dachshund'), dchData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced dachshund entries', function () {
        $rkc = dchData('dachshund.adult_weight.rkc.value');

        // RKC standard "Ideal weight: 9-12 kgs" (S126), one range for both sexes.
        expect(dchRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $rkc[0], (float) $rkc[1]])
            ->and(dchRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.0, 12.0])
            ->and(dchRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(dchData('dachshund.adult_weight.rkc.source_id'))
            ->and(dchRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(dchData('dachshund.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(dchRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(dchData('dachshund.trainability.coren_rank.value'))
            ->and(dchRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(49)
            ->and(dchData('dachshund.lifespan.rkc.value'))->toBe('> 12')
            ->and(dchRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) ltrim((string) dchData('dachshund.lifespan.rkc.value'), '> '))
            ->and(dchRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('dachshund.lifespan.rkc')
            ->and(dchRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S125')
            ->and(dchRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            // The Miniature Dachshund's 14.0 y (S130) is never a runtime number.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->pluck('value')->flatten()->all())->not->toContain(14.0);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->where('verified', false)->pluck('key')->all())
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

        expect($general('dachshund'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = dchPet($arrival);
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

    it('becomes senior at 108 months like the Standard Poodle, after the Beagle and before the Labrador', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('dachshund', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('dachshund', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('poodle-standard', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('beagle', 102))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cavalier-king-charles-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('labrador-retriever', 108))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (1.0, Coren Average tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dachshund = dchPet(36);
        $date = $dachshund->localDate(now());

        expect($training->learningFactor($dachshund))->toBe(1.0)
            ->and($training->gainPerSuccess($dachshund, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'dachshund', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = dchPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(dchLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Dachshund)
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
        foreach (['border_collie', 'dachshund'] as $breed) {
            $pin = dchPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(dchLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['dachshund']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['dachshund']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['dachshund']->arrival_age_months)->toBe(108)
            ->and($pets['dachshund']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Dachshund appearance data.
            ->and($pets['dachshund']->pet_dna['breed'])->toBe('dachshund')
            ->and((int) $pets['dachshund']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = dchPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Dachshund after the Standard Poodle with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky'])
            ->and($breeds[9])->toBe([
                'breed' => 'dachshund', 'slug' => 'dachshund', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.dachshund',
                'search_keywords' => BreedConfigsSeeder::configs()[9]['search_keywords'], 'sort_order' => 90,
                'suitability' => [
                    'suits' => ['children'],
                    'consider' => ['back_spine', 'sheds', 'needs_mental_stimulation'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: standard colours, never dapple, moderate body, red portrait)', function () {
    it('samples Dachshund DNA inside the standard (red / black and tan / chocolate and tan, smooth coat, moderate length)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('dachshund')
            ->and(PetDnaService::hasAppearance('dachshund'))->toBeTrue()
            ->and(config('breed_appearance.dachshund.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('dachshund', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_pattern'])->toBe('no white markings')
                ->and($traits['body'])->toContain('enough ground clearance');

            $prompt = $prompts->imagePrompt('dachshund', $traits);
            expect($prompt)->toContain('Dachshund')
                ->toContain('smooth')
                ->toContain('ground clearance')
                ->not->toContain('dapple')
                ->not->toContain('merle')
                ->not->toContain('piebald')
                ->not->toContain('tricolour')
                ->not->toContain('extremely long')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['red', 'black and tan', 'chocolate and tan']);
    });

    it('draws the register portrait red', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Dachshund);

        expect($traits['coat_color'])->toBe('red')
            ->and($traits['coat_pattern'])->toBe('no white markings')
            ->and($portrait->prompt(BreedType::Dachshund))->toContain('Dachshund')->toContain('red')->toContain('ground clearance')->not->toContain('dapple')
            ->and(BreedPortraitService::relativeFile(BreedType::Dachshund))->toBe('dog/dachshund.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Dachshund);
        expect($v1['prompt_anchor'])->toContain('Dachshund')->toContain('red')->toContain('ground clearance')->not->toContain('dapple')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red', 'black and tan', 'chocolate and tan']);

        $pet = dchPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Dachshund')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, still in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the back_spine chip and is not a marketing-excluded breed', function () {
        $app = json_encode([
            config('breed_appearance.dachshund'),
            config('breed_suitability.breeds.dachshund'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dachshund')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', '10-12', 'times higher', '5-7 years', 'x10'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(app(BreedSuitability::class)->for(BreedType::Dachshund)['consider'])->toContain('back_spine')
            ->and(dchData('dachshund.health._note'))->toContain('never shown in the app')
            ->and(dchData('proposed_game_parameters.dachshund.marketing.decision'))->toContain('may appear in marketing');
    });
});
