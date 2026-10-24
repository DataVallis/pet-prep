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
| M5-R10-21 — German Shorthaired Pointer (appended after the Bulldog: AKC 2024 No. 10)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `german_shorthaired_pointer`
| (S184–S189, lifespan S123) and the runbook's standing rules (confirmed by David
| 2026-10-10) in `proposed_game_parameters.german_shorthaired_pointer` (exercise
| 120 min, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 121 —
| senior from the McMillan 2024 median 13.4 y —, arrival 2 / 9 / 36 / 121,
| learning × 1.5, paid breed, Border Collie care rates, suitability active_family /
| large_home + long_daily_exercise / sheds; standard colours only (liver roan
| portrait), natural tail; no welfare chip).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gspData(string $path): mixed
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

/** @return array<string, mixed> the German Shorthaired Pointer seeder row of (stage, from, key) */
function gspRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'german-shorthaired-pointer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("german-shorthaired-pointer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gspPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->germanShorthairedPointer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gspPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gspLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GermanShorthairedPointer;
        $config = BreedConfig::where('breed_slug', 'german-shorthaired-pointer')->sole();

        expect($breed->value)->toBe('german_shorthaired_pointer')
            ->and($breed->slug())->toBe('german-shorthaired-pointer')
            ->and(BreedType::fromSlug('german-shorthaired-pointer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 170, 'breeds.german_shorthaired_pointer'])
            ->and($config->search_keywords)->toBe(['german shorthaired pointer', 'german short-haired pointer', 'german pointer', 'gsp', 'shorthair', 'deutsch kurzhaar', 'kurzhaar', 'nemški kratkodlaki ptičar', 'nemski kratkodlaki pticar', 'kratkodlaki ptičar', 'kratkodlaki pticar', 'ptičar', 'pticar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gspData('proposed_game_parameters.german_shorthaired_pointer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gspData('proposed_game_parameters.german_shorthaired_pointer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a German Shorthaired Pointer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gspPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('german_shorthaired_pointer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'gsp']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_23_120000_add_german_shorthaired_pointer_breed.php');
        // down() refuses while a German Shorthaired Pointer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'german_shorthaired_pointer']))->toThrow(QueryException::class);
        // The Bulldog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bulldog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_shorthaired_pointer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GermanShorthairedPointer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'german-shorthaired-pointer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shorthaired-pointer')->count());

        BreedConfig::where('breed_slug', 'german-shorthaired-pointer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'german-shorthaired-pointer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'german-shorthaired-pointer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'german-shorthaired-pointer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shorthaired-pointer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'german-shorthaired-pointer'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the pointer's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.german_shorthaired_pointer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gspRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gspData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gspRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gspData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gspRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gspData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.4 y (McMillan 2024 via the Dogs Trust table, S123) = 120.6 → 121.
            ->and(gspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gspData('german_shorthaired_pointer.lifespan.senior_from.months'))
            ->and(gspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gspRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gspData('german_shorthaired_pointer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(gspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(121);

        expect(gspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gspData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gspRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(gspRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gspData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gspRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gspRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gspData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gspRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gspData("{$pgp}.learning_multiplier.value"))
            ->and(gspRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every German Shorthaired Pointer decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_SHORTHAIRED_POINTER) {
                $decided++;
                expect($row['breed_slug'])->toBe('german-shorthaired-pointer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.german_shorthaired_pointer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gspData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // need without a mechanic (weekly brushing) points at its ROADMAP task, recall is already trained.
        expect(json_encode([gspData('german_shorthaired_pointer'), gspData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            // M5-R13: the range rule applied consistently — PDSA "once or twice a week" → 2 (RKC 1 = alternative).
            ->and(gspData("{$pgp}.grooming.value"))->toBe(2)
            ->and(gspData("{$pgp}.grooming.alternatives.rkc_once_a_week"))->toBe(1)
            ->and(gspData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(gspData("{$pgp}.recall_training.decision"))->toContain('Pridi');
    });

    it('takes the breed-level numbers from the sourced german_shorthaired_pointer entries', function () {
        $akc = gspData('german_shorthaired_pointer.adult_weight.akc.value');

        // AKC standard (S186): dogs 55–70 lb, bitches 45–60 lb → 20.4–31.8 kg.
        expect(gspRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) min($akc['female'][0], $akc['male'][0]), (float) max($akc['female'][1], $akc['male'][1])])
            ->and(gspRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.4, 31.8])
            ->and(gspRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gspData('german_shorthaired_pointer.adult_weight.akc.source_id'))
            ->and(gspRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(gspData('german_shorthaired_pointer.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(gspRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(gspData('german_shorthaired_pointer.trainability.coren_rank.value'))
            ->and(gspRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(17)
            ->and(gspRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(gspData('german_shorthaired_pointer.lifespan.median_uk.value'))
            ->and(gspRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('german_shorthaired_pointer.lifespan.median_uk')
            ->and(gspRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(gspRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.4);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shorthaired-pointer')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shorthaired-pointer')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('german-shorthaired-pointer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gspPet($arrival);
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
        'adult 120 months' => [120, LifeStage::Adult, 120, 12000],
        'senior 121 months' => [121, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 121 months, later than the Rottweiler', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('german-shorthaired-pointer', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('german-shorthaired-pointer', 121))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rottweiler', 81))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-shorthaired-pointer', 81))->toBe(LifeStage::Adult);
    });

    it('learns at 1.5 × the mixed-breed speed (Coren Excellent tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $pointer = gspPet(36);
        $date = $pointer->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($pointer))->toBe(1.0)
            ->and($training->gainPerSuccess($pointer, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'german_shorthaired_pointer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gspPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gspLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GermanShorthairedPointer)
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
        foreach (['border_collie', 'german_shorthaired_pointer'] as $breed) {
            $pin = gspPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gspLogin($pin)->assertOk()->json('pet.id'));
        }

        $pointer = $pets['german_shorthaired_pointer'];
        expect($pointer->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pointer->plan)->toBe(PetPlan::Challenge)
            ->and($pointer->arrival_age_months)->toBe(121)
            ->and($pointer->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the German Shorthaired Pointer appearance data.
            ->and($pointer->pet_dna['breed'])->toBe('german_shorthaired_pointer')
            ->and((int) $pointer->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gspPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the German Shorthaired Pointer after the Bulldog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'german_shorthaired_pointer', 'bulldog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'german_shorthaired_pointer', 'slug' => 'german-shorthaired-pointer', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.german_shorthaired_pointer',
            'search_keywords' => seededBreedConfig('german_shorthaired_pointer')['search_keywords'], 'sort_order' => 170,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural tail = Claude\'s proposal)', function () {
    it('samples German Shorthaired Pointer DNA inside the standard (liver / black, roan or solid, dark brown eyes)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('german_shorthaired_pointer')
            ->and(PetDnaService::hasAppearance('german_shorthaired_pointer'))->toBeTrue()
            ->and(config('breed_appearance.german_shorthaired_pointer.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('german_shorthaired_pointer', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['eye_color'])->toBe('dark brown, almond-shaped');

            $prompt = $prompts->imagePrompt('german_shorthaired_pointer', $traits);
            expect($prompt)->toContain('German Shorthaired Pointer')
                ->not->toMatch('/\b(tan|red|orange|lemon)\b/')
                ->not->toContain('solid white')
                ->not->toMatch('/\bdocked tail/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(count($colours))->toBe(5);
        foreach (array_keys($colours) as $colour) {
            expect($colour)->toMatch('/^(liver|solid liver|black roan|solid black)/');
        }
    });

    it('draws the register portrait liver roan with a solid liver head', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GermanShorthairedPointer);

        expect($traits['coat_color'])->toStartWith('liver roan')
            ->and($portrait->prompt(BreedType::GermanShorthairedPointer))->toContain('German Shorthaired Pointer')->toContain('liver roan')->toContain('undocked')
            ->and(BreedPortraitService::relativeFile(BreedType::GermanShorthairedPointer))->toBe('dog/german-shorthaired-pointer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GermanShorthairedPointer);
        expect($v1['prompt_anchor'])->toContain('German Shorthaired Pointer')->toContain('liver roan')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['liver roan', 'solid liver', 'black roan'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = gspPet(121, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('German Shorthaired Pointer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.german_shorthaired_pointer'),
            config('breed_suitability.breeds.german_shorthaired_pointer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-shorthaired-pointer')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.german_shorthaired_pointer.traits')))->not->toMatch('/\bdocked tail/')->not->toMatch('/\b(tan|red|lemon)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::GermanShorthairedPointer)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('shorter_lifespan')
            ->and(gspData('proposed_game_parameters.german_shorthaired_pointer.marketing.decision'))->toContain('in marketing yes');
    });
});
