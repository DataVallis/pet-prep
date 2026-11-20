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
| M5-R10-121 — Tibetan Terrier (appended after the American Eskimo Dog, blocked: AKC 2024 No. 117)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `tibetan_terrier` (S904–S911) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.tibetan_terrier` (exercise 60 min — PDSA "around an hour
| of exercise every day", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36 / 108 (0.75 × PDSA lower bound "Over 12 years"), arrival 2 / 9 / 36 / 108, learning × 0.7 (Coren 62, Fair),
| paid breed, Border Collie care rates, brushing every day (PDSA, M5-R13), puppy socialisation with the PetMD quote
| (S907, M5-R17), tooth brushing every day (WebMD, M5-R15); no nail / ear / bath / groomer task (no frequency).
| No welfare chip, in marketing; suits active_family / family_pet; consider frequent_grooming / needs_company; golden
| portrait (one of seven FCI colours), natural coat.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function tbtData(string $path): mixed
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

/** @return array<string, mixed> the Tibetan Terrier seeder row of (stage, from, key) */
function tbtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'tibetan-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("tibetan-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function tbtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->tibetanTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function tbtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function tbtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::TibetanTerrier;
        $config = BreedConfig::where('breed_slug', 'tibetan-terrier')->sole();

        expect($breed->value)->toBe('tibetan_terrier')
            ->and($breed->slug())->toBe('tibetan-terrier')
            ->and(BreedType::fromSlug('tibetan-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1120, 'breeds.tibetan_terrier'])
            ->and($config->search_keywords)->toBe(['tibetan terrier', 'tibetanski terier', 'tibetan terier', 'tibetan', 'terier', 'terrier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) tbtData('proposed_game_parameters.tibetan_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = tbtData('proposed_game_parameters.tibetan_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Tibetan Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = tbtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('tibetan_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'tsang_apso']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'tibetan_mastiff']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_14_120000_add_tibetan_terrier_breed.php');
        // down() refuses while a Tibetan Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'tibetan_terrier']))->toThrow(QueryException::class);
        // The Manchester Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'manchester_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'tibetan_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::TibetanTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'tibetan-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-terrier')->count());

        BreedConfig::where('breed_slug', 'tibetan-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'tibetan-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'tibetan-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'tibetan-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'tibetan-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_TIBETAN_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.tibetan_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(tbtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(tbtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(tbtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tbtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(tbtRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tbtData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PDSA lower bound "Over 12 years", S905 — no RKC page, no McMillan 2024 / VetCompass value)
            // → confirmed rule, verified.
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(tbtData('tibetan_terrier.lifespan.senior_from.months'))
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * tbtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(tbtData('tibetan_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S905'])
            // The Dogs Trust rule of thumb is background only — never the seeded boundary.
            ->and(tbtData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', tbtData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_TERRIER)
            ->and(tbtRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(tbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(tbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tbtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(tbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tbtData('tibetan_terrier.exercise.adult.quote'))
            ->and(tbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tbtData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(tbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S905')
            ->and(tbtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tbtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tbtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(tbtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(tbtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(tbtData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(tbtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(tbtData("{$pgp}.learning_multiplier.value"))
            ->and(tbtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('tibetan-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.tibetan_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) tbtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, PDSA), puppy socialisation with the PetMD quote (M5-R17)
        // and tooth brushing every day (WebMD, M5-R15). No nail / ear / bath / groomer task (no frequency), no meal gap,
        // no heat warning.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['tibetan_terrier'];
        expect(json_encode([tbtData('tibetan_terrier'), tbtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(tbtData("{$pgp}.grooming.value"))->toBe(7)
            ->and(tbtData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('tibetan_terrier')
            ->and(tbtData('tibetan_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['tibetan_terrier'])->toBe(['S907', tbtData("{$pgp}.socialisation.quote")])
            ->and(tbtData("{$pgp}.socialisation.quote"))->toBe(tbtData('tibetan_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S906', tbtData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(tbtData("{$pgp}.tooth_brushing.quote"))->toBe(tbtData('tibetan_terrier.suitability.webmd_teeth.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('tibetan_terrier');
    });

    it('takes the breed-level numbers from the sourced tibetan_terrier entries', function () {
        // CKC standard (S909): 8.25 to 13.5 kg (18 to 30 lb) for both sexes (FCI gives no weight).
        expect(tbtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([8.25, 13.5])
            ->and(tbtData('tibetan_terrier.adult_weight.ckc.value'))->toEqual(['male' => [8.25, 13.5], 'female' => [8.25, 13.5]])
            ->and(tbtRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(tbtData('tibetan_terrier.adult_weight.ckc.source_id'))
            ->and(tbtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(tbtData('tibetan_terrier.adult_weight.ckc.quote'))
            ->and(tbtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(tbtData('tibetan_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 62, Fair → 0.7.
            ->and(tbtRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(62)
            ->and(tbtData('tibetan_terrier.trainability.coren_rank.value'))->toBe(62)
            ->and(tbtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('tibetan_terrier.lifespan.pdsa')
            ->and(tbtRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S905')
            ->and(tbtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(tbtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(tbtData('tibetan_terrier.lifespan.pdsa.quote'));

        // Brushing every day: PDSA "Grooming is a daily requirement" → 7.
        $grooming = tbtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S905')
            ->and($grooming['quote'])->toBe(tbtData('tibetan_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(tbtData('proposed_game_parameters.tibetan_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(tbtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(tbtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S907')
            ->and(tbtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-terrier');
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
            // M5-R17: socialisation (PetMD S907 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15: tooth brushing (WebMD S906).
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('tibetan-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = tbtPet($arrival);
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

    it('becomes senior at 108 months, like the Manchester Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('tibetan-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('tibetan-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('manchester-terrier', 108))->toBe(LifeStage::Senior);
    });

    it('learns at 0.7× the mixed breed (Coren 62, Fair) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = tbtPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'tibetan_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = tbtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(tbtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::TibetanTerrier)
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
        foreach (['border_collie', 'tibetan_terrier'] as $breed) {
            $pin = tbtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(tbtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['tibetan_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('tibetan_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = tbtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Tibetan Terrier after the Manchester Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'tibetan_terrier', 'manchester_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'tibetan_terrier', 'slug' => 'tibetan-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.tibetan_terrier',
            'search_keywords' => seededBreedConfig('tibetan_terrier')['search_keywords'], 'sort_order' => 1120,
            'suitability' => [
                'suits' => ['active_family', 'family_pet'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 209 S904)', function () {
    it('samples DNA only in the seven named FCI colours — never chocolate, liver or merle, never a clipped coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('tibetan_terrier')
            ->and(PetDnaService::hasAppearance('tibetan_terrier'))->toBeTrue()
            ->and(config('breed_appearance.tibetan_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.tibetan_terrier.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.tibetan_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('tibetan_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('tibetan_terrier', $traits);
            expect($prompt)->toContain('Tibetan Terrier')->toContain('V-shaped')
                ->not->toMatch('/\b(merle|brindle|chocolate|liver|clipped|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.tibetan_terrier');
        expect($standard)->toBe(['golden', 'white', 'cream', 'grey or smoke', 'black', 'parti-colour (white with coloured patches)', 'tricolour (black, white and tan)'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['golden', 'white', 'cream', 'grey-smoke', 'black', 'parti-colour', 'tricolour'])
            ->and(tbtData('tibetan_terrier.appearance.colours.value'))->toBe(['golden', 'white', 'cream', 'grey or smoke', 'black', 'parti-colour', 'tricolour'])
            ->and(tbtData('tibetan_terrier.appearance.colours.quote'))->toBe('in fact any colour except chocolate, liver or merle permissible.');
    });

    it('draws the register portrait golden (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::TibetanTerrier);

        expect($traits['coat_color'])->toBe('golden')
            ->and($portrait->prompt(BreedType::TibetanTerrier))->toContain('Tibetan Terrier')->toContain('golden')
            ->and(BreedPortraitService::relativeFile(BreedType::TibetanTerrier))->toBe('dog/tibetan-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::TibetanTerrier);
        expect($v1['prompt_anchor'])->toContain('Tibetan Terrier')->toContain('golden')
            ->not->toMatch('/\b(merle|brindle|clipped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('golden');

        $pet = tbtPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Tibetan Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.tibetan_terrier'),
            config('breed_suitability.breeds.tibetan_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::TibetanTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no breathing / hips / back chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(tbtData('proposed_game_parameters.tibetan_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family and family life; consider daily grooming and company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::TibetanTerrier);
        expect($tags['suits'])->toBe(['active_family', 'family_pet'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // PetMD (supervised) and WebMD ("some individuals can struggle … young children") disagree → no children chip.
            // No RKC key facts → never apartment; Dogs Trust vs WebMD on shedding → neither shedding chip; no prey drive.
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('chases_small_pets')->not->toContain('cold_sensitive')
            ->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('not_for_small_children');

        expect(tbtData('tibetan_terrier.behaviour.family.quote'))->toBe('Tibetan Terriers generally get along well with everyone and will make good family pets.')
            ->and(tbtData('tibetan_terrier.behaviour.time_alone.quote'))->toBe('Tibetan Terriers can develop separation anxiety if left alone for long periods')
            ->and(tbtData('tibetan_terrier.suitability.pdsa_grooming.quote'))->toBe('Grooming is a daily requirement to keep their coats in tip-top condition.')
            ->and(tbtData('tibetan_terrier.lifespan.pdsa.quote'))->toBe('Over 12 years');
    });
});
