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
| M5-R10-106 — Borzoi (appended after the Bouvier des Flandres: AKC 2024 No. 102 "Borzoi")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `borzoi` (S800–S804) and the runbook's standing rules (confirmed by David
| 2026-10-10) in `proposed_game_parameters.borzoi` (exercise 60 min — PDSA "Minimum exercise (per day): 1 hour", the RKC
| page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning
| × 0.5 (Coren rank 75, Lowest), paid breed, Border Collie care rates, brushing 3× a week (PetMD "two to three times a
| week", M5-R13), the 60-min meal gap (PDSA quote, M5-R16), puppy socialisation (PDSA quote, M5-R17). Open for David: the
| senior boundary / senior arrival 90 (0.75 × 10 y, the PDSA band "Over 10 years" — Claude's proposal). Not a flat-faced
| breed → in marketing; no suits tag; consider frequent_grooming / not_for_small_children / experienced_owner /
| needs_company / sheds / chases_small_pets; white-and-red portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bzData(string $path): mixed
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

/** @return array<string, mixed> the Borzoi seeder row of (stage, from, key) */
function bzRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'borzoi'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("borzoi.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bzPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->borzoi()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bzPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bzLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Borzoi;
        $config = BreedConfig::where('breed_slug', 'borzoi')->sole();

        expect($breed->value)->toBe('borzoi')
            ->and($breed->slug())->toBe('borzoi')
            ->and(BreedType::fromSlug('borzoi'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 990, 'breeds.borzoi'])
            ->and($config->search_keywords)->toBe(['borzoi', 'russian hunting sighthound', 'ruski hrt', 'borzoj', 'ruski chrt'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bzData('proposed_game_parameters.borzoi.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bzData('proposed_game_parameters.borzoi.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Borzoi pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bzPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('borzoi');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'russian_wolfhound']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'ruski_hrt']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_27_120000_add_borzoi_breed.php');
        // down() refuses while a Borzoi exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'borzoi']))->toThrow(QueryException::class);
        // The Bouvier des Flandres (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bouvier_des_flandres']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'borzoi']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Borzoi);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'borzoi')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'borzoi')->count());

        BreedConfig::where('breed_slug', 'borzoi')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'borzoi')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'borzoi')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'borzoi');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'borzoi');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'borzoi'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BORZOI)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BORZOI_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_BORZOI', 'PROPOSED_R10_BORZOI_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.borzoi';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bzRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bzData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bzRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bzData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bzRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bzData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (the lower bound of the PDSA band "Over 10 years", S800) = 90 — the closest rule row,
            // Claude's suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bzData('borzoi.lifespan.senior_from.months'))
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bzData("{$pgp}.senior_boundary_months.value"))
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bzRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bzData('borzoi.lifespan.senior_from.derived_from'))->toBe(['S11', 'S800'])
            ->and(bzData('borzoi.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S800')
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BORZOI_SENIOR)
            ->and(bzData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BORZOI_SENIOR)
            ->and(bzRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(bzRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bzData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bzRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bzRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bzData('borzoi.suitability.pdsa_exercise.quote'))
            ->and(bzRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S800')
            ->and(bzRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bzRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bzData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bzRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(bzRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bzData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bzRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bzData("{$pgp}.learning_multiplier.value"))
            ->and(bzRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BORZOI) {
                $decided++;
                expect($row['breed_slug'])->toBe('borzoi')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.borzoi.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bzData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 3× a week (M5-R13, PetMD), the 60-min meal gap (M5-R16, PDSA quote), puppy
        // socialisation (M5-R17, PDSA quote). No tooth / ear / nail / bath / groomer task (no frequency), no heat warning
        // (not flat-faced).
        expect(json_encode([bzData('borzoi'), bzData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bzData("{$pgp}.grooming.value"))->toBe(3)
            ->and(bzData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['borzoi'])->toBe(['S800', bzData('borzoi.exercise.after_meals.quote')])
            ->and(bzData("{$pgp}.meal_exercise_gap.quote"))->toBe(bzData('borzoi.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['borzoi'])->toBe(['S800', bzData('borzoi.behaviour.socialisation.quote')])
            ->and(bzData("{$pgp}.socialisation.quote"))->toBe(bzData('borzoi.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('borzoi')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('borzoi');
    });

    it('takes the breed-level numbers from the sourced borzoi entries', function () {
        // PDSA (S800) "27-47 kg" for both sexes — the FCI standard gives no weight.
        expect(bzRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([27, 47])
            ->and(bzData('borzoi.adult_weight.pdsa.value'))->toEqual(['male' => [27, 47], 'female' => [27, 47]])
            ->and(bzRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bzData('borzoi.adult_weight.pdsa.source_id'))
            ->and(bzRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bzData('borzoi.adult_weight.pdsa.quote'))
            ->and(bzRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(bzData('borzoi.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 75 (Lowest) → learning 0.5.
            ->and(bzRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(75)
            ->and(bzData('borzoi.trainability.coren_rank.value'))->toBe(75)
            ->and(bzRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('borzoi.lifespan.pdsa')
            ->and(bzRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S800')
            ->and(bzRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(bzRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bzData('borzoi.lifespan.pdsa.quote'));

        // Brushing 3× a week: PDSA "a few times a week" has no number → PetMD "two to three times a week" → 3 (runbook rule, verified).
        $grooming = bzRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S803')
            ->and($grooming['quote'])->toBe(bzData('borzoi.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(bzData('proposed_game_parameters.borzoi.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(bzRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bzRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S800')
            ->and(bzRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(bzRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S800');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'borzoi');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BORZOI_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.borzoi.senior_boundary_months')
                ->and($row['value'])->toBe(bzData('proposed_game_parameters.borzoi.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: socialisation (PDSA S800 quote).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the 60-min meal gap (PDSA S800 quote).
            StageParamKey::MealExerciseGapMinutes,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('borzoi'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bzPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Keeshond', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('borzoi', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('borzoi', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('keeshond', 90))->toBe(LifeStage::Senior);
    });

    it('learns at 0.5 × the mixed-breed speed (Coren Lowest tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bzPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'borzoi', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bzPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bzLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Borzoi)
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
        foreach (['border_collie', 'borzoi'] as $breed) {
            $pin = bzPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bzLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['borzoi'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('borzoi')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bzPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Borzoi after the Bouvier des Flandres with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'borzoi', 'bouvier_des_flandres');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'borzoi', 'slug' => 'borzoi', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.borzoi',
            'search_keywords' => seededBreedConfig('borzoi')['search_keywords'], 'sort_order' => 990,
            'suitability' => [
                'suits' => [],
                'consider' => ['frequent_grooming', 'not_for_small_children', 'experienced_owner', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 193 S802)', function () {
    it('samples DNA in standard colours only, never brown, blue or lilac', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('borzoi')
            ->and(PetDnaService::hasAppearance('borzoi'))->toBeTrue()
            ->and(config('breed_appearance.borzoi.verified'))->toBeFalse()
            ->and(config('breed_appearance.borzoi.prompt_order'))->toContain('nose')->toContain('tail');

        $standard = array_column(config('breed_appearance.borzoi.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('borzoi', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color'])
                ->and($traits['tail'])->toContain('natural');

            $prompt = $prompts->imagePrompt('borzoi', $traits);
            expect($prompt)->toContain('Borzoi')
                ->not->toMatch('/\b(merle|chocolate|blue|lilac|isabella|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect($standard)->toHaveCount(7)
            ->and($standard[0])->toBe('white with red patches')
            ->and(array_column(config('breed_colours.borzoi'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.borzoi'), 'key'))->toBe(['white-red', 'white', 'red', 'sable', 'brindle', 'black-tan', 'black']);
    });

    it('draws the register portrait white with red patches', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Borzoi);

        expect($traits['coat_color'])->toBe('white with red patches')
            ->and($portrait->prompt(BreedType::Borzoi))->toContain('Borzoi')->toContain('white with red patches')->toContain('sabre')
            ->and(BreedPortraitService::relativeFile(BreedType::Borzoi))->toBe('dog/borzoi.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Borzoi);
        expect($v1['prompt_anchor'])->toContain('Borzoi')->toContain('white with red patches')
            ->not->toMatch('/\b(merle|chocolate|blue|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('white with red patches');

        $pet = bzPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Borzoi')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no flat-face chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.borzoi'),
            config('breed_suitability.breeds.borzoi'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'borzoi')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Borzoi);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced (FCI long narrow head, S802) → no breathing chip, no BVA marketing exclusion; Breed Watch Category 2
            // per PDSA without verbatim points → no welfare chip (open for David).
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(bzData('proposed_game_parameters.borzoi.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (108 McMillan via Wikipedia, 81 PetMD) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'borzoi')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(81);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('has no sourced suits tag; consider grooming, small children, experience, company, shedding and prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Borzoi);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'not_for_small_children', 'experienced_owner', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('hips_hind_legs')->not->toContain('long_daily_exercise');

        expect(bzData('borzoi.exercise.adult.quote'))->toBe('Minimum exercise (per day): 1 hour')
            ->and(bzData('borzoi.behaviour.children.quote'))->toContain('younger children')
            ->and(bzData('borzoi.behaviour.other_pets.quote'))->toContain('very high prey drive')
            ->and(bzData('borzoi.behaviour.experienced_owner.quote'))->toContain('experienced owners')
            ->and(bzData('borzoi.suitability.pdsa_shedding.quote'))->toStartWith('They do shed')
            ->and(bzData('borzoi.lifespan.pdsa.quote'))->toBe('Average lifespan: Over 10 years');
    });
});
