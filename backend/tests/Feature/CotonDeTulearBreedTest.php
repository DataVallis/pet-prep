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
| M5-R10-96 — Coton de Tulear (appended after the Coton de Tulear: AKC 2024 No. 92 "Coton de Tulear")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `coton_de_tulear` (S721–S728) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.coton_de_tulear` (exercise 30 min — RKC "Up to 30 minutes
| per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12
| years"), arrival 2 / 9 / 36 / 108, learning × 1.0 (not in Coren's ranking), paid breed, Border Collie care rates,
| brushing every day (RKC "Every day", M5-R13), puppy socialisation with the PetMD quote (S726, M5-R17), the child's ear
| care once a week (PetMD "at least once a week", M5-R20), the parent's bath reminder every 4 weeks (PetMD
| "once-a-month", M5-R19). RKC Breed Watch Category 1 → in marketing; suits apartment / family_pet / children /
| other_pets / low_shedding; consider frequent_grooming / needs_company; white portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ctData(string $path): mixed
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

/** @return array<string, mixed> the Coton de Tulear seeder row of (stage, from, key) */
function ctRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'coton-de-tulear'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("coton-de-tulear.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ctPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->cotonDeTulear()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ctPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ctLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::CotonDeTulear;
        $config = BreedConfig::where('breed_slug', 'coton-de-tulear')->sole();

        expect($breed->value)->toBe('coton_de_tulear')
            ->and($breed->slug())->toBe('coton-de-tulear')
            ->and(BreedType::fromSlug('coton-de-tulear'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 900, 'breeds.coton_de_tulear'])
            ->and($config->search_keywords)->toBe(['coton de tulear', 'coton de tuléar', 'coton', 'cotonček', 'cotonek', 'tulear', 'royal dog of madagascar'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ctData('proposed_game_parameters.coton_de_tulear.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ctData('proposed_game_parameters.coton_de_tulear.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Coton de Tulear pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ctPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('coton_de_tulear');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'coton']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bichon_coton']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_14_120000_add_coton_de_tulear_breed.php');
        // down() refuses while a Coton de Tulear exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'coton_de_tulear']))->toThrow(QueryException::class);
        // The Standard Schnauzer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'standard_schnauzer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'coton_de_tulear']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::CotonDeTulear);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'coton-de-tulear')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'coton-de-tulear')->count());

        BreedConfig::where('breed_slug', 'coton-de-tulear')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'coton-de-tulear')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'coton-de-tulear')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'coton-de-tulear');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'coton-de-tulear');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'coton-de-tulear'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_COTON_DE_TULEAR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_COTON_DE_TULEAR');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.coton_de_tulear';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ctRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ctData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ctRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ctData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ctRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ctData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S724) = 108 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ctData('coton_de_tulear.lifespan.senior_from.months'))
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ctRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ctData('coton_de_tulear.lifespan.senior_from.derived_from'))->toBe(['S11', 'S724'])
            ->and(ctData('coton_de_tulear.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', ctData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_COTON_DE_TULEAR)
            ->and(ctRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(ctRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(ctRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ctData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ctRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(ctRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ctData('coton_de_tulear.exercise.adult.quote'))
            ->and(ctRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ctData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ctRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S724')
            ->and(ctRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(ctRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ctData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ctRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            ->and(ctRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ctData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(ctRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ctData("{$pgp}.learning_multiplier.value"))
            ->and(ctRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_COTON_DE_TULEAR) {
                $decided++;
                expect($row['breed_slug'])->toBe('coton-de-tulear')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.coton_de_tulear.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ctData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, RKC / PDSA), puppy socialisation with the PetMD quote (M5-R17),
        // ear care once a week and bath every 4 weeks (PetMD, M5-R19 / R20). No meal gap, no tooth / nail / groomer task (no
        // frequency), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['coton_de_tulear'];
        expect(json_encode([ctData('coton_de_tulear'), ctData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ctData("{$pgp}.grooming.value"))->toBe(7)
            ->and(ctData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('coton_de_tulear')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['coton_de_tulear'])->toBe(['S726', ctData("{$pgp}.socialisation.quote")])
            ->and(ctData("{$pgp}.socialisation.quote"))->toBe(ctData('coton_de_tulear.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['ear_cleaning', 'bath'])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S726', ctData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S726', ctData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(ctData("{$pgp}.ear_care.quote"))->toBe(ctData('coton_de_tulear.suitability.petmd_ears.quote'))
            ->and(ctData("{$pgp}.bathing.quote"))->toBe(ctData('coton_de_tulear.suitability.petmd_bath.quote'))
            ->and($tasks)->not->toHaveKey('tooth_brushing')->not->toHaveKey('nail_trim')->not->toHaveKey('groomer_visit')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('coton_de_tulear');
    });

    it('takes the breed-level numbers from the sourced coton_de_tulear entries', function () {
        // FCI (S722) males 4–6 kg, females 3.5–5 kg → game range 3.5–6.
        expect(ctRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.5, 6])
            ->and(ctData('coton_de_tulear.adult_weight.fci.value'))->toEqual(['male' => [4, 6], 'female' => [3.5, 5]])
            ->and(ctRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ctData('coton_de_tulear.adult_weight.fci.source_id'))
            ->and(ctRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ctData('coton_de_tulear.adult_weight.fci.quote'))
            ->and(ctRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(ctData('coton_de_tulear.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Not in Coren's ranking → learning 1.0.
            ->and(ctRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(ctData('coton_de_tulear.trainability.coren_rank.value'))->toBeNull()
            ->and(ctRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('coton_de_tulear.lifespan.rkc')
            ->and(ctRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S724')
            ->and(ctRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(ctRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ctData('coton_de_tulear.lifespan.rkc.quote'));

        // Brushing every day: RKC "Grooming: Every day" → 7 (PDSA "Daily grooming" agrees).
        $grooming = ctRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S724')
            ->and($grooming['quote'])->toBe(ctData('coton_de_tulear.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(ctData('proposed_game_parameters.coton_de_tulear.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(ctRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ctRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S726')
            ->and(ctRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'coton-de-tulear');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S726 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R19 / R20: the PetMD care tasks (S726).
            StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('coton-de-tulear'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ctPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, like the Havanese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('coton-de-tulear', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('coton-de-tulear', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at 1.0 × the mixed-breed speed (not in Coren\'s ranking) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ctPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'coton_de_tulear', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ctPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ctLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::CotonDeTulear)
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
        foreach (['border_collie', 'coton_de_tulear'] as $breed) {
            $pin = ctPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ctLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['coton_de_tulear'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('coton_de_tulear')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ctPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Coton de Tulear after the Standard Schnauzer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'coton_de_tulear', 'standard_schnauzer');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'coton_de_tulear', 'slug' => 'coton-de-tulear', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.coton_de_tulear',
            'search_keywords' => seededBreedConfig('coton_de_tulear')['search_keywords'], 'sort_order' => 900,
            'suitability' => [
                'suits' => ['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 283 S722)', function () {
    it('samples DNA in white only, never black, tan, tricolour or patches', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('coton_de_tulear')
            ->and(PetDnaService::hasAppearance('coton_de_tulear'))->toBeTrue()
            ->and(config('breed_appearance.coton_de_tulear.verified'))->toBeFalse()
            ->and(config('breed_appearance.coton_de_tulear.prompt_order'))->toContain('head')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.coton_de_tulear.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('coton_de_tulear', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('coton_de_tulear', $traits);
            expect($prompt)->toContain('Coton de Tulear')->toContain('pure white')
                ->not->toMatch('/\b(merle|tan|tricolou?r|brindle|black and white|patches)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.coton_de_tulear');
        expect($standard)->toBe(['pure white'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['white'])
            ->and(ctData('coton_de_tulear.appearance.colours.value'))->toBe(['white']);
    });

    it('draws the register portrait in white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::CotonDeTulear);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::CotonDeTulear))->toContain('Coton de Tulear')->toContain('pure white')
            ->and(BreedPortraitService::relativeFile(BreedType::CotonDeTulear))->toBe('dog/coton-de-tulear.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::CotonDeTulear);
        expect($v1['prompt_anchor'])->toContain('Coton de Tulear')->toContain('pure white')
            ->not->toMatch('/\b(merle|tan|tricolou?r)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('pure white');

        $pet = ctPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Coton de Tulear')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.coton_de_tulear'),
            config('breed_suitability.breeds.coton_de_tulear'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'coton-de-tulear')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::CotonDeTulear);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S724), straight muzzle with open nostrils (S723) → no breathing chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(ctData('coton_de_tulear.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(ctData('proposed_game_parameters.coton_de_tulear.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (128 Wikipedia, 135 PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'coton-de-tulear')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(128)->not->toContain(135)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an apartment family with children and other pets; consider daily grooming and company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::CotonDeTulear);
        expect($tags['suits'])->toBe(['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // PetMD's children statement has an introduction condition → never small_children; separation anxiety → never often_alone.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('large_home')
            ->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('shorter_lifespan')->not->toContain('chases_small_pets')
            ->not->toContain('experienced_owner');

        expect(ctData('coton_de_tulear.behaviour.family.quote'))->toContain('excellent family pets')
            ->and(ctData('coton_de_tulear.suitability.petmd_children.quote'))->toContain('introductions are done properly')
            ->and(ctData('coton_de_tulear.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(ctData('coton_de_tulear.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(ctData('coton_de_tulear.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(ctData('coton_de_tulear.lifespan.rkc.quote'))->toBe('Lifespan: Over 12 years');
    });
});
