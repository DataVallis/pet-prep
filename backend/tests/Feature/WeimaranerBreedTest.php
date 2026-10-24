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
| M5-R10-59 — Weimaraner (appended after the Scottish Terrier: AKC 2024 No. 55)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `weimaraner` (S477–S483) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.weimaraner` (exercise 120 min — RKC "Exercise: More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 — 0.75 × RKC lower bound "Over 10
| years"; the Dogs Trust McMillan 2024 table could not be fetched —, arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren
| rank 21, Excellent), paid breed, Border Collie care rates, brushing once a week (RKC "Grooming: Once a week",
| M5-R13), puppy socialisation with the PDSA quote (M5-R17), the digestion warning with the PDSA quote (M5-R16);
| suitability: suits active_family / family_pet / large_home, consider long_daily_exercise / experienced_owner /
| not_for_small_children / needs_company / sheds / chases_small_pets; silver grey portrait (grey only); RKC Breed
| Watch Category 1, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function wmrData(string $path): mixed
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

/** @return array<string, mixed> the Weimaraner seeder row of (stage, from, key) */
function wmrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'weimaraner'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("weimaraner.{$stage}.{$from}.{$key->value}");

    return $row;
}

function wmrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->weimaraner()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function wmrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function wmrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Weimaraner;
        $config = BreedConfig::where('breed_slug', 'weimaraner')->sole();

        expect($breed->value)->toBe('weimaraner')
            ->and($breed->slug())->toBe('weimaraner')
            ->and(BreedType::fromSlug('weimaraner'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 550, 'breeds.weimaraner'])
            ->and($config->search_keywords)->toBe(['weimaraner', 'weimarski ptičar', 'weimarski pticar', 'vajmarski ptičar', 'vajmarski pticar', 'weim'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) wmrData('proposed_game_parameters.weimaraner.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = wmrData('proposed_game_parameters.weimaraner.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Weimaraner pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = wmrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('weimaraner');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'weimarski_pticar']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'weim']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_16_120000_add_weimaraner_breed.php');
        // down() refuses while a Weimaraner exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'weimaraner']))->toThrow(QueryException::class);
        // The Scottish Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'scottish_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'weimaraner']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Weimaraner);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'weimaraner')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'weimaraner')->count());

        BreedConfig::where('breed_slug', 'weimaraner')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'weimaraner')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'weimaraner')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'weimaraner');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'weimaraner');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'weimaraner'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WEIMARANER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_WEIMARANER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.weimaraner';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(wmrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(wmrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(wmrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wmrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(wmrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wmrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S479) = 90 — the runbook's third choice.
            ->and(wmrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(wmrData('weimaraner.lifespan.senior_from.months'))
            ->and(wmrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * wmrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(wmrData('weimaraner.lifespan.senior_from.derived_from'))->toBe(['S11', 'S479'])
            ->and(wmrData('weimaraner.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(wmrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S479')
            ->and(wmrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(wmrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(wmrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wmrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(wmrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(wmrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wmrData('weimaraner.exercise.adult.quote'))
            ->and(wmrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S479')
            ->and(wmrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(wmrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wmrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(wmrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(wmrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(wmrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(wmrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(wmrData("{$pgp}.learning_multiplier.value"))
            ->and(wmrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WEIMARANER) {
                $decided++;
                expect($row['breed_slug'])->toBe('weimaraner')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.weimaraner.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) wmrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); monthly ear cleaning and baths have no
        // mechanic → open for David (ROADMAP M5-R20).
        expect(json_encode([wmrData('weimaraner'), wmrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(wmrData("{$pgp}.grooming.value"))->toBe(1)
            ->and(wmrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(wmrData("{$pgp}.ear_care_bathing.decision"))->toContain('M5-R20')
            ->and(wmrData($pgp))->not->toHaveKey('bloat_feeding')
            ->and(wmrData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(wmrData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['weimaraner'][1])
            ->and(wmrData('weimaraner.health.gdv.notes'))->toContain(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['weimaraner'][1])
            ->and(wmrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['weimaraner'][1])
            ->and(wmrData('weimaraner.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['weimaraner'][1]);
    });

    it('takes the breed-level numbers from the sourced weimaraner entries', function () {
        // FCI standard No. 99 (S478): males about 30–40 kg, females about 25–35 kg → 25–40 kg.
        expect(wmrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([25.0, 40.0])
            ->and(wmrData('weimaraner.adult_weight.fci.value'))->toEqual(['male' => [30, 40], 'female' => [25, 35]])
            ->and(wmrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(wmrData('weimaraner.adult_weight.fci.source_id'))
            ->and(wmrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(wmrData('weimaraner.adult_weight.fci.quote'))
            ->and(wmrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(wmrData('weimaraner.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 21 (S35), Excellent tier → learning 1.5.
            ->and(wmrRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(21)
            ->and(wmrRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(wmrData('weimaraner.trainability.coren_rank.quote'))
            ->and(wmrData('weimaraner.trainability.coren_rank.value'))->toBe(21)
            ->and(wmrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('weimaraner.lifespan.rkc')
            ->and(wmrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S479')
            ->and(wmrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(wmrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(wmrData('weimaraner.lifespan.rkc.quote'));

        // Brushing once a week (RKC "Grooming: Once a week" → 1) — the rule, verified.
        $grooming = wmrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S479')
            ->and($grooming['quote'])->toBe(wmrData('weimaraner.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(wmrData('proposed_game_parameters.weimaraner.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(wmrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(wmrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S480')
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice.
            ->and(wmrRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(wmrRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S480');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'weimaraner');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning (PDSA S480) and the puppy's weekly "new experience"
            // with the breed's own PDSA quote (S480).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('weimaraner'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = wmrPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Chesapeake Bay Retriever and 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('weimaraner', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('weimaraner', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('chesapeake-bay-retriever', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = wmrPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'weimaraner', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = wmrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(wmrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Weimaraner)
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
        foreach (['border_collie', 'weimaraner'] as $breed) {
            $pin = wmrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(wmrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['weimaraner'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('weimaraner')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = wmrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Weimaraner after the Scottish Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'weimaraner', 'scottish_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'weimaraner', 'slug' => 'weimaraner', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.weimaraner',
            'search_keywords' => seededBreedConfig('weimaraner')['search_keywords'], 'sort_order' => 550,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'large_home'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — grey only)', function () {
    it('samples DNA in the three standard grey shades of the appearance block only, never blue or brown', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('weimaraner')
            ->and(PetDnaService::hasAppearance('weimaraner'))->toBeTrue()
            ->and(config('breed_appearance.weimaraner.verified'))->toBeFalse()
            ->and(config('breed_appearance.weimaraner.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('nose');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('weimaraner', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('short')
                ->and($traits['tail'])->toContain('undocked')
                ->and($traits['eye_color'])->toContain('amber');

            $prompt = $prompts->imagePrompt('weimaraner', $traits);
            expect($prompt)->toContain('Weimaraner')
                ->not->toMatch('/\b(black|blue|brown|tan|merle|cropped|long-haired|Vizsla)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['silver grey', 'roe grey', 'mouse grey'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.weimaraner'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.weimaraner'), 'key'))->toBe(['silver-grey', 'roe-grey', 'mouse-grey']);
    });

    it('draws the register portrait silver grey', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Weimaraner);

        expect($traits['coat_color'])->toBe('silver grey')
            ->and($portrait->prompt(BreedType::Weimaraner))->toContain('Weimaraner')->toContain('silver grey')
            ->and(BreedPortraitService::relativeFile(BreedType::Weimaraner))->toBe('dog/weimaraner.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Weimaraner);
        expect($v1['prompt_anchor'])->toContain('Weimaraner')->toContain('silver grey')
            ->not->toMatch('/\b(black|blue|brown)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['silver grey', 'roe grey', 'mouse grey'])
            ->and($v1['visual_traits']['eye_color'])->toBe('amber');

        $pet = wmrPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Weimaraner')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.weimaraner'),
            config('breed_suitability.breeds.weimaraner'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'weimaraner')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Weimaraner);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S479) → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(wmrData('weimaraner.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(wmrData('proposed_game_parameters.weimaraner.marketing.source_id'))->toBe('S479')
            ->and(wmrData('proposed_game_parameters.weimaraner.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and McMillan 2024 via Wikipedia (115) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'weimaraner')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(115);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family, family and large-home chips plus long exercise, experienced owner, no small children, company, shedding and prey drive; never children, small_children, other_pets or apartment', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Weimaraner);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'large_home'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('frequent_grooming')->not->toContain('older_children')->not->toContain('chews_when_bored');

        expect(wmrData('weimaraner.suitability.rkc_exercise.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(wmrData('weimaraner.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(wmrData('weimaraner.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(wmrData('weimaraner.suitability.pdsa_family.quote'))->toBe('In the right hands, Weimaraners make wonderful family pets and can be very good natured dogs.')
            ->and(wmrData('weimaraner.suitability.pdsa_children.quote'))->toContain('smaller children')
            ->and(wmrData('weimaraner.behaviour.other_pets.quote'))->toContain('very strong prey drive')
            ->and(wmrData('weimaraner.behaviour.experienced_owner.quote'))->toContain('owners familiar with the breed')
            ->and(wmrData('weimaraner.behaviour.time_alone.quote'))->toBe('Weimaraners can develop separation anxiety.');
    });
});
