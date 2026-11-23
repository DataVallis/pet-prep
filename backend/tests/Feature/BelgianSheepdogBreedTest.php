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
| M5-R10-135 — Belgian Sheepdog / Groenendael (appended after the Norfolk Terrier: AKC 2024 No. 131 "Belgian Sheepdog")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `belgian_sheepdog` (S1019–S1021, reused S313, S831, S35) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.belgian_sheepdog` (exercise 120 min — RKC
| "More than 2 hours per day" —, senior 90 min, puppy / young 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC
| lower bound 10 y, S1019 — provisional like the Belgian Malinois / Tervuren), arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren
| rank 15, Excellent), paid breed, Border Collie care rates, brushing 1× a week (RKC "More than once a week" → PetMD "weekly",
| M5-R13), tooth brushing every day (WebMD S1021, M5-R15), ear cleaning once a month (PetMD S1020, M5-R18) and a bath
| reminder every 4 weeks (WebMD S1021, M5-R18), puppy socialisation with the PetMD quote (S1020, M5-R17), no meal gap, no
| heat warning. Not a welfare breed (RKC Breed Watch Category 1) → in marketing; solid black portrait, one colour.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bsdData(string $path): mixed
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

/** @return array<string, mixed> the Belgian Sheepdog seeder row of (stage, from, key) */
function bsdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'belgian-sheepdog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("belgian-sheepdog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bsdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->belgianSheepdog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bsdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bsdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BelgianSheepdog;
        $config = BreedConfig::where('breed_slug', 'belgian-sheepdog')->sole();

        expect($breed->value)->toBe('belgian_sheepdog')
            ->and($breed->slug())->toBe('belgian-sheepdog')
            ->and(BreedType::fromSlug('belgian-sheepdog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1260, 'breeds.belgian_sheepdog'])
            ->and($config->search_keywords)->toBe(['belgian sheepdog', 'groenendael', 'belgijski ovčar groenendael', 'belgijski ovcar groenendael', 'belgian shepherd groenendael', 'belgian shepherd dog groenendael', 'grunendal'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bsdData('proposed_game_parameters.belgian_sheepdog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bsdData('proposed_game_parameters.belgian_sheepdog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Belgian Sheepdog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bsdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('belgian_sheepdog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'groenendael']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'belgian_shepherd']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_04_120000_add_belgian_sheepdog_breed.php');
        // down() refuses while a Belgian Sheepdog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'belgian_sheepdog']))->toThrow(QueryException::class);
        // The Norfolk Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'norfolk_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_sheepdog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BelgianSheepdog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'belgian-sheepdog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-sheepdog')->count());

        BreedConfig::where('breed_slug', 'belgian-sheepdog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'belgian-sheepdog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'belgian-sheepdog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'belgian-sheepdog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-sheepdog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'belgian-sheepdog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_SHEEPDOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BELGIAN_SHEEPDOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.belgian_sheepdog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bsdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bsdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bsdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bsdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bsdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bsdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 10 y × 12) = 90 (RKC lower bound, S1019) → runbook rule, verified (provisional, open for David).
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bsdData('belgian_sheepdog.lifespan.senior_from.months'))
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bsdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bsdData('belgian_sheepdog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1019'])
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', bsdData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_SHEEPDOG)
            ->and(bsdRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(bsdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90)
            // PetMD / WebMD (108) and Dogs Trust's 7 y stay alternatives.
            ->and(bsdData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_petmd_12_y' => 108, 'senior_dogs_trust_7_y' => 84]);

        expect(bsdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bsdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bsdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(bsdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bsdData('belgian_sheepdog.exercise.adult.quote'))
            ->and(bsdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bsdData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(bsdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1019')
            ->and(bsdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bsdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bsdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(bsdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bsdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(bsdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bsdData("{$pgp}.learning_multiplier.value"))
            ->and(bsdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_SHEEPDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('belgian-sheepdog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.belgian_sheepdog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bsdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 1× a week (M5-R13), tooth brushing every day (WebMD S1021, M5-R15), ear
        // cleaning once a month (PetMD S1020) and a bath reminder every 4 weeks (WebMD S1021, M5-R18), puppy socialisation
        // with the PetMD quote (M5-R17). No meal gap and no heat warning (no quote), no nail / groomer task (no frequency).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['belgian_sheepdog'];
        expect(json_encode([bsdData('belgian_sheepdog'), bsdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bsdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bsdData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('belgian_sheepdog')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('belgian_sheepdog')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['belgian_sheepdog'])->toBe(['S1020', bsdData("{$pgp}.socialisation.quote")])
            ->and(bsdData("{$pgp}.socialisation.quote"))->toBe(bsdData('belgian_sheepdog.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath'])
            ->and([$tasks['tooth_brushing'][0], $tasks['tooth_brushing'][1]])->toBe([7, 'day'])
            ->and($tasks['tooth_brushing'][3])->toBe(bsdData("{$pgp}.tooth_brushing.quote"))
            ->and(bsdData("{$pgp}.tooth_brushing.value"))->toBe([7, 7])
            ->and(bsdData("{$pgp}.tooth_brushing.quote"))->toBe(bsdData('belgian_sheepdog.suitability.webmd_teeth.quote'))
            ->and([$tasks['ear_cleaning'][0], $tasks['ear_cleaning'][1]])->toBe([1, 'month'])
            ->and([$tasks['bath'][0], $tasks['bath'][1]])->toBe([1, 'month'])
            ->and($tasks['ear_cleaning'][3])->toBe(bsdData("{$pgp}.ear_care.quote"))
            ->and($tasks['bath'][3])->toBe(bsdData("{$pgp}.bathing.quote"))
            ->and(bsdData("{$pgp}.ear_care.value"))->toBe([1, 28])
            ->and(bsdData("{$pgp}.bathing.value"))->toBe([1, 28])
            ->and(bsdData("{$pgp}.ear_care.quote"))->toBe(bsdData('belgian_sheepdog.suitability.petmd_ears.quote'))
            ->and(bsdData("{$pgp}.bathing.quote"))->toBe(bsdData('belgian_sheepdog.suitability.webmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced belgian_sheepdog entries', function () {
        // FCI standard No. 15 (S313): males about 25–30 kg, females about 20–25 kg → 20–30 kg.
        expect(bsdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.0, 30.0])
            ->and(bsdData('belgian_sheepdog.adult_weight.fci.value'))->toEqual(['male' => [25, 30], 'female' => [20, 25]])
            ->and(bsdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bsdData('belgian_sheepdog.adult_weight.fci.source_id'))
            ->and(bsdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bsdData('belgian_sheepdog.adult_weight.fci.quote'))
            ->and(bsdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(bsdData('belgian_sheepdog.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(bsdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(15)
            ->and(bsdData('belgian_sheepdog.trainability.coren_rank.value'))->toBe(15)
            ->and(bsdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('belgian_sheepdog.lifespan.rkc')
            ->and(bsdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S1019')
            ->and(bsdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(bsdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bsdData('belgian_sheepdog.lifespan.rkc.quote'));

        // Brushing 1× a week: RKC "More than once a week" has no number → PetMD "weekly" → 1.
        $grooming = bsdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S1020')
            ->and($grooming['quote'])->toBe(bsdData('belgian_sheepdog.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(bsdData('proposed_game_parameters.belgian_sheepdog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(bsdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bsdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1020')
            ->and(bsdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-sheepdog');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::ToothBrushingFrequency->value, StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S1020 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        // Care-task frequency rows are breed-specific and skipped (teeth, ears, bath).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('belgian-sheepdog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bsdPet($arrival);
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

        expect($service->stageForAge('belgian-sheepdog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('belgian-sheepdog', 90))->toBe(LifeStage::Senior);
    });

    it('learns 1.5× as fast (Coren rank 15, Excellent) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bsdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'belgian_sheepdog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bsdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bsdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BelgianSheepdog)
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
        foreach (['border_collie', 'belgian_sheepdog'] as $breed) {
            $pin = bsdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bsdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['belgian_sheepdog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('belgian_sheepdog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bsdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Belgian Sheepdog after the Norfolk Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'belgian_sheepdog', 'norfolk_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'belgian_sheepdog', 'slug' => 'belgian-sheepdog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.belgian_sheepdog',
            'search_keywords' => seededBreedConfig('belgian_sheepdog')['search_keywords'], 'sort_order' => 1260,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI standard No. 15, S831)', function () {
    it('samples DNA in uniform black only (the one Groenendael colour), never fawn, grey, brindle or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('belgian_sheepdog')
            ->and(PetDnaService::hasAppearance('belgian_sheepdog'))->toBeTrue()
            ->and(config('breed_appearance.belgian_sheepdog.verified'))->toBeFalse()
            ->and(config('breed_appearance.belgian_sheepdog.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.belgian_sheepdog.traits.coat_color'));
        expect($standard)->toBe(['black'])
            ->and(bsdData('belgian_sheepdog.appearance.colours.value'))->toBe(['black'])
            ->and(bsdData('belgian_sheepdog.appearance.colours.quote'))->toBe('Only uniform black.');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('belgian_sheepdog', $seed)['traits'];
            expect($traits['coat_color'])->toBe('black')
                ->and($traits['ear_carriage'])->toContain('erect');

            $prompt = $prompts->imagePrompt('belgian_sheepdog', $traits);
            expect($prompt)->toContain('Belgian Sheepdog')->toContain('erect')
                ->not->toMatch('/\b(merle|tricolou?r|brindle|fawn|grey|white patches|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.belgian_sheepdog');
        expect(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black']);
    });

    it('draws the register portrait solid black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BelgianSheepdog);

        expect($traits['coat_color'])->toBe('black')
            ->and($portrait->prompt(BreedType::BelgianSheepdog))->toContain('Belgian Sheepdog')->toContain('black')->toContain('erect')
            ->and(BreedPortraitService::relativeFile(BreedType::BelgianSheepdog))->toBe('dog/belgian-sheepdog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BelgianSheepdog);
        expect($v1['prompt_anchor'])->toContain('Belgian Sheepdog')->toContain('uniformly black coat')->toContain('erect')
            ->not->toMatch('/\b(merle|brindle|fawn)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black');

        $pet = bsdPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Belgian Sheepdog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and keeps the breed in marketing', function () {
        $app = json_encode([
            config('breed_appearance.belgian_sheepdog'),
            config('breed_suitability.breeds.belgian_sheepdog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-sheepdog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BelgianSheepdog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced; RKC Breed Watch Category 1 → no welfare chip.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(bsdData('belgian_sheepdog.health.breed_watch.value'))->toStartWith('RKC Breed Watch Category 1')
            ->and(bsdData('proposed_game_parameters.belgian_sheepdog.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            // The alternatives (108, 84) are never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-sheepdog')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([90]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family and a large home; consider long exercise, herding children, mental stimulation, company, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BelgianSheepdog);
        expect($tags['suits'])->toBe(['active_family', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('apartment')->not->toContain('family_pet')->not->toContain('children')
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan');

        expect(bsdData('belgian_sheepdog.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(bsdData('belgian_sheepdog.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(bsdData('belgian_sheepdog.suitability.rkc_grooming.quote'))->toBe('More than once a week')
            ->and(bsdData('belgian_sheepdog.suitability.rkc_lifespan.quote'))->toBe('Over 10 years')
            ->and(bsdData('belgian_sheepdog.behaviour.time_alone.quote'))->toContain('away from them for long hours')
            // Herding, not a "high prey drive" → no chases_small_pets (runbook); children conflicting → no chip.
            ->and(bsdData('belgian_sheepdog.behaviour.other_pets.quote'))->not->toContain('prey drive')
            ->and(bsdData('proposed_game_parameters.belgian_sheepdog.suitability_tags.not_proposed.children'))->toStartWith('Conflicting');
    });
});
