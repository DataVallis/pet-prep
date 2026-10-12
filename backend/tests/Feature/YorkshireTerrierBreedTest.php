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
| M5-R10-22 — Yorkshire Terrier (appended after the German Shorthaired Pointer: AKC 2024 No. 11)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `yorkshire_terrier` (S190–S196)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.yorkshire_terrier` (exercise 30 min, senior 23 min,
| puppy 10 min × age capped at 30, stages 9 / 36 / 122 — senior from the VetCompass
| median 13.56 y, provisional —, arrival 2 / 9 / 36 / 122, learning × 1.2, paid
| breed, Border Collie care rates, suitability apartment / low_shedding +
| fragile_older_children (new key) / frequent_grooming; dark steel blue and tan
| only, natural tail; no welfare chip).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ytData(string $path): mixed
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

/** @return array<string, mixed> the Yorkshire Terrier seeder row of (stage, from, key) */
function ytRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'yorkshire-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("yorkshire-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ytPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->yorkshireTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ytPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ytLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::YorkshireTerrier;
        $config = BreedConfig::where('breed_slug', 'yorkshire-terrier')->sole();

        expect($breed->value)->toBe('yorkshire_terrier')
            ->and($breed->slug())->toBe('yorkshire-terrier')
            ->and(BreedType::fromSlug('yorkshire-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 180, 'breeds.yorkshire_terrier'])
            ->and($config->search_keywords)->toBe(['yorkshire terrier', 'yorkshire', 'yorkie', 'yorki', 'yorkshirski terier', 'jorkširski terier', 'jorksirski terier', 'jorki'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ytData('proposed_game_parameters.yorkshire_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ytData('proposed_game_parameters.yorkshire_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Yorkshire Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ytPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('yorkshire_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'yorkie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_24_120000_add_yorkshire_terrier_breed.php');
        // down() refuses while a Yorkshire Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'yorkshire_terrier']))->toThrow(QueryException::class);
        // The German Shorthaired Pointer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_shorthaired_pointer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'yorkshire_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::YorkshireTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'yorkshire-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'yorkshire-terrier')->count());

        BreedConfig::where('breed_slug', 'yorkshire-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'yorkshire-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'yorkshire-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'yorkshire-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'yorkshire-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_YORKSHIRE_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Yorkshire Terrier's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_YORKSHIRE_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.yorkshire_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ytRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ytData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ytRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ytData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ytRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ytData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.56 y (VetCompass median, O'Neill et al. 2025, S195) = 122.04 → 122 (provisional).
            ->and(ytRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ytData('yorkshire_terrier.lifespan.senior_from.months'))
            ->and(ytRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ytRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ytData('yorkshire_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S195'])
            ->and(ytRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(122);

        expect(ytRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ytData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ytRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(ytRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(ytRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ytData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ytRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ytRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ytData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(ytRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ytData("{$pgp}.learning_multiplier.value"))
            ->and(ytRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every Yorkshire Terrier decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_YORKSHIRE_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('yorkshire-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.yorkshire_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ytData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs without a mechanic (daily brushing, daily tooth brushing) point at their ROADMAP tasks.
        expect(json_encode([ytData('yorkshire_terrier'), ytData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ytData("{$pgp}.grooming.value"))->toBe(7)
            ->and(ytData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(ytData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(ytData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15');
    });

    it('takes the breed-level numbers from the sourced yorkshire_terrier entries', function () {
        // PDSA average 3.2 kg (S194) = the FCI / RKC upper limit "Up to 3,2 kgs" (S190, S193).
        expect(ytRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual(ytData('yorkshire_terrier.adult_weight.pdsa.value'))
            ->and(ytRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.2, 3.2])
            ->and(ytData('yorkshire_terrier.adult_weight.fci.value'))->toEqual([3.2, 3.2])
            ->and(ytRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ytData('yorkshire_terrier.adult_weight.pdsa.source_id'))
            // The heavier UK pet population (VetCompass 5.06 kg) is background only, never the game weight.
            ->and(ytData('yorkshire_terrier.adult_weight.uk_pet_population_median.value'))->toBe(5.06)
            ->and(ytRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(ytData('yorkshire_terrier.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            ->and(ytRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(ytData('yorkshire_terrier.trainability.coren_rank.value'))
            ->and(ytRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(27)
            ->and(ytRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(ytData('yorkshire_terrier.lifespan.median_uk.value'))
            ->and(ytRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('yorkshire_terrier.lifespan.median_uk')
            ->and(ytRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S195')
            ->and(ytRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.56);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'yorkshire-terrier')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'yorkshire-terrier')->where('verified', false)->pluck('key')->all())
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

        expect($general('yorkshire-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ytPet($arrival);
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
        'adult 121 months' => [121, LifeStage::Adult, 30, 3000],
        'senior 122 months' => [122, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 122 months, one month after the West Highland White Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('yorkshire-terrier', 121))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('yorkshire-terrier', 122))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('west-highland-white-terrier', 121))->toBe(LifeStage::Senior);
    });

    it('learns at 1.2 × the mixed-breed speed (Coren Above average tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $yorkie = ytPet(36);
        $date = $yorkie->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($yorkie))->toBe(1.0)
            ->and($training->gainPerSuccess($yorkie, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'yorkshire_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ytPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ytLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::YorkshireTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 122 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'yorkshire_terrier'] as $breed) {
            $pin = ytPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ytLogin($pin)->assertOk()->json('pet.id'));
        }

        $yorkie = $pets['yorkshire_terrier'];
        expect($yorkie->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($yorkie->plan)->toBe(PetPlan::Challenge)
            ->and($yorkie->arrival_age_months)->toBe(122)
            ->and($yorkie->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Yorkshire Terrier appearance data.
            ->and($yorkie->pet_dna['breed'])->toBe('yorkshire_terrier')
            ->and((int) $yorkie->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ytPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Yorkshire Terrier after the German Shorthaired Pointer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann'])
            ->and($breeds[18])->toBe([
                'breed' => 'yorkshire_terrier', 'slug' => 'yorkshire-terrier', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.yorkshire_terrier',
                'search_keywords' => BreedConfigsSeeder::configs()[18]['search_keywords'], 'sort_order' => 180,
                'suitability' => [
                    'suits' => ['apartment', 'low_shedding'],
                    'consider' => ['fragile_older_children', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colour only; natural tail — an FCI option)', function () {
    it('samples Yorkshire Terrier DNA inside the standard (dark steel blue and tan only, undocked tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('yorkshire_terrier')
            ->and(PetDnaService::hasAppearance('yorkshire_terrier'))->toBeTrue()
            ->and(config('breed_appearance.yorkshire_terrier.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('yorkshire_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('erect');

            $prompt = $prompts->imagePrompt('yorkshire_terrier', $traits);
            expect($prompt)->toContain('Yorkshire Terrier')
                ->not->toMatch('/\b(fawn|bronze|merle|parti|chocolate|gold|silver)\b/i')
                ->not->toMatch('/\bdocked tail/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toBe(['dark steel blue body with rich bright tan head, chest and legs']);
    });

    it('draws the register portrait dark steel blue and tan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::YorkshireTerrier);

        expect($traits['coat_color'])->toStartWith('dark steel blue')
            ->and($portrait->prompt(BreedType::YorkshireTerrier))->toContain('Yorkshire Terrier')->toContain('steel blue')->toContain('undocked')
            ->and(BreedPortraitService::relativeFile(BreedType::YorkshireTerrier))->toBe('dog/yorkshire-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::YorkshireTerrier);
        expect($v1['prompt_anchor'])->toContain('Yorkshire Terrier')->toContain('dark steel blue and rich tan')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBe('dark steel blue and rich tan')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = ytPet(122, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Yorkshire Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.yorkshire_terrier'),
            config('breed_suitability.breeds.yorkshire_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'yorkshire-terrier')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.yorkshire_terrier.traits')))->not->toMatch('/\bdocked tail/')->not->toMatch('/\b(fawn|bronze|merle)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::YorkshireTerrier)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('shorter_lifespan')
            ->and(ytData('proposed_game_parameters.yorkshire_terrier.marketing.decision'))->toContain('in marketing yes');
    });
});
