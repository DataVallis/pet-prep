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
| M5-R10-147 — Icelandic Sheepdog (appended after the Barbet: AKC 2024 No. 143)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `icelandic_sheepdog` (S1100–S1109) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.icelandic_sheepdog` (exercise 40 min — Dogs NSW "40 min/day",
| the RKC page was not reachable —, senior 30 min, puppy 10 min × age capped at 40, stages 9 / 36 / 108 (0.75 × WebMD lower
| bound "12 to 14 years"), arrival 2 / 9 / 36 / 108, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care
| rates, brushing 1× a week (PetMD "at least once a week", M5-R13), tooth brushing every day (WebMD, M5-R15), ear cleaning
| every 4 weeks (PetMD "once a month", M5-R18), bath every 4 weeks (WebMD "every 4 to 6 weeks", M5-R20), puppy socialisation
| with the PetMD quote (S1101, M5-R17); no nail / groomer task, no meal gap, no heat walk ban. No welfare chip, in marketing;
| suits active_family, family_pet, children; consider needs_company, sheds, secure_fencing; red and white portrait (seven
| FCI colours, always with white markings).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function iceData(string $path): mixed
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

/** @return array<string, mixed> the Icelandic Sheepdog seeder row of (stage, from, key) */
function iceRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'icelandic-sheepdog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("icelandic_sheepdog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function icePet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->icelandicSheepdog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function icePin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function iceLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::IcelandicSheepdog;
        $config = BreedConfig::where('breed_slug', 'icelandic-sheepdog')->sole();

        expect($breed->value)->toBe('icelandic_sheepdog')
            ->and($breed->slug())->toBe('icelandic-sheepdog')
            ->and(BreedType::fromSlug('icelandic-sheepdog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1380, 'breeds.icelandic_sheepdog'])
            ->and($config->search_keywords)->toBe(['icelandic sheepdog', 'islandski ovčar', 'islandski ovcar', 'icelandic spitz', 'iceland dog', 'islandski špic', 'íslenskur fjárhundur'])
            // Legacy fallback = the adult step goal (40 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) iceData('proposed_game_parameters.icelandic_sheepdog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(4000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts an Icelandic Sheepdog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = icePet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('icelandic_sheepdog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'icelandic_sheepdogs']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_18_120000_add_icelandic_sheepdog_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'icelandic_sheepdog']))->toThrow(QueryException::class);
        // The Barbet (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'barbet']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'icelandic_sheepdog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::IcelandicSheepdog)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'icelandic-sheepdog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog')->count());

        BreedConfig::where('breed_slug', 'icelandic-sheepdog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'icelandic-sheepdog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'icelandic-sheepdog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'icelandic-sheepdog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'icelandic-sheepdog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ICELANDIC_SHEEPDOG)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_ICELANDIC_SHEEPDOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.icelandic_sheepdog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(iceRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(iceData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(iceRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(iceData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(iceRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(iceData('icelandic_sheepdog.lifespan.senior_from.months'))
            ->and(iceRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * iceRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(iceData('icelandic_sheepdog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1102'])
            ->and(iceData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(iceRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', iceData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(iceRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_ICELANDIC_SHEEPDOG)
            ->and(iceRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(iceRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(iceData("{$pgp}.exercise_minutes_adult.value"))
            ->and(iceRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(40)
            ->and(iceRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(iceData('icelandic_sheepdog.exercise.adult.quote'))
            ->and(iceRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1104')
            ->and(iceRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(40)
            ->and(iceRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(iceData("{$pgp}.exercise_minutes_senior.value"))
            ->and(iceRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(iceRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(iceRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ICELANDIC_SHEEPDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('icelandic-sheepdog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.icelandic_sheepdog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) iceData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 1× a week (PetMD "at least once a week", M5-R13), tooth
        // brushing every day (WebMD, M5-R15), ear cleaning every 4 weeks (PetMD "once a month", M5-R18), bath every 4 weeks
        // (WebMD "every 4 to 6 weeks", M5-R20), puppy socialisation (PetMD, M5-R17). No nail task (no frequency), no groomer
        // (PetMD: not needed), no meal gap (no waiting quote).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['icelandic_sheepdog'];
        expect(json_encode([iceData('icelandic_sheepdog'), iceData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(iceData("{$pgp}.grooming.value"))->toBe(1)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('icelandic_sheepdog')
            ->and(iceData('icelandic_sheepdog.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['icelandic_sheepdog'])->toBe(['S1101', iceData("{$pgp}.socialisation.quote")])
            ->and(iceData("{$pgp}.socialisation.quote"))->toBe(iceData('icelandic_sheepdog.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1102', iceData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(iceData("{$pgp}.tooth_brushing.quote"))->toBe(iceData('icelandic_sheepdog.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'month', 'S1101', iceData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(iceData("{$pgp}.ear_care.quote"))->toBe(iceData('icelandic_sheepdog.suitability.petmd_ears.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S1102', iceData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(iceData("{$pgp}.bathing.quote"))->toBe(iceData('icelandic_sheepdog.suitability.webmd_bath.quote'))
            ->and(iceData('icelandic_sheepdog.suitability.webmd_nails.quote'))->toContain('before they\'re clicking')
            ->and(iceData('icelandic_sheepdog.suitability.petmd_groomer.quote'))->toContain('won’t be needed')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('icelandic_sheepdog');
    });

    it('takes the breed-level numbers from the sourced icelandic_sheepdog entries', function () {
        expect(iceRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([11.3, 13.6])
            ->and(iceData('icelandic_sheepdog.adult_weight.webmd.value'))->toEqual(['male' => [11.3, 13.6], 'female' => [11.3, 13.6]])
            ->and(iceRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(iceData('icelandic_sheepdog.adult_weight.webmd.quote'))
            ->and(iceRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe('S1102')
            ->and(iceRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(iceRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(iceData('icelandic_sheepdog.trainability.coren_rank.value'))
            ->and(iceRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(iceData('icelandic_sheepdog.trainability.coren_tier.value'))->toBeNull()
            ->and(iceRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('icelandic_sheepdog.lifespan.webmd')
            ->and(iceRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(iceRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(iceData('icelandic_sheepdog.lifespan.webmd.quote'));

        $grooming = iceRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S1101')
            ->and($grooming['quote'])->toBe(iceData('icelandic_sheepdog.suitability.petmd_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(iceRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1101')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::EarCleaningFrequency->value => [1, 28],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek,
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('icelandic-sheepdog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 40 minutes = 4,000 steps and 30 minutes = 3,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = icePet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 4 months (reaches the adult 40)' => [4, LifeStage::Puppy, 40, 4000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 40, 4000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 40, 4000],
        'adult 36 months' => [36, LifeStage::Adult, 40, 4000],
        'adult 107 months' => [107, LifeStage::Adult, 40, 4000],
        'senior 108 months' => [108, LifeStage::Senior, 30, 3000],
    ]);

    it('becomes senior at 108 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('icelandic-sheepdog', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('icelandic-sheepdog', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed breed\'s speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = icePet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'icelandic_sheepdog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = icePin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(iceLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::IcelandicSheepdog)
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
        foreach (['border_collie', 'icelandic_sheepdog'] as $breed) {
            $pin = icePin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(iceLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['icelandic_sheepdog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('icelandic_sheepdog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = icePet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Icelandic Sheepdog after the Barbet with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'icelandic_sheepdog', 'barbet');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'icelandic_sheepdog', 'slug' => 'icelandic-sheepdog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.icelandic_sheepdog',
            'search_keywords' => seededBreedConfig('icelandic-sheepdog')['search_keywords'], 'sort_order' => 1380,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['needs_company', 'sheds', 'secure_fencing'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 289 S1100, UKC S1107)', function () {
    it('samples DNA only in the colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('icelandic_sheepdog')
            ->and(PetDnaService::hasAppearance('icelandic_sheepdog'))->toBeTrue()
            ->and(config('breed_appearance.icelandic_sheepdog.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.icelandic_sheepdog.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('icelandic_sheepdog', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('icelandic_sheepdog', $traits);
            expect($prompt)->toContain('Icelandic Sheepdog')->toContain('curled')->toContain('erect')
                ->not->toMatch('/\b(merle|dapple|brindle|saddle|blue eyes|yellow eyes|cropped|docked|floppy ears|drop ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.icelandic_sheepdog');
        expect($standard)->toBe(['red with white markings', 'black and tan with white markings', 'cream with white markings', 'red sable with white markings', 'grey with white markings', 'chocolate and tan with white markings', 'white with red patches (pied)'])
            ->and($standard)->toBe(iceData('icelandic_sheepdog.appearance.colours.value'))
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['red-white', 'black-tan-white', 'cream-white', 'sable-white', 'grey-white', 'chocolate-tan-white', 'white-red']);
    });

    it('draws the register portrait red with white markings (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::IcelandicSheepdog);

        expect($traits['coat_color'])->toBe('red with white markings')
            ->and($portrait->prompt(BreedType::IcelandicSheepdog))->toContain('Icelandic Sheepdog')->toContain('red with white markings')
            ->and(BreedPortraitService::relativeFile(BreedType::IcelandicSheepdog))->toBe('dog/icelandic-sheepdog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::IcelandicSheepdog);
        expect($v1['prompt_anchor'])->toContain('Icelandic Sheepdog')->toContain('red with white markings')->toContain('curled')
            ->not->toMatch('/\b(merle|dapple|brindle|saddle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('red with white markings');

        $pet = icePet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Icelandic Sheepdog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.icelandic_sheepdog'),
            config('breed_suitability.breeds.icelandic_sheepdog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::IcelandicSheepdog);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(iceData('proposed_game_parameters.icelandic_sheepdog.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (> 7 years → 84) is an alternative only; the WebMD lower bound decides the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'icelandic-sheepdog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families, family life and children; consider company, shedding and a secure fence', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::IcelandicSheepdog);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['needs_company', 'sheds', 'secure_fencing'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('chases_small_pets')->not->toContain('frequent_grooming');

        expect(iceData('icelandic_sheepdog.exercise.webmd.quote'))->toContain('active family')
            ->and(iceData('icelandic_sheepdog.behaviour.family.quote'))->toContain('loving family dogs')
            ->and(iceData('icelandic_sheepdog.behaviour.children.quote'))->toContain('patient with small children')
            ->and(iceData('icelandic_sheepdog.behaviour.time_alone.quote'))->toContain('left on their own')
            ->and(iceData('icelandic_sheepdog.suitability.petmd_shedding.quote'))->toContain('shed year-round')
            ->and(iceData('icelandic_sheepdog.behaviour.escaping.quote'))->toContain('secure fence')
            ->and(iceData('icelandic_sheepdog.behaviour.other_pets.quote'))->toStartWith('When socialized')
            ->and(iceData('icelandic_sheepdog.suitability.petmd_shedding.quote'))->not->toContain('hypoallergenic');
    });
});
