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
| M5-R10-122 — Greyhound (appended after the Tibetan Terrier: AKC 2024 No. 118)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `greyhound` (S912–S922) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.greyhound` (exercise 60 min — PDSA "about an hour of
| exercise daily", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36 / 103 (0.75 × VetCompass median 11.4 y), arrival 2 / 9 / 36 / 103, learning × 1.0 (Coren 46, Average),
| paid breed, Border Collie care rates, brushing once a week (PDSA, M5-R13), puppy socialisation with the PDSA quote
| (S914, M5-R17), tooth brushing every day (WebMD, M5-R15); no nail / ear / bath / groomer task (no frequency).
| No welfare chip, in marketing; suits family_pet; consider chases_small_pets; brindle portrait (one of six FCI
| colours), natural tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function grhData(string $path): mixed
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

/** @return array<string, mixed> the Greyhound seeder row of (stage, from, key) */
function grhRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'greyhound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("greyhound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function grhPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->greyhound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function grhPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function grhLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Greyhound;
        $config = BreedConfig::where('breed_slug', 'greyhound')->sole();

        expect($breed->value)->toBe('greyhound')
            ->and($breed->slug())->toBe('greyhound')
            ->and(BreedType::fromSlug('greyhound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1130, 'breeds.greyhound'])
            ->and($config->search_keywords)->toBe(['greyhound', 'angleški hrt', 'angleski hrt', 'english greyhound', 'hrt', 'grejhaund', 'sighthound'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) grhData('proposed_game_parameters.greyhound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = grhData('proposed_game_parameters.greyhound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Greyhound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = grhPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('greyhound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'english_greyhound']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'sighthound']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_15_120000_add_greyhound_breed.php');
        // down() refuses while a Greyhound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'greyhound']))->toThrow(QueryException::class);
        // The Tibetan Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'tibetan_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'greyhound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Greyhound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'greyhound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greyhound')->count());

        BreedConfig::where('breed_slug', 'greyhound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'greyhound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'greyhound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'greyhound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greyhound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'greyhound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GREYHOUND)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_GREYHOUND');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.greyhound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(grhRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(grhData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(grhRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(grhData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(grhRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(grhData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.4 y (VetCompass median, S920 — McMillan 2024 not reachable) → confirmed rule, verified.
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(grhData('greyhound.lifespan.senior_from.months'))
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * grhRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(grhData('greyhound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S920'])
            // The Dogs Trust rule of thumb is background only — never the seeded boundary.
            ->and(grhData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', grhData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_GREYHOUND)
            ->and(grhRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(grhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(103);

        expect(grhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(grhData("{$pgp}.exercise_minutes_adult.value"))
            ->and(grhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(grhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(grhData('greyhound.exercise.adult.quote'))
            ->and(grhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(grhData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(grhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S914')
            ->and(grhRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(grhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(grhData("{$pgp}.exercise_minutes_senior.value"))
            ->and(grhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(grhRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(grhData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(grhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(grhData("{$pgp}.learning_multiplier.value"))
            ->and(grhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GREYHOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('greyhound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.greyhound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) grhData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing once a week (M5-R13, PDSA), puppy socialisation with the PDSA
        // quote (M5-R17) and tooth brushing every day (WebMD, M5-R15). No nail / ear / bath / groomer task (no frequency), no
        // meal gap (no quote), no heat warning. Runs and weather care have no mechanic → open for David (breed doc).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['greyhound'];
        expect(json_encode([grhData('greyhound'), grhData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(grhData("{$pgp}.grooming.value"))->toBe(1)
            ->and(grhData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('greyhound')
            ->and(grhData('greyhound.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['greyhound'])->toBe(['S914', grhData("{$pgp}.socialisation.quote")])
            ->and(grhData("{$pgp}.socialisation.quote"))->toBe(grhData('greyhound.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S917', grhData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(grhData("{$pgp}.tooth_brushing.quote"))->toBe(grhData('greyhound.suitability.webmd_teeth.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('greyhound');
    });

    it('takes the breed-level numbers from the sourced greyhound entries', function () {
        // AKC standard (S918): dogs 65–70 lb, bitches 60–65 lb → 27.2–31.8 kg overall (FCI gives no weight).
        expect(grhRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([27.2, 31.8])
            ->and(grhData('greyhound.adult_weight.akc.value'))->toEqual(['male' => [29.5, 31.8], 'female' => [27.2, 29.5]])
            ->and(grhRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(grhData('greyhound.adult_weight.akc.source_id'))
            ->and(grhRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(grhData('greyhound.adult_weight.akc.quote'))
            ->and(grhRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(grhData('greyhound.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 46, Average → 1.0.
            ->and(grhRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(46)
            ->and(grhData('greyhound.trainability.coren_rank.value'))->toBe(46)
            ->and(grhRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('greyhound.lifespan.median_uk')
            ->and(grhRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S920')
            ->and(grhRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.4)
            ->and(grhRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(grhData('greyhound.lifespan.median_uk.quote'));

        // Brushing once a week: PDSA "requires weekly grooming" → 1.
        $grooming = grhRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S914')
            ->and($grooming['quote'])->toBe(grhData('greyhound.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(grhData('proposed_game_parameters.greyhound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(grhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(grhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S914')
            ->and(grhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greyhound');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->values()->all())
            ->toBe([StageParamKey::ToothBrushingFrequency->value])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: socialisation (PDSA S914 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15: tooth brushing (WebMD S917).
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('greyhound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = grhPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 102 months' => [102, LifeStage::Adult, 60, 6000],
        'senior 103 months' => [103, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 103 months, earlier than the Tibetan Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('greyhound', 102))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('greyhound', 103))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('tibetan-terrier', 103))->toBe(LifeStage::Adult);
    });

    it('learns like the mixed breed (Coren 46, Average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = grhPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'greyhound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = grhPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(grhLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Greyhound)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 103 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'greyhound'] as $breed) {
            $pin = grhPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(grhLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['greyhound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(103)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('greyhound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = grhPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Greyhound after the Tibetan Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'greyhound', 'tibetan_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'greyhound', 'slug' => 'greyhound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.greyhound',
            'search_keywords' => seededBreedConfig('greyhound')['search_keywords'], 'sort_order' => 1130,
            'suitability' => [
                'suits' => ['family_pet'],
                'consider' => ['chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 158 S912 / S913)', function () {
    it('samples DNA only in six of the named FCI colours — never merle, never cropped ears or a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('greyhound')
            ->and(PetDnaService::hasAppearance('greyhound'))->toBeTrue()
            ->and(config('breed_appearance.greyhound.verified'))->toBeFalse()
            ->and(config('breed_appearance.greyhound.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.greyhound.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('greyhound', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('greyhound', $traits);
            expect($prompt)->toContain('Greyhound')->toContain('rose-shaped')
                ->not->toMatch('/\b(merle|cropped|docked|muzzled|racing)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.greyhound');
        expect($standard)->toBe(['brindle', 'black', 'fawn', 'blue (grey)', 'red', 'white with black patches'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['brindle', 'black', 'fawn', 'blue', 'red', 'black-white'])
            ->and(grhData('greyhound.appearance.colours.value'))->toBe($standard)
            ->and(grhData('greyhound.appearance.colours.quote'))->toBe('Black, white, red, blue, fawn, fallow, brindle or any of these colours broken with white.');
    });

    it('draws the register portrait brindle (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Greyhound);

        expect($traits['coat_color'])->toBe('brindle')
            ->and($portrait->prompt(BreedType::Greyhound))->toContain('Greyhound')->toContain('brindle')
            ->and(BreedPortraitService::relativeFile(BreedType::Greyhound))->toBe('dog/greyhound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Greyhound);
        expect($v1['prompt_anchor'])->toContain('Greyhound')->toContain('brindle')
            ->not->toMatch('/\b(merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('brindle');

        $pet = grhPet(103, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Greyhound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.greyhound'),
            config('breed_suitability.breeds.greyhound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greyhound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Greyhound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch category 1 (S922), no veterinary association names the breed → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(grhData('proposed_game_parameters.greyhound.marketing.value'))->toContain('in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'greyhound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits family life; consider the strong chase instinct with small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Greyhound);
        expect($tags['suits'])->toBe(['family_pet'])
            ->and($tags['consider'])->toBe(['chases_small_pets'])
            // PetMD ("patient with children") and WebMD ("only moderately good with young children") differ → no children chip.
            // No RKC key facts → never apartment; WebMD vs Dogs Trust on shedding → neither shedding chip; WebMD names extreme
            // temperatures, not the cold → no cold_sensitive.
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->not->toContain('active_family')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('needs_company')->not->toContain('cold_sensitive')
            ->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming');

        expect(grhData('greyhound.behaviour.family.quote'))->toBe('With the right socialisation as puppies, they can make good family pets')
            ->and(grhData('greyhound.behaviour.other_pets.quote'))->toBe("Greyhounds have a very strong prey drive, so they aren't ideal for homes with cats or other small animals.")
            ->and(grhData('greyhound.suitability.pdsa_grooming.quote'))->toBe('They have a short coat which requires weekly grooming to keep it in good condition.')
            ->and(grhData('greyhound.lifespan.pdsa.quote'))->toBe('Over 10 years');
    });

    it('records the sourced needs without a game mechanic for David (runs, weather care)', function () {
        foreach (['runs', 'weather_care'] as $need) {
            expect(grhData("proposed_game_parameters.greyhound.{$need}.decision"))->toContain('potrebna nova naloga v ROADMAP (David)')
                ->and(grhData("proposed_game_parameters.greyhound.{$need}.quote"))->not->toBeNull();
        }
    });
});
