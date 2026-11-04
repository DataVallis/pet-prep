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
| M5-R10-92 — Chow Chow (appended after the Pekingese: AKC 2024 No. 88 "Chow Chow")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `chow_chow` (S696–S702) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.chow_chow` (exercise 60 min — PDSA "a minimum of one hour", the RKC page
| was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning × 0.5
| (Coren rank 76, Lowest), paid breed, Border Collie care rates, brushing every day (PDSA, M5-R13), puppy socialisation
| with the PDSA quote (M5-R17), the 60-minute meal / exercise gap warning (PDSA, M5-R16). No care task (no sourced
| frequency), no heat warning (not flat-faced). Open for David: the senior boundary / senior arrival 90 (0.75 × 10 y, the
| PDSA band "Under 10 years" — Claude's proposal). Not a flat-faced breed → in marketing; no suits chip; consider
| not_for_small_children / independent_experienced_owner / needs_company / needs_mental_stimulation / heat_sensitive /
| eyelid_problems / sensitive_skin / shorter_lifespan / sheds / frequent_grooming / chases_small_pets; red portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function chcData(string $path): mixed
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

/** @return array<string, mixed> the Chow Chow seeder row of (stage, from, key) */
function chcRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'chow-chow'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("chow-chow.{$stage}.{$from}.{$key->value}");

    return $row;
}

function chcPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->chowChow()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function chcPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function chcLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ChowChow;
        $config = BreedConfig::where('breed_slug', 'chow-chow')->sole();

        expect($breed->value)->toBe('chow_chow')
            ->and($breed->slug())->toBe('chow-chow')
            ->and(BreedType::fromSlug('chow-chow'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 860, 'breeds.chow_chow'])
            ->and($config->search_keywords)->toBe(['chow chow', 'chow', 'chow-chow', 'chowchow', 'čau čau', 'cau cau', 'čau-čau', 'čavčav', 'kitajski levji pes'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) chcData('proposed_game_parameters.chow_chow.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = chcData('proposed_game_parameters.chow_chow.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Chow Chow pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = chcPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('chow_chow');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'chow']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'chowchow']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_09_120000_add_chow_chow_breed.php');
        // down() refuses while a Chow Chow exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'chow_chow']))->toThrow(QueryException::class);
        // The Pekingese (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pekingese']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chow_chow']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ChowChow);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'chow-chow')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chow-chow')->count());

        BreedConfig::where('breed_slug', 'chow-chow')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'chow-chow')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'chow-chow')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'chow-chow');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chow-chow');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'chow-chow'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CHOW_CHOW)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_CHOW_CHOW_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_CHOW_CHOW', 'PROPOSED_R10_CHOW_CHOW_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.chow_chow';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(chcRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(chcData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(chcRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(chcData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(chcRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(chcData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (the only bound of the PDSA band "Under 10 years", S700) = 90 — the closest rule row,
            // Claude's suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(chcData('chow_chow.lifespan.senior_from.months'))
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(chcData("{$pgp}.senior_boundary_months.value"))
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * chcRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(chcData('chow_chow.lifespan.senior_from.derived_from'))->toBe(['S11', 'S700'])
            ->and(chcData('chow_chow.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S700')
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_CHOW_CHOW_SENIOR)
            ->and(chcData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_CHOW_CHOW_SENIOR)
            ->and(chcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(chcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(chcData("{$pgp}.exercise_minutes_adult.value"))
            ->and(chcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(chcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(chcData('chow_chow.suitability.pdsa_exercise.quote'))
            ->and(chcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S700')
            ->and(chcRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(chcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(chcData("{$pgp}.exercise_minutes_senior.value"))
            ->and(chcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(chcRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(chcData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(chcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(chcData("{$pgp}.learning_multiplier.value"))
            ->and(chcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CHOW_CHOW) {
                $decided++;
                expect($row['breed_slug'])->toBe('chow-chow')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.chow_chow.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) chcData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13), socialisation (M5-R17), the meal gap (M5-R16).
        // No care task (no tooth / ear / nail / bath / groomer / fold frequency), no heat warning (not flat-faced).
        expect(json_encode([chcData('chow_chow'), chcData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(chcData("{$pgp}.grooming.value"))->toBe(7)
            ->and(chcData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(chcData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(chcData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['chow_chow'])->toBe(['S700', chcData('chow_chow.exercise.after_meals.quote')])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['chow_chow'][1])->toBe(chcData("{$pgp}.meal_exercise_gap.quote"))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['chow_chow'])->toBe(['S700', chcData('chow_chow.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['chow_chow'][1])->toBe(chcData("{$pgp}.socialisation.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('chow_chow')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('chow_chow');
    });

    it('takes the breed-level numbers from the sourced chow_chow entries', function () {
        // PDSA (S700) "20-30 kg" for both sexes — the FCI / KC / AKC standards give no weight.
        expect(chcRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20, 30])
            ->and(chcData('chow_chow.adult_weight.pdsa.value'))->toEqual(['male' => [20, 30], 'female' => [20, 30]])
            ->and(chcRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(chcData('chow_chow.adult_weight.pdsa.source_id'))
            ->and(chcRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(chcData('chow_chow.adult_weight.pdsa.quote'))
            ->and(chcRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(chcData('chow_chow.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 76 (Lowest) → learning 0.5.
            ->and(chcRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(76)
            ->and(chcData('chow_chow.trainability.coren_rank.value'))->toBe(76)
            ->and(chcRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('chow_chow.lifespan.pdsa')
            ->and(chcRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S700')
            ->and(chcRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(chcRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(chcData('chow_chow.lifespan.pdsa.quote'));

        // Brushing every day: PDSA "brushing every day" → 7 (runbook rule, verified).
        $grooming = chcRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S700')
            ->and($grooming['quote'])->toBe(chcData('chow_chow.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(chcData('proposed_game_parameters.chow_chow.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(chcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(chcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S700')
            ->and(chcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chow-chow');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(1)
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->first()['value'])->toBe(60)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_CHOW_CHOW_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.chow_chow.senior_boundary_months')
                ->and($row['value'])->toBe(chcData('proposed_game_parameters.chow_chow.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the breed's own socialisation quote (PDSA S700).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / exercise gap (PDSA S700; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('chow-chow'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = chcPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Anatolian Shepherd Dog and 18 months before the Pekingese (108)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('chow-chow', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('chow-chow', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('anatolian-shepherd-dog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pekingese', 107))->toBe(LifeStage::Adult);
    });

    it('learns at half the mixed-breed speed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = chcPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'chow_chow', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = chcPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(chcLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ChowChow)
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
        foreach (['border_collie', 'chow_chow'] as $breed) {
            $pin = chcPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(chcLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['chow_chow'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('chow_chow')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = chcPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Chow Chow after the Pekingese with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'chow_chow', 'pekingese');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'chow_chow', 'slug' => 'chow-chow', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.chow_chow',
            'search_keywords' => seededBreedConfig('chow_chow')['search_keywords'], 'sort_order' => 860,
            'suitability' => [
                'suits' => [],
                'consider' => ['not_for_small_children', 'independent_experienced_owner', 'needs_company', 'needs_mental_stimulation', 'heat_sensitive', 'eyelid_problems', 'sensitive_skin', 'shorter_lifespan', 'sheds', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard whole colours only — FCI-Standard N° 205 S696)', function () {
    it('samples DNA in the six colours of the appearance block only, never patches or merle, with a moderate muzzle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('chow_chow')
            ->and(PetDnaService::hasAppearance('chow_chow'))->toBeTrue()
            ->and(config('breed_appearance.chow_chow.verified'))->toBeFalse()
            ->and(config('breed_appearance.chow_chow.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('chow_chow', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('moderate length')
                ->and($traits['eye_color'])->toContain('clean and open');

            $prompt = $prompts->imagePrompt('chow_chow', $traits);
            expect($prompt)->toContain('Chow Chow')
                ->not->toMatch('/\b(merle|parti|brindle|cropped|docked|patches)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The six colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.chow_chow.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(6)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.chow_chow'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.chow_chow'), 'key'))->toBe(['red', 'black', 'cream', 'fawn', 'blue', 'white'])
            ->and(chcData('chow_chow.appearance.colours.value'))->toBe(['red', 'black', 'cream', 'fawn', 'blue', 'white']);
    });

    it('draws the register portrait solid red', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ChowChow);

        expect($traits['coat_color'])->toBe('solid red, light golden to deep red, frequently shaded')
            ->and($portrait->prompt(BreedType::ChowChow))->toContain('Chow Chow')->toContain('solid red')->toContain('moderate length')
            ->and(BreedPortraitService::relativeFile(BreedType::ChowChow))->toBe('dog/chow-chow.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ChowChow);
        expect($v1['prompt_anchor'])->toContain('Chow Chow')->toContain('moderate length')
            ->not->toMatch('/\b(merle|parti|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid red, light golden to deep red', 'solid black']);

        $pet = chcPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Chow Chow')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no flat-face chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.chow_chow'),
            config('breed_suitability.breeds.chow_chow'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chow-chow')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%', '48.6', '6.44'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ChowChow);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced (FCI muzzle "Moderate in length", S696) → no breathing chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(chcData('proposed_game_parameters.chow_chow.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (109 via Wikipedia, 84 Dogs Trust, 72 PetMD) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chow-chow')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(109)->not->toContain(84)->not->toContain(72);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('has no suits chip; consider small children, experienced owner, company, stimulation, heat, eyelids, folds, lifespan, shedding, grooming and prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ChowChow);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['not_for_small_children', 'independent_experienced_owner', 'needs_company', 'needs_mental_stimulation', 'heat_sensitive', 'eyelid_problems', 'sensitive_skin', 'shorter_lifespan', 'sheds', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('older_children')->not->toContain('experienced_owner')->not->toContain('long_daily_exercise');

        expect(chcData('chow_chow.exercise.adult.quote'))->toContain('one hour')
            ->and(chcData('chow_chow.behaviour.children.quote'))->toContain('small children')
            ->and(chcData('chow_chow.behaviour.other_pets.quote'))->toContain('prey drive')
            ->and(chcData('chow_chow.behaviour.time_alone.quote'))->toContain('may not tolerate being alone')
            ->and(chcData('chow_chow.lifespan.pdsa.quote'))->toBe('Under 10 years');
    });
});
