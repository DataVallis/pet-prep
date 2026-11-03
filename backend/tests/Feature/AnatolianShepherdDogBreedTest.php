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
| M5-R10-90 — Anatolian Shepherd Dog (appended after the Anatolian Shepherd Dog: AKC 2024 No. 86 "Anatolian Shepherd Dog")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `anatolian_shepherd_dog` (S682–S689) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.anatolian_shepherd_dog` (exercise 120 min — RKC "More than 2
| hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound 10 y), arrival
| 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing 1× a week (PetMD,
| M5-R13), puppy socialisation with the PetMD quote (M5-R17), the 60-minute meal / exercise warning (PetMD, M5-R16), daily
| tooth brushing (WebMD, M5-R15). Not a welfare breed (RKC Breed Watch Category 1): suits other_pets / large_home; consider
| long_daily_exercise / not_for_small_children / sheds; fawn with black mask portrait; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function asdData(string $path): mixed
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

/** @return array<string, mixed> the Anatolian Shepherd Dog seeder row of (stage, from, key) */
function asdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'anatolian-shepherd-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("anatolian-shepherd-dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function asdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->anatolianShepherdDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function asdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function asdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AnatolianShepherdDog;
        $config = BreedConfig::where('breed_slug', 'anatolian-shepherd-dog')->sole();

        expect($breed->value)->toBe('anatolian_shepherd_dog')
            ->and($breed->slug())->toBe('anatolian-shepherd-dog')
            ->and(BreedType::fromSlug('anatolian-shepherd-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 840, 'breeds.anatolian_shepherd_dog'])
            ->and($config->search_keywords)->toBe(['anatolian shepherd dog', 'anatolian shepherd', 'anatolian', 'kangal', 'kangal shepherd dog', 'karabash', 'anatolski ovčar', 'anatolski ovcar', 'anatolski pastirski pes', 'kangalski ovčar', 'kangalski ovcar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) asdData('proposed_game_parameters.anatolian_shepherd_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = asdData('proposed_game_parameters.anatolian_shepherd_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Anatolian Shepherd Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = asdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('anatolian_shepherd_dog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'kangal']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'anatolian-shepherd-dog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_06_120000_add_anatolian_shepherd_dog_breed.php');
        // down() refuses while an Anatolian Shepherd Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'anatolian_shepherd_dog']))->toThrow(QueryException::class);
        // The Anatolian Shepherd Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'irish_setter']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'anatolian_shepherd_dog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AnatolianShepherdDog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'anatolian-shepherd-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'anatolian-shepherd-dog')->count());

        BreedConfig::where('breed_slug', 'anatolian-shepherd-dog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'anatolian-shepherd-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'anatolian-shepherd-dog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'anatolian-shepherd-dog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'anatolian-shepherd-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'anatolian-shepherd-dog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ANATOLIAN_SHEPHERD_DOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_ANATOLIAN_SHEPHERD_DOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.anatolian_shepherd_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(asdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(asdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(asdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(asdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(asdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(asdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S685) = 90 — the runbook's third lifespan source.
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(asdData('anatolian_shepherd_dog.lifespan.senior_from.months'))
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(asdData("{$pgp}.senior_boundary_months.value"))
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * asdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(asdData('anatolian_shepherd_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S685'])
            ->and(asdData('anatolian_shepherd_dog.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S685')
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_ANATOLIAN_SHEPHERD_DOG)
            ->and(asdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(asdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(asdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(asdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(asdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(asdData('anatolian_shepherd_dog.exercise.adult.quote'))
            ->and(asdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S685')
            ->and(asdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(asdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(asdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(asdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(asdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(asdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(asdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(asdData("{$pgp}.learning_multiplier.value"))
            ->and(asdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ANATOLIAN_SHEPHERD_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('anatolian-shepherd-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.anatolian_shepherd_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) asdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 1× a week (M5-R13, PetMD), socialisation (M5-R17), meal gap (M5-R16, PetMD),
        // daily tooth brushing (M5-R15, WebMD). No summer walk warning (not flat-faced).
        expect(json_encode([asdData('anatolian_shepherd_dog'), asdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(asdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(asdData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(asdData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['anatolian_shepherd_dog'])->toBe(['S687', asdData('anatolian_shepherd_dog.exercise.after_meals.quote')])
            ->and(asdData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['anatolian_shepherd_dog'])->toBe(['S687', asdData('anatolian_shepherd_dog.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['anatolian_shepherd_dog'][1])->toBe(asdData("{$pgp}.socialisation.quote"))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['anatolian_shepherd_dog']))->toBe(['tooth_brushing'])
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('anatolian_shepherd_dog');

        // The care tasks quote their data.json entries verbatim.
        foreach (BreedStageParamsSeeder::DOG_CARE_TASKS['anatolian_shepherd_dog'] as $task => [$times, $period, $source, $quote, $ref]) {
            expect(asdData($ref)['quote'])->toBe($quote, $task)
                ->and(asdData($ref)['source_id'])->toBe($source, $task);
        }
        expect(array_map(fn (array $t) => [$t[0], $t[1]], BreedStageParamsSeeder::DOG_CARE_TASKS['anatolian_shepherd_dog']))
            ->toBe(['tooth_brushing' => [7, 'day']]);
    });

    it('takes the breed-level numbers from the sourced anatolian_shepherd_dog entries', function () {
        // AKC breed standard (S686): bitches 80–120 lb, dogs 110–150 lb → 36.3–68.0 kg.
        expect(asdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([36.3, 68.0])
            ->and(asdData('anatolian_shepherd_dog.adult_weight.akc.value'))->toEqual(['male' => [49.9, 68.0], 'female' => [36.3, 54.4]])
            ->and(asdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(asdData('anatolian_shepherd_dog.adult_weight.akc.source_id'))
            ->and(asdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(asdData('anatolian_shepherd_dog.adult_weight.akc.quote'))
            ->and(asdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(asdData('anatolian_shepherd_dog.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Not ranked by Coren → no rank, learning 1.0.
            ->and(asdRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(asdData('anatolian_shepherd_dog.trainability.coren_rank.value'))->toBeNull()
            ->and(asdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('anatolian_shepherd_dog.lifespan.rkc')
            ->and(asdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S685')
            ->and(asdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(asdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(asdData('anatolian_shepherd_dog.lifespan.rkc.quote'));

        // Brushing 1× a week: RKC "More than once a week" has no number → PetMD "weekly brushing", verified.
        $grooming = asdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S687')
            ->and($grooming['quote'])->toBe(asdData('anatolian_shepherd_dog.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(asdData('proposed_game_parameters.anatolian_shepherd_dog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and(BreedStageParamsSeeder::DOG_GROOMING['anatolian_shepherd_dog'][4])->toBeFalse()
            ->and(asdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(asdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S687')
            ->and(asdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'anatolian-shepherd-dog');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->pluck('value')->all())->toBe([60])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->sort()->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the breed's own socialisation quote (PetMD S687).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / exercise warning (PetMD S687).
            StageParamKey::MealExerciseGapMinutes,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            // M5-R19 / R20: the breed's care tasks (the Border Collie has none).
            ->reject(fn (array $r) => str_ends_with($r['key'], '_frequency') || $r['key'] === StageParamKey::NoWalkInHeat->value)
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('anatolian-shepherd-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = asdPet($arrival);
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

    it('becomes senior at 90 months, like the Anatolian Shepherd Dog', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('anatolian-shepherd-dog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('anatolian-shepherd-dog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('irish-setter', 90))->toBe(LifeStage::Senior);
    });

    it('learns like the mixed breed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = asdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'anatolian_shepherd_dog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = asdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(asdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AnatolianShepherdDog)
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
        foreach (['border_collie', 'anatolian_shepherd_dog'] as $breed) {
            $pin = asdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(asdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['anatolian_shepherd_dog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('anatolian_shepherd_dog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = asdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Anatolian Shepherd Dog after the Anatolian Shepherd Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'anatolian_shepherd_dog', 'irish_setter');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'anatolian_shepherd_dog', 'slug' => 'anatolian-shepherd-dog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.anatolian_shepherd_dog',
            'search_keywords' => seededBreedConfig('anatolian_shepherd_dog')['search_keywords'], 'sort_order' => 840,
            'suitability' => [
                'suits' => ['other_pets', 'large_home'],
                'consider' => ['long_daily_exercise', 'not_for_small_children', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S682 fawn to wolf sable with a black mask)', function () {
    it('samples DNA in the two standard colours only, always with the black mask', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('anatolian_shepherd_dog')
            ->and(PetDnaService::hasAppearance('anatolian_shepherd_dog'))->toBeTrue()
            ->and(config('breed_appearance.anatolian_shepherd_dog.verified'))->toBeFalse()
            ->and(config('breed_appearance.anatolian_shepherd_dog.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 100) as $seed) {
            $traits = $dna->sample('anatolian_shepherd_dog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;

            $prompt = $prompts->imagePrompt('anatolian_shepherd_dog', $traits);
            expect($prompt)->toContain('Anatolian Shepherd Dog')->toContain('black mask')
                ->not->toMatch('/\b(merle|brindle|blue eyes|cropped|docked|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $standard = array_column(config('breed_appearance.anatolian_shepherd_dog.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(2)
            ->and(collect(array_keys($colours))->sort()->values()->all())->toBe(collect($standard)->sort()->values()->all())
            ->and(array_column(config('breed_colours.anatolian_shepherd_dog'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.anatolian_shepherd_dog'), 'key'))->toBe(['fawn', 'wolf-sable'])
            ->and(asdData('anatolian_shepherd_dog.appearance.colours.value'))->toBe(['fawn with a black mask', 'wolf sable with a black mask']);
    });

    it('draws the register portrait fawn with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AnatolianShepherdDog);

        expect($traits['coat_color'])->toStartWith('one-coloured fawn with a black mask')
            ->and($portrait->prompt(BreedType::AnatolianShepherdDog))->toContain('Anatolian Shepherd Dog')->toContain('fawn')
            ->and(BreedPortraitService::relativeFile(BreedType::AnatolianShepherdDog))->toBe('dog/anatolian-shepherd-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AnatolianShepherdDog);
        expect($v1['prompt_anchor'])->toContain('Anatolian Shepherd Dog')->toContain('black mask')
            ->not->toMatch('/\b(merle|brindle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('fawn with a black mask');

        $pet = asdPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Anatolian Shepherd Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.anatolian_shepherd_dog'),
            config('breed_suitability.breeds.anatolian_shepherd_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'anatolian-shepherd-dog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', 'risk of'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AnatolianShepherdDog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->not->toContain('shorter_lifespan')->not->toContain('hips_hind_legs')
            ->and(asdData('proposed_game_parameters.anatolian_shepherd_dog.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (99 WebMD 11 y, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'anatolian-shepherd-dog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(99)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits other pets and a large home; consider exercise, small children and shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AnatolianShepherdDog);
        expect($tags['suits'])->toBe(['other_pets', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'not_for_small_children', 'sheds'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('apartment')
            ->and($tags['consider'])->not->toContain('often_alone')->not->toContain('chases_small_pets')->not->toContain('frequent_grooming');

        expect(asdData('anatolian_shepherd_dog.exercise.adult.quote'))->toBe('More than 2 hours per day')
            ->and(asdData('anatolian_shepherd_dog.behaviour.children.quote'))->toContain('without smaller children')
            ->and(asdData('anatolian_shepherd_dog.behaviour.other_pets.quote'))->toContain('introduced at a young age')
            ->and(asdData('anatolian_shepherd_dog.suitability.rkc_size_of_home.quote'))->toBe('Large house');
    });
});
