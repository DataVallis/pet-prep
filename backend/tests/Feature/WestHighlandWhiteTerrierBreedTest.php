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
| M5-R10-11 — West Highland White Terrier (eleventh breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `west_highland_white_terrier`
| (S142–S149) and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.west_highland_white_terrier` (exercise 60 min, senior
| 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 121, arrival
| 2 / 9 / 36 / 121, learning × 1.0 (Coren 47, Average), paid breed, Border Collie care
| rates, suitability apartment / family_pet / children + sensitive_skin (new, welfare
| rule: RKC Breed Watch dermatitis) / sheds / frequent_grooming / chews_when_bored;
| white is the only standard colour → a white portrait).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function whwData(string $path): mixed
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

/** @return array<string, mixed> the West Highland White Terrier seeder row of (stage, from, key) */
function whwRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'west-highland-white-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("west-highland-white-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function whwPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->westHighlandWhiteTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function whwPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function whwLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::WestHighlandWhiteTerrier;
        $config = BreedConfig::where('breed_slug', 'west-highland-white-terrier')->sole();

        expect($breed->value)->toBe('west_highland_white_terrier')
            ->and($breed->slug())->toBe('west-highland-white-terrier')
            ->and(BreedType::fromSlug('west-highland-white-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 120, 'breeds.west_highland_white_terrier'])
            ->and($config->search_keywords)->toBe(['west highland white terrier', 'westie', 'westy', 'zahodnoškotski beli terier', 'zahodnoskotski beli terier', 'west highland terier', 'vestie'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) whwData('proposed_game_parameters.west_highland_white_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = whwData('proposed_game_parameters.west_highland_white_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a West Highland White Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = whwPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('west_highland_white_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'westie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_12_120000_add_west_highland_white_terrier_breed.php');
        // down() refuses while a West Highland White Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'west_highland_white_terrier']))->toThrow(QueryException::class);
        // The Havanese (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'havanese']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'west_highland_white_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::WestHighlandWhiteTerrier);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'west-highland-white-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'west-highland-white-terrier')->count());

        BreedConfig::where('breed_slug', 'west-highland-white-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'west-highland-white-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'west-highland-white-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $rows = collect(BreedStageParamsSeeder::rows());
        $others = $rows->where('breed_slug', '!=', 'west-highland-white-terrier');
        $mine = $rows->where('breed_slug', 'west-highland-white-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER)->all())->toBe([]);
        foreach ([BreedStageParamsSeeder::CONFIRMED_R10, BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER, BreedStageParamsSeeder::CONFIRMED_R10_BEAGLE,
            BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_POODLE, BreedStageParamsSeeder::CONFIRMED_R10_DACHSHUND,
            BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SHEPHERD, BreedStageParamsSeeder::CONFIRMED_R10_HAVANESE] as $other) {
            expect($mine->where('decision', $other)->all())->toBe([]);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.west_highland_white_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(whwRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(whwData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(whwRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(whwData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(whwRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(whwData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.4 y (VetCompass median, S148) = 120.6 → 121.
            ->and(whwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(whwData('west_highland_white_terrier.lifespan.senior_from.months'))
            ->and(whwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * whwRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(whwData('west_highland_white_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S148'])
            ->and(whwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(121);

        expect(whwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(whwData("{$pgp}.exercise_minutes_adult.value"))
            ->and(whwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(whwRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(whwRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(whwData("{$pgp}.exercise_minutes_senior.value"))
            ->and(whwRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe((int) round(0.75 * 60, 0, PHP_ROUND_HALF_UP))
            ->and(whwRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(whwRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(whwData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(whwRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(whwData("{$pgp}.learning_multiplier.value"))
            ->and(whwRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WEST_HIGHLAND_WHITE_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('west-highland-white-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.west_highland_white_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) whwData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the research.
        expect(json_encode([whwData('west_highland_white_terrier'), whwData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced west_highland_white_terrier entries', function () {
        $pdsa = whwData('west_highland_white_terrier.adult_weight.pdsa.value');

        // PDSA "6-9 kg" (S147), one range for both sexes (the FCI / RKC standards give height only).
        expect(whwRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) $pdsa[0], (float) $pdsa[1]])
            ->and(whwRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6.0, 9.0])
            ->and(whwRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(whwData('west_highland_white_terrier.adult_weight.pdsa.source_id'))
            ->and(whwRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(whwData('west_highland_white_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 47 (S35, "Average").
            ->and(whwRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(47)
            ->and(whwData('west_highland_white_terrier.trainability.coren_rank.value'))->toBe(47)
            ->and(whwData('west_highland_white_terrier.trainability.coren_tier.value'))->toBe('Average')
            ->and(whwRow('all', 0, StageParamKey::CorenRank)['source_id'])->toBe('S35')
            // VetCompass median 13.4 y (S148).
            ->and(whwRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe((float) whwData('west_highland_white_terrier.lifespan.median_uk.value'))
            ->and(whwRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('west_highland_white_terrier.lifespan.median_uk')
            ->and(whwRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S148')
            ->and(whwRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.4)
            // No McMillan 2024 value was reachable — nothing unsourced at runtime.
            ->and(whwData('west_highland_white_terrier.lifespan.mcmillan_2024.value'))->toBeNull();

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'west-highland-white-terrier')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'west-highland-white-terrier')->where('verified', false)->pluck('key')->all())
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

        expect($general('west-highland-white-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = whwPet($arrival);
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
        'adult 120 months' => [120, LifeStage::Adult, 60, 6000],
        'senior 121 months' => [121, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 121 months — later than the Havanese (108)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('west-highland-white-terrier', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('west-highland-white-terrier', 121))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (1.0, Coren Average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $westie = whwPet(36);
        $date = $westie->localDate(now());

        expect($training->learningFactor($westie))->toBe(1.0)
            ->and($training->gainPerSuccess($westie, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'west_highland_white_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = whwPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(whwLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::WestHighlandWhiteTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 121 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'west_highland_white_terrier'] as $breed) {
            $pin = whwPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(whwLogin($pin)->assertOk()->json('pet.id'));
        }
        $westie = $pets['west_highland_white_terrier'];

        expect($westie->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($westie->plan)->toBe(PetPlan::Challenge)
            ->and($westie->arrival_age_months)->toBe(121)
            ->and($westie->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($westie->pet_dna['breed'])->toBe('west_highland_white_terrier')
            ->and((int) $westie->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = whwPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the West Highland White Terrier after the Havanese with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog'])
            ->and($breeds[12])->toBe([
                'breed' => 'west_highland_white_terrier', 'slug' => 'west-highland-white-terrier', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.west_highland_white_terrier',
                'search_keywords' => BreedConfigsSeeder::configs()[12]['search_keywords'], 'sort_order' => 120,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'children'],
                    'consider' => ['sensitive_skin', 'sheds', 'frequent_grooming', 'chews_when_bored'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: white only, healthy skin)', function () {
    it('samples DNA inside the standard (white, harsh coat, erect ears, healthy skin)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('west_highland_white_terrier')
            ->and(PetDnaService::hasAppearance('west_highland_white_terrier'))->toBeTrue()
            ->and(config('breed_appearance.west_highland_white_terrier.verified'))->toBeFalse();

        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('west_highland_white_terrier', $seed)['traits'];
            expect($traits['coat_color'])->toBe('pure white')
                ->and($traits['coat_condition'])->toContain('healthy skin')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['coat_length'])->toContain('harsh');

            $prompt = $prompts->imagePrompt('west_highland_white_terrier', $traits);
            expect($prompt)->toContain('West Highland White Terrier')
                ->toContain('pure white')
                ->toContain('healthy skin')
                ->not->toContain('wheaten')
                ->not->toContain('brindle')
                ->not->toContain('docked')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
    });

    it('draws the register portrait white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::WestHighlandWhiteTerrier);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::WestHighlandWhiteTerrier))->toContain('West Highland White Terrier')->toContain('pure white')->toContain('healthy skin')
            ->and(BreedPortraitService::relativeFile(BreedType::WestHighlandWhiteTerrier))->toBe('dog/west-highland-white-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::WestHighlandWhiteTerrier);
        expect($v1['prompt_anchor'])->toContain('West Highland White Terrier')->toContain('pure white')->toContain('healthy skin')
            ->and($v1['visual_traits']['color_scheme'])->toBe('pure white');

        $pet = whwPet(121, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('West Highland White Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip sensitive_skin, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the sensitive_skin chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.west_highland_white_terrier'),
            config('breed_suitability.breeds.west_highland_white_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'west-highland-white-terrier')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '95% CI', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::WestHighlandWhiteTerrier)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toContain('sensitive_skin')
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'hips_hind_legs', 'heart_and_spine', 'back_spine']))->toBe([])
            ->and(whwData('west_highland_white_terrier.health._note'))->toContain('Welfare-concern breed')
            ->and(whwData('proposed_game_parameters.west_highland_white_terrier.marketing.decision'))->toContain('may appear in marketing');
    });
});
