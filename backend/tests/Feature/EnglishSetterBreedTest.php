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
| M5-R10-97 — English Setter (appended after the Coton de Tulear: AKC 2024 No. 93 "English Setter")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `english_setter` (S729–S736) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.english_setter` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10
| years"), arrival 2 / 9 / 36 / 90, learning × 1.2 (Coren rank 37, Above average), paid breed, Border Collie care rates,
| brushing 3× a week (PDSA "2-3 times per week", M5-R13), puppy socialisation with the PDSA quote (S732, M5-R17), the
| parent's bath reminder every 6 weeks (PetMD "every six to eight weeks", M5-R19) and groomer reminder every 4 weeks
| (VCA "clipping and trimming … every month", M5-R18). RKC Breed Watch Category 1 → in marketing; suits active_family /
| family_pet / children / other_pets / large_home; consider long_daily_exercise / needs_company / sheds / frequent_grooming;
| blue belton portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function esData(string $path): mixed
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

/** @return array<string, mixed> the English Setter seeder row of (stage, from, key) */
function esRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'english-setter'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("english-setter.{$stage}.{$from}.{$key->value}");

    return $row;
}

function esPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->englishSetter()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function esPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function esLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::EnglishSetter;
        $config = BreedConfig::where('breed_slug', 'english-setter')->sole();

        expect($breed->value)->toBe('english_setter')
            ->and($breed->slug())->toBe('english-setter')
            ->and(BreedType::fromSlug('english-setter'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 910, 'breeds.english_setter'])
            ->and($config->search_keywords)->toBe(['english setter', 'setter', 'laverack', 'llewellin setter', 'belton setter', 'angleški seter', 'angleski seter', 'seter'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) esData('proposed_game_parameters.english_setter.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = esData('proposed_game_parameters.english_setter.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a English Setter pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = esPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('english_setter');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'setter']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'laverack']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_15_120000_add_english_setter_breed.php');
        // down() refuses while a English Setter exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'english_setter']))->toThrow(QueryException::class);
        // The Coton de Tulear (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'coton_de_tulear']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_setter']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::EnglishSetter);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'english-setter')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-setter')->count());

        BreedConfig::where('breed_slug', 'english-setter')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'english-setter')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'english-setter')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'english-setter');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-setter');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'english-setter'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_SETTER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_ENGLISH_SETTER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.english_setter';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(esRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(esData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(esRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(esData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(esRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(esData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S731) = 90 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(esData('english_setter.lifespan.senior_from.months'))
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * esRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(esData('english_setter.lifespan.senior_from.derived_from'))->toBe(['S11', 'S731'])
            ->and(esData('english_setter.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', esData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_SETTER)
            ->and(esRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(esRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(esRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(esData("{$pgp}.exercise_minutes_adult.value"))
            ->and(esRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(esRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(esData('english_setter.exercise.adult.quote'))
            ->and(esRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(esData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(esRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S731')
            ->and(esRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(esRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(esData("{$pgp}.exercise_minutes_senior.value"))
            ->and(esRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(esRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(esData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(esRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(esData("{$pgp}.learning_multiplier.value"))
            ->and(esRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_SETTER) {
                $decided++;
                expect($row['breed_slug'])->toBe('english-setter')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.english_setter.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) esData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 3× a week (M5-R13, PDSA), puppy socialisation with the PDSA quote (M5-R17),
        // bath every 6 weeks (PetMD, M5-R19) and groomer every 4 weeks (VCA, M5-R18). No meal gap, no ear / tooth / nail task
        // (no frequency), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['english_setter'];
        expect(json_encode([esData('english_setter'), esData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(esData("{$pgp}.grooming.value"))->toBe(3)
            ->and(esData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('english_setter')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['english_setter'])->toBe(['S732', esData("{$pgp}.socialisation.quote")])
            ->and(esData("{$pgp}.socialisation.quote"))->toBe(esData('english_setter.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['bath', 'groomer_visit'])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'six_weeks', 'S733', esData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'month', 'S734', esData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(esData("{$pgp}.bathing.quote"))->toBe(esData('english_setter.suitability.petmd_bath.quote'))
            ->and(esData("{$pgp}.professional_grooming.quote"))->toBe(esData('english_setter.suitability.vca_grooming.quote'))
            ->and($tasks)->not->toHaveKey('tooth_brushing')->not->toHaveKey('nail_trim')->not->toHaveKey('ear_cleaning')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('english_setter');
    });

    it('takes the breed-level numbers from the sourced english_setter entries', function () {
        // PDSA (S732) 22-29 kg, one range for both sexes (the FCI standard gives no weight).
        expect(esRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([22, 29])
            ->and(esData('english_setter.adult_weight.pdsa.value'))->toEqual(['male' => [22, 29], 'female' => [22, 29]])
            ->and(esRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(esData('english_setter.adult_weight.pdsa.source_id'))
            ->and(esRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(esData('english_setter.adult_weight.pdsa.quote'))
            ->and(esRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(esData('english_setter.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 37 → Above average → 1.2.
            ->and(esRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(37)
            ->and(esData('english_setter.trainability.coren_rank.value'))->toBe(37)
            ->and(esRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('english_setter.lifespan.rkc')
            ->and(esRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S731')
            ->and(esRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(esRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(esData('english_setter.lifespan.rkc.quote'));

        // Brushing 3× a week: RKC "More than once a week" has no number → PDSA "2-3 times per week" → upper bound 3.
        $grooming = esRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S732')
            ->and($grooming['quote'])->toBe(esData('english_setter.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(esData('proposed_game_parameters.english_setter.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(esRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(esRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S732')
            ->and(esRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-setter');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::GroomerVisitFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PDSA S732 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R18 / R19: the parent's groomer (VCA S734) and bath (PetMD S733) reminders.
            StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('english-setter'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = esPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Irish Setter', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('english-setter', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('english-setter', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('irish-setter', 90))->toBe(LifeStage::Senior);
    });

    it('learns at 1.2 × the mixed-breed speed (Coren rank 37, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = esPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'english_setter', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = esPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(esLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::EnglishSetter)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 90 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'english_setter'] as $breed) {
            $pin = esPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(esLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['english_setter'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('english_setter')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = esPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the English Setter after the Coton de Tulear with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'english_setter', 'coton_de_tulear');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'english_setter', 'slug' => 'english-setter', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.english_setter',
            'search_keywords' => seededBreedConfig('english_setter')['search_keywords'], 'sort_order' => 910,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'other_pets', 'large_home'],
                'consider' => ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 2 S730)', function () {
    it('samples DNA in the six standard belton / tricolour colours only, never solid, merle or brindle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('english_setter')
            ->and(PetDnaService::hasAppearance('english_setter'))->toBeTrue()
            ->and(config('breed_appearance.english_setter.verified'))->toBeFalse()
            ->and(config('breed_appearance.english_setter.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.english_setter.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('english_setter', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('english_setter', $traits);
            expect($prompt)->toContain('English Setter')->toContain('belton')
                ->not->toMatch('/\b(merle|brindle|solid black|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.english_setter');
        expect($standard)->toHaveCount(6)
            ->and($standard[0])->toBe('blue belton (white evenly flecked with black)')
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['blue-belton', 'orange-belton', 'blue-belton-tan', 'lemon-belton', 'liver-belton', 'liver-belton-tan'])
            ->and(esData('english_setter.appearance.colours.value'))->toHaveCount(6);
    });

    it('draws the register portrait in blue belton (the highest weight)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::EnglishSetter);

        expect($traits['coat_color'])->toBe('blue belton (white evenly flecked with black)')
            ->and($portrait->prompt(BreedType::EnglishSetter))->toContain('English Setter')->toContain('blue belton')
            ->and(BreedPortraitService::relativeFile(BreedType::EnglishSetter))->toBe('dog/english-setter.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::EnglishSetter);
        expect($v1['prompt_anchor'])->toContain('English Setter')->toContain('blue belton')
            ->not->toMatch('/\b(merle|brindle|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('blue belton (white evenly flecked with black)');

        $pet = esPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('English Setter')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.english_setter'),
            config('breed_suitability.breeds.english_setter'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-setter')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::EnglishSetter);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S731), a long muzzle (S730) → no breathing / hips chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')
            ->and(esData('english_setter.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(esData('proposed_game_parameters.english_setter.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (118 Wikipedia, 108 PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-setter')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(118)->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family with children and other pets in a large home; consider long walks, company, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::EnglishSetter);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'other_pets', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'])
            // No explicit small-children rating → never small_children; PetMD "don't like being left alone" → never often_alone.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('apartment')
            ->not->toContain('first_time_owner')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('chases_small_pets')
            ->not->toContain('experienced_owner');

        expect(esData('english_setter.behaviour.family.quote'))->toContain('perfect for families')
            ->and(esData('english_setter.behaviour.time_alone.quote'))->toContain('left alone for long')
            ->and(esData('english_setter.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(esData('english_setter.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(esData('english_setter.lifespan.rkc.quote'))->toBe('Over 10 years');
    });
});
