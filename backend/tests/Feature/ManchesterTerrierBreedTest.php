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
| M5-R10-119 — Manchester Terrier (appended after the Boerboel, blocked: AKC 2024 No. 115)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `manchester_terrier` (S888–S894) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.manchester_terrier` (exercise 60 min — PDSA "up to an hour
| of exercise per day", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36 / 108 (0.75 × PDSA lower bound "Over 12 years"), arrival 2 / 9 / 36 / 108, learning × 1.2 (Coren 32, Above
| average), paid breed, Border Collie care rates, brushing once a week (PDSA, M5-R13), puppy socialisation with the PetMD
| quote (S890, M5-R17), tooth brushing every day (WebMD, M5-R15); no nail / ear / bath / groomer task (no frequency).
| No welfare chip, in marketing; suits active_family / children; consider cold_sensitive / chases_small_pets; black and
| tan portrait, natural ears. Not the English Toy Terrier.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function mntData(string $path): mixed
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

/** @return array<string, mixed> the Manchester Terrier seeder row of (stage, from, key) */
function mntRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'manchester-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("manchester-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function mntPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->manchesterTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function mntPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function mntLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ManchesterTerrier;
        $config = BreedConfig::where('breed_slug', 'manchester-terrier')->sole();

        expect($breed->value)->toBe('manchester_terrier')
            ->and($breed->slug())->toBe('manchester-terrier')
            ->and(BreedType::fromSlug('manchester-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1110, 'breeds.manchester_terrier'])
            ->and($config->search_keywords)->toBe(['manchester terrier', 'manchesterski terier', 'manchester terier', 'manchester', 'black and tan terrier', 'terier', 'terrier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) mntData('proposed_game_parameters.manchester_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = mntData('proposed_game_parameters.manchester_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Manchester Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = mntPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('manchester_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'toy_manchester_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'english_toy_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_10_120000_add_manchester_terrier_breed.php');
        // down() refuses while a Manchester Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'manchester_terrier']))->toThrow(QueryException::class);
        // The Wire Fox Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'wire_fox_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'manchester_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ManchesterTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'manchester-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'manchester-terrier')->count());

        BreedConfig::where('breed_slug', 'manchester-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'manchester-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'manchester-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'manchester-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'manchester-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'manchester-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MANCHESTER_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_MANCHESTER_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.manchester_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(mntRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(mntData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(mntRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mntData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(mntRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mntData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PDSA lower bound "Over 12 years", S889 — no RKC page, no McMillan 2024 / VetCompass value)
            // → confirmed rule, verified.
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(mntData('manchester_terrier.lifespan.senior_from.months'))
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * mntRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(mntData('manchester_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S889'])
            // The older KC survey via Wikipedia (tier C) is background only — never the seeded boundary.
            ->and(mntData('manchester_terrier.lifespan.kc_survey_via_wikipedia.source_id'))->toBe('S894')
            ->and(mntData("{$pgp}.stage_boundaries_months.alternatives.senior_kc_survey_via_wikipedia_12_8_y"))->toBe(115)
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', mntData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_MANCHESTER_TERRIER)
            ->and(mntRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(mntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(mntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mntData("{$pgp}.exercise_minutes_adult.value"))
            ->and(mntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(mntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(mntData('manchester_terrier.exercise.adult.quote'))
            ->and(mntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(mntData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(mntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S889')
            ->and(mntRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(mntRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mntData("{$pgp}.exercise_minutes_senior.value"))
            ->and(mntRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(mntRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(mntData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(mntRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(mntData("{$pgp}.learning_multiplier.value"))
            ->and(mntRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MANCHESTER_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('manchester-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.manchester_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) mntData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing once a week (M5-R13, PDSA), puppy socialisation with the PetMD quote (M5-R17)
        // and tooth brushing every day (WebMD, M5-R15). No nail / ear / bath / groomer task (no frequency), no meal gap,
        // no heat warning.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['manchester_terrier'];
        expect(json_encode([mntData('manchester_terrier'), mntData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(mntData("{$pgp}.grooming.value"))->toBe(1)
            ->and(mntData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('manchester_terrier')
            ->and(mntData('manchester_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['manchester_terrier'])->toBe(['S890', mntData("{$pgp}.socialisation.quote")])
            ->and(mntData("{$pgp}.socialisation.quote"))->toBe(mntData('manchester_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S891', mntData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(mntData("{$pgp}.tooth_brushing.quote"))->toBe(mntData('manchester_terrier.suitability.webmd_teeth.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('manchester_terrier');
    });

    it('takes the breed-level numbers from the sourced manchester_terrier entries', function () {
        // CKC standard (S892): 5.5 to 10 kg (12 to 22 lb) for both sexes (FCI gives no weight).
        expect(mntRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.5, 10])
            ->and(mntData('manchester_terrier.adult_weight.ckc.value'))->toEqual(['male' => [5.5, 10], 'female' => [5.5, 10]])
            ->and(mntRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(mntData('manchester_terrier.adult_weight.ckc.source_id'))
            ->and(mntRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(mntData('manchester_terrier.adult_weight.ckc.quote'))
            ->and(mntRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(mntData('manchester_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 32, Above average → 1.2.
            ->and(mntRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(32)
            ->and(mntData('manchester_terrier.trainability.coren_rank.value'))->toBe(32)
            ->and(mntRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('manchester_terrier.lifespan.pdsa')
            ->and(mntRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S889')
            ->and(mntRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(mntRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(mntData('manchester_terrier.lifespan.pdsa.quote'));

        // Brushing once a week: PDSA "brushing once a week" → 1.
        $grooming = mntRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S889')
            ->and($grooming['quote'])->toBe(mntData('manchester_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(mntData('proposed_game_parameters.manchester_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(mntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(mntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S890')
            ->and(mntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'manchester-terrier');
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
            // M5-R17: socialisation (PetMD S890 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15: tooth brushing (WebMD S891).
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('manchester-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = mntPet($arrival);
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
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, like the Wire Fox Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('manchester-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('manchester-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('wire-fox-terrier', 108))->toBe(LifeStage::Senior);
    });

    it('learns 1.2× faster than the mixed breed (Coren 32, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = mntPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'manchester_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = mntPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(mntLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ManchesterTerrier)
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
        foreach (['border_collie', 'manchester_terrier'] as $breed) {
            $pin = mntPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(mntLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['manchester_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('manchester_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = mntPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Manchester Terrier after the Wire Fox Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'manchester_terrier', 'wire_fox_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'manchester_terrier', 'slug' => 'manchester-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.manchester_terrier',
            'search_keywords' => seededBreedConfig('manchester_terrier')['search_keywords'], 'sort_order' => 1110,
            'suitability' => [
                'suits' => ['active_family', 'children'],
                'consider' => ['cold_sensitive', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 71 S888)', function () {
    it('samples DNA only in black and tan — never white, blue, another colour or cropped ears', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('manchester_terrier')
            ->and(PetDnaService::hasAppearance('manchester_terrier'))->toBeTrue()
            ->and(config('breed_appearance.manchester_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.manchester_terrier.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.manchester_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('manchester_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('manchester_terrier', $traits);
            expect($prompt)->toContain('Manchester Terrier')->toContain('black and rich mahogany tan')->toContain('V-shaped ears')
                ->not->toMatch('/\b(merle|brindle|blue|white|cropped|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.manchester_terrier');
        expect($standard)->toBe(['jet black and rich mahogany tan'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-tan'])
            ->and(mntData('manchester_terrier.appearance.colours.value'))->toBe(['jet black and rich mahogany tan'])
            ->and(mntData('manchester_terrier.appearance.colours.quote'))->toBe('Any other colour or combination of colours unacceptable.');
    });

    it('draws the register portrait black and tan (the only standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ManchesterTerrier);

        expect($traits['coat_color'])->toBe('jet black and rich mahogany tan')
            ->and($portrait->prompt(BreedType::ManchesterTerrier))->toContain('Manchester Terrier')->toContain('jet black and rich mahogany tan')
            ->and(BreedPortraitService::relativeFile(BreedType::ManchesterTerrier))->toBe('dog/manchester-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ManchesterTerrier);
        expect($v1['prompt_anchor'])->toContain('Manchester Terrier')->toContain('jet black and rich mahogany tan')
            ->not->toMatch('/\b(merle|brindle|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('jet black and rich mahogany tan');

        $pet = mntPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Manchester Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.manchester_terrier'),
            config('breed_suitability.breeds.manchester_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'manchester-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ManchesterTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no breathing / hips / back chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(mntData('proposed_game_parameters.manchester_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 115 KC survey via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'manchester-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(115);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family and children; consider the cold and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ManchesterTerrier);
        expect($tags['suits'])->toBe(['active_family', 'children'])
            ->and($tags['consider'])->toBe(['cold_sensitive', 'chases_small_pets'])
            // WebMD "small children should never be left with dogs unsupervised" → children, never small_children. No RKC
            // key facts → never apartment / sheds; 60 min → never long_daily_exercise; no family or time-alone source.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('family_pet')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('needs_company')->not->toContain('frequent_grooming')
            ->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('not_for_small_children');

        expect(mntData('manchester_terrier.behaviour.children.quote'))->toBe('Manchesters also do well with children, especially when exposed to kids during puppyhood.')
            ->and(mntData('manchester_terrier.behaviour.family.quote'))->toBeNull()
            ->and(mntData('manchester_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(mntData('manchester_terrier.behaviour.time_alone.quote'))->toBeNull()
            ->and(mntData('manchester_terrier.suitability.webmd_cold.quote'))->toBe('Manchester Terriers are sensitive to the cold.')
            ->and(mntData('manchester_terrier.lifespan.pdsa.quote'))->toBe('Over 12 years');
    });
});
