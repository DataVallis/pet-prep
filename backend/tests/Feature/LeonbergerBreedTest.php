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
| M5-R10-103 — Leonberger (appended after the Leonberger: AKC 2024 No. 99 "Leonberger")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `leonberger` (S780–S786) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.leonberger` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy / young 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × McMillan 2024
| median 10.0 y via Dogs Trust S123 — not provisional), arrival 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren),
| paid breed, Border Collie care rates, brushing 3× a week (Woodgreen "two to three times a week", M5-R13), meal / walk
| gap (PetMD S785, M5-R16), puppy socialisation with the Woodgreen quote (S784, M5-R17), ear cleaning and a bath reminder
| every 2 weeks (PetMD S785, M5-R18 / R20). RKC Breed Watch Category 2 without points → no welfare chip, in marketing;
| lion yellow portrait with a black mask.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function leoData(string $path): mixed
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

/** @return array<string, mixed> the Leonberger seeder row of (stage, from, key) */
function leoRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'leonberger'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("leonberger.{$stage}.{$from}.{$key->value}");

    return $row;
}

function leoPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->leonberger()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function leoPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function leoLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Leonberger;
        $config = BreedConfig::where('breed_slug', 'leonberger')->sole();

        expect($breed->value)->toBe('leonberger')
            ->and($breed->slug())->toBe('leonberger')
            ->and(BreedType::fromSlug('leonberger'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 960, 'breeds.leonberger'])
            ->and($config->search_keywords)->toBe(['leonberger', 'leonberg', 'leo', 'leonberški pes', 'leonberski pes'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) leoData('proposed_game_parameters.leonberger.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = leoData('proposed_game_parameters.leonberger.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Leonberger pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = leoPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('leonberger');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'leonberg']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'leonberski_pes']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_23_120000_add_leonberger_breed.php');
        // down() refuses while a Leonberger exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'leonberger']))->toThrow(QueryException::class);
        // The Border Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'leonberger']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Leonberger);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'leonberger')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'leonberger')->count());

        BreedConfig::where('breed_slug', 'leonberger')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'leonberger')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'leonberger')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'leonberger');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'leonberger');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'leonberger'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_LEONBERGER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_LEONBERGER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.leonberger';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(leoRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(leoData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(leoRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(leoData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(leoRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(leoData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 10.0 y × 12) = 90 (McMillan 2024 via Dogs Trust, S123) → not provisional.
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(leoData('leonberger.lifespan.senior_from.months'))
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * leoRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(leoData('leonberger.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', leoData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_LEONBERGER)
            ->and(leoRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(leoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90)
            // The Woodgreen / PetMD lower bounds (63) stay alternatives.
            ->and(leoData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_woodgreen_lower_bound_7_y' => 63, 'senior_petmd_7_y' => 63]);

        expect(leoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(leoData("{$pgp}.exercise_minutes_adult.value"))
            ->and(leoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(leoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(leoData('leonberger.exercise.adult.quote'))
            ->and(leoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(leoData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(leoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S780')
            ->and(leoRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(leoData("{$pgp}.exercise_minutes_senior.value"))
            ->and(leoRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(leoRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(leoData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(leoRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(leoData("{$pgp}.learning_multiplier.value"))
            ->and(leoRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_LEONBERGER) {
                $decided++;
                expect($row['breed_slug'])->toBe('leonberger')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.leonberger.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) leoData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 3× a week (M5-R13, Woodgreen), the meal / walk gap (M5-R16, PetMD), puppy
        // socialisation with the Woodgreen quote (M5-R17), ear cleaning + bath reminder every 2 weeks (M5-R20 / R18, PetMD).
        expect(json_encode([leoData('leonberger'), leoData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(leoData("{$pgp}.grooming.value"))->toBe(3)
            ->and(leoData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['leonberger'])->toBe(['S785', leoData("{$pgp}.meal_exercise_gap.quote")])
            ->and(leoData("{$pgp}.meal_exercise_gap.quote"))->toBe(leoData('leonberger.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['leonberger'])->toBe(['S784', leoData("{$pgp}.socialisation.quote")])
            ->and(leoData("{$pgp}.socialisation.quote"))->toBe(leoData('leonberger.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('leonberger')
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']))->toBe(['ear_cleaning', 'bath'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']['ear_cleaning'][0])->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']['ear_cleaning'][1])->toBe('two_weeks')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']['ear_cleaning'][3])->toBe(leoData("{$pgp}.ear_care.quote"))
            ->and(leoData("{$pgp}.ear_care.quote"))->toBe(leoData('leonberger.suitability.petmd_ears.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']['bath'][1])->toBe('two_weeks')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['leonberger']['bath'][3])->toBe(leoData("{$pgp}.bathing.quote"))
            ->and(leoData("{$pgp}.bathing.quote"))->toBe(leoData('leonberger.suitability.petmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced leonberger entries', function () {
        // PDSA weight 30–50 kg for both sexes (FCI / AKC give none).
        expect(leoRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([30.0, 50.0])
            ->and(leoData('leonberger.adult_weight.pdsa.value'))->toEqual(['male' => [30, 50], 'female' => [30, 50]])
            ->and(leoRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(leoData('leonberger.adult_weight.pdsa.source_id'))
            ->and(leoRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(leoData('leonberger.adult_weight.pdsa.quote'))
            ->and(leoRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(leoData('leonberger.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            ->and(leoRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(leoData('leonberger.trainability.coren_rank.value'))->toBeNull()
            ->and(leoRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('leonberger.lifespan.median_uk')
            ->and(leoRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(leoRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(leoRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(leoData('leonberger.lifespan.median_uk.quote'));

        // Brushing 3× a week: Woodgreen "two to three times a week" → the upper bound (RKC 1, PDSA 2, PetMD 7 alternatives).
        $grooming = leoRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S784')
            ->and($grooming['quote'])->toBe(leoData('leonberger.suitability.woodgreen_grooming.quote'))
            ->and($grooming['quote'])->toBe(leoData('proposed_game_parameters.leonberger.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(leoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(leoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S784')
            ->and(leoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'leonberger');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap->count())->toBe(1)
            ->and($gap[0]['value'])->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP_MINUTES)
            ->and($gap[0]['source_id'])->toBe('S785')
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe([StageParamKey::BathFrequency->value, StageParamKey::EarCleaningFrequency->value])
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
            // M5-R17: socialisation (Woodgreen S784 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / walk gap (PetMD S785).
            StageParamKey::MealExerciseGapMinutes,
        ];
        // The care-task frequency rows (ear cleaning, bath) are breed-specific and skipped.
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('leonberger'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = leoPet($arrival);
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

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('leonberger', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('leonberger', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (1.0 — not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = leoPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'leonberger', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = leoPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(leoLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Leonberger)
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
        foreach (['border_collie', 'leonberger'] as $breed) {
            $pin = leoPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(leoLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['leonberger'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('leonberger')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = leoPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Leonberger after the Border Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'leonberger', 'border_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'leonberger', 'slug' => 'leonberger', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.leonberger',
            'search_keywords' => seededBreedConfig('leonberger')['search_keywords'], 'sort_order' => 960,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home'],
                'consider' => ['shorter_lifespan', 'long_daily_exercise', 'sheds', 'frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 145 S783)', function () {
    it('samples DNA in the four standard colours only, always with a black mask, never merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('leonberger')
            ->and(PetDnaService::hasAppearance('leonberger'))->toBeTrue()
            ->and(config('breed_appearance.leonberger.verified'))->toBeFalse()
            ->and(config('breed_appearance.leonberger.prompt_order'))->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.leonberger.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('leonberger', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('leonberger', $traits);
            expect($prompt)->toContain('Leonberger')
                ->not->toMatch('/\b(merle|brindle|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.leonberger');
        expect($standard)->toBe(['lion yellow with a black mask', 'red with a black mask', 'reddish brown with a black mask', 'sandy cream with a black mask'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['lion-yellow', 'red', 'red-brown', 'sandy'])
            // Lion yellow carries the highest weight → the portrait default.
            ->and(config('breed_appearance.leonberger.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.leonberger.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in lion yellow with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Leonberger);

        expect($traits['coat_color'])->toBe('lion yellow with a black mask')
            ->and($portrait->prompt(BreedType::Leonberger))->toContain('Leonberger')->toContain('lion yellow with a black mask')
            ->and(BreedPortraitService::relativeFile(BreedType::Leonberger))->toBe('dog/leonberger.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Leonberger);
        expect($v1['prompt_anchor'])->toContain('Leonberger')->toContain('lion yellow with a black mask')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('lion yellow with a black mask');

        $pet = leoPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Leonberger')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and stays in marketing (not a welfare breed)', function () {
        $app = json_encode([
            config('breed_appearance.leonberger'),
            config('breed_suitability.breeds.leonberger'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'leonberger')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '50%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Leonberger);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 2 without points (S780) → no welfare chip, in marketing.
            ->and($tags['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(leoData('leonberger.health.breed_watch.quote'))->toBe('Category 2')
            ->and(leoData('proposed_game_parameters.leonberger.marketing.value'))->toStartWith('in the app: yes; in marketing: yes')
            // The alternative (63, Woodgreen / PetMD lower bound) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'leonberger')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(63);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family, a family, children and a large home; consider a shorter life, long walks, shedding, grooming and company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Leonberger);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['shorter_lifespan', 'long_daily_exercise', 'sheds', 'frequent_grooming', 'needs_company'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('experienced_owner')->not->toContain('not_for_small_children');

        expect(leoData('leonberger.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(leoData('leonberger.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(leoData('leonberger.lifespan.rkc.quote'))->toBe('Lifespan: Under 10 years')
            ->and(leoData('leonberger.behaviour.other_pets.quote'))->not->toContain('prey drive')
            ->and(leoData('leonberger.behaviour.children.quote'))->toContain('friendliness towards children');
    });
});
