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
| M5-R10-49 — Portuguese Water Dog (appended after the Papillon: AKC 2024 No. 45 "Portuguese Water Dog")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `portuguese_water_dog` (S402–S408) and the runbook's standing
| rules (confirmed by David 2026-10-10) in `proposed_game_parameters.portuguese_water_dog` (exercise 60 min —
| RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 — 0.75 ×
| RKC lower bound 10 y, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 90, learning × 1.0
| (not ranked by Coren), paid breed, Border Collie care rates, brushing every day (RKC "Every day", M5-R13),
| puppy socialisation with the PDSA quote (M5-R17), no meal / walk gap (no advice); suitability: suits
| family_pet / children / large_home / other_pets / low_shedding (never hypoallergenic), consider
| frequent_grooming; solid black (portrait) in the standard's lion clip; not a welfare breed (RKC Breed Watch
| Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pwdData(string $path): mixed
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

/** @return array<string, mixed> the Portuguese Water Dog seeder row of (stage, from, key) */
function pwdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'portuguese-water-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("portuguese_water_dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function pwdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->portugueseWaterDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pwdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pwdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::PortugueseWaterDog;
        $config = BreedConfig::where('breed_slug', 'portuguese-water-dog')->sole();

        expect($breed->value)->toBe('portuguese_water_dog')
            ->and($breed->slug())->toBe('portuguese-water-dog')
            ->and(BreedType::fromSlug('portuguese-water-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 450, 'breeds.portuguese_water_dog'])
            ->and($config->search_keywords)->toBe(['portuguese water dog', 'portie', 'pwd', 'cao de agua', 'cão de água português', 'portugalski vodni pes', 'vodni pes', 'portugalski'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pwdData('proposed_game_parameters.portuguese_water_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pwdData('proposed_game_parameters.portuguese_water_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Portuguese Water Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pwdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('portuguese_water_dog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "portie" / "cao_de_agua_portugues" are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'portie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'cao_de_agua_portugues']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_29_120000_add_portuguese_water_dog_breed.php');
        // down() refuses while a Portuguese Water Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'portuguese_water_dog']))->toThrow(QueryException::class);
        // The Papillon (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'papillon']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'portuguese_water_dog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::PortugueseWaterDog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'portuguese-water-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')->count());

        BreedConfig::where('breed_slug', 'portuguese-water-dog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'portuguese-water-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'portuguese-water-dog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'portuguese-water-dog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'portuguese-water-dog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PORTUGUESE_WATER_DOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Portuguese Water Dog's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_PORTUGUESE_WATER_DOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.portuguese_water_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pwdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pwdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pwdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pwdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pwdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pwdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S404) = 90 (provisional until McMillan 2024 is checked).
            ->and(pwdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pwdData('portuguese_water_dog.lifespan.senior_from.months'))
            ->and(pwdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pwdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pwdData('portuguese_water_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S404'])
            ->and(pwdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S404')
            ->and(pwdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(pwdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(pwdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pwdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pwdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(pwdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(pwdData('portuguese_water_dog.exercise.adult.quote'))
            ->and(pwdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(pwdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pwdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pwdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(pwdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pwdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(pwdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pwdData("{$pgp}.learning_multiplier.value"))
            ->and(pwdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Portuguese Water Dog decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PORTUGUESE_WATER_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('portuguese-water-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.portuguese_water_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pwdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); the groomer waits for ROADMAP M5-R18, tooth brushing for M5-R15,
        // nail trimming for M5-R19, ear cleaning / baths for M5-R20. No source advises a meal / walk gap → no M5-R16 row.
        expect(json_encode([pwdData('portuguese_water_dog'), pwdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(pwdData("{$pgp}.grooming.value"))->toBe(7)
            ->and(pwdData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(pwdData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(pwdData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(pwdData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(pwdData("{$pgp}.ear_care_bathing.decision"))->toContain('ROADMAP M5-R20')
            ->and(pwdData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(pwdData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['portuguese_water_dog'][1])
            ->and(pwdData('portuguese_water_dog.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['portuguese_water_dog'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('portuguese_water_dog')
            ->and(pwdData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(pwdData('portuguese_water_dog.exercise.after_meals.source_id'))->toBeNull();
    });

    it('takes the breed-level numbers from the sourced portuguese_water_dog entries', function () {
        // RKC breed standard (S403): dogs 19–25 kg, bitches 16–22 kg → 16–25 kg.
        expect(pwdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16.0, 25.0])
            ->and(pwdData('portuguese_water_dog.adult_weight.rkc_standard.value'))->toEqual(['male' => [19, 25], 'female' => [16, 22]])
            ->and(pwdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pwdData('portuguese_water_dog.adult_weight.rkc_standard.source_id'))
            ->and(pwdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(pwdData('portuguese_water_dog.adult_weight.rkc_standard.quote'))
            ->and(pwdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(pwdData('portuguese_water_dog.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not ranked by Coren (S35) → learning 1.0.
            ->and(pwdRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(pwdData('portuguese_water_dog.trainability.coren_rank.value'))->toBeNull()
            ->and(pwdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('portuguese_water_dog.lifespan.rkc')
            ->and(pwdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S404')
            ->and(pwdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(pwdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(pwdData('portuguese_water_dog.lifespan.rkc.quote'));

        // Brushing every day (RKC "Grooming: Every day" → 7) — the rule, verified.
        $grooming = pwdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S404')
            ->and($grooming['quote'])->toBe(pwdData('portuguese_water_dog.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(pwdData('proposed_game_parameters.portuguese_water_dog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(pwdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(pwdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S405');
        // No meal / walk gap row (VCA only lists bloat; no source advises a gap).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S405).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('portuguese-water-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pwdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, the same month as the Rhodesian Ridgeback', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('portuguese-water-dog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('portuguese-water-dog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rhodesian-ridgeback', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rhodesian-ridgeback', 89))->toBe(LifeStage::Adult);
    });

    it('learns like a mixed breed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = pwdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'portuguese_water_dog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pwdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pwdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::PortugueseWaterDog)
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
        foreach (['border_collie', 'portuguese_water_dog'] as $breed) {
            $pin = pwdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pwdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['portuguese_water_dog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Portuguese Water Dog appearance data.
            ->and($dog->pet_dna['breed'])->toBe('portuguese_water_dog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pwdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Portuguese Water Dog after the Papillon with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'portuguese_water_dog', 'papillon');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'portuguese_water_dog', 'slug' => 'portuguese-water-dog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.portuguese_water_dog',
            'search_keywords' => seededBreedConfig('portuguese-water-dog')['search_keywords'], 'sort_order' => 450,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'large_home', 'other_pets', 'low_shedding'],
                'consider' => ['frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — black, white, brown and their bicolours; the standard\'s lion clip)', function () {
    it('samples Portuguese Water Dog DNA in the five standard colour options only, always in the lion clip with drop ears', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('portuguese_water_dog')
            ->and(PetDnaService::hasAppearance('portuguese_water_dog'))->toBeTrue()
            ->and(config('breed_appearance.portuguese_water_dog.verified'))->toBeFalse()
            ->and(config('breed_appearance.portuguese_water_dog.prompt_order'))->toContain('clip')->toContain('ear_carriage')
            ->and(config('breed_appearance.portuguese_water_dog.sources'))->toHaveKey('clip');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('portuguese_water_dog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toMatch('/^(solid black|black with white|brown|white with a black nose)/')
                ->and($traits['clip'])->toContain('lion clip')
                ->and($traits['ear_carriage'])->toContain('drop ears')
                ->and($traits['coat_length'])->toContain('without undercoat');

            $prompt = $prompts->imagePrompt('portuguese_water_dog', $traits);
            expect($prompt)->toContain('Portuguese Water Dog')
                ->not->toMatch('/\b(merle|brindle|silver|cream|apricot|tan|sable|parti|docked|cropped|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_colours.portuguese_water_dog'), 'value');
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and($standard)->toHaveCount(5)
            ->and(array_column(config('breed_colours.portuguese_water_dog'), 'key'))->toBe(['black', 'black-white', 'brown', 'brown-white', 'white']);
    });

    it('draws the register portrait solid black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::PortugueseWaterDog);

        expect($traits['coat_color'])->toStartWith('solid black')
            ->and($portrait->prompt(BreedType::PortugueseWaterDog))->toContain('Portuguese Water Dog')->toContain('solid black')->toContain('lion clip')
            ->and(BreedPortraitService::relativeFile(BreedType::PortugueseWaterDog))->toBe('dog/portuguese-water-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::PortugueseWaterDog);
        expect($v1['prompt_anchor'])->toContain('Portuguese Water Dog')->toContain('solid black')->toContain('lion clip')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid black', 'black with white on the chin and chest', 'brown'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = pwdPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Portuguese Water Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.portuguese_water_dog'),
            config('breed_suitability.breeds.portuguese_water_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::PortugueseWaterDog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['frequent_grooming'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 10 years"; RKC "Up to 1 hour"; no prey-drive statement.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets', 'sheds']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'first_time_owner', 'active_family', 'apartment']))->toBe([])
            ->and(pwdData('portuguese_water_dog.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(pwdData('proposed_game_parameters.portuguese_water_dog.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) is an alternative only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'portuguese-water-dog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Portuguese Water Dog family_pet / children / large_home / other_pets / low_shedding and the grooming chip — no welfare chip (Breed Watch Category 1), never hypoallergenic', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::PortugueseWaterDog);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'large_home', 'other_pets', 'low_shedding'])
            // VCA "Fine with older kids" → never small_children; no first-time-owner statement.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('active_family')->not->toContain('first_time_owner')->not->toContain('apartment')
            ->and($tags['consider'])->toBe(['frequent_grooming'])
            // Breed Watch Category 1 → no welfare chip; 60 min a day; no prey-drive / time-alone statement; RKC "Sheds: No".
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('needs_company')
            ->not->toContain('sheds')->not->toContain('older_children')->not->toContain('fragile_older_children');

        expect(pwdData('portuguese_water_dog.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(pwdData('portuguese_water_dog.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(pwdData('portuguese_water_dog.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(pwdData('portuguese_water_dog.suitability.petmd_children.quote'))->toBe('A Portuguese Water Dog can make a great family pet that can be around children and other animals.')
            ->and(pwdData('portuguese_water_dog.behaviour.time_alone.quote'))->toBeNull()
            ->and(pwdData('portuguese_water_dog.suitability.rkc_breed_watch.value'))->toBe('Category 1');
    });
});
