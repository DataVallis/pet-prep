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
| M5-R10-145 — Saluki (appended after the Bearded Collie: AKC 2024 No. 141)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `saluki` (S1084–S1093) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.saluki` (exercise 120 min — PDSA "Minimum exercise (per day): 2 hours",
| the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × PDSA lower
| bound "Over 10 years"), arrival 2 / 9 / 36 / 90, learning × 1.0 (Coren tier Average), paid breed, Border Collie care
| rates, brushing 2× a week (PetMD "once or twice a week", M5-R13), tooth brushing every day (WebMD, M5-R15), puppy
| socialisation with the PetMD quote (S1087, M5-R17); no ear / nail / bath / groomer task (no frequency), no meal gap, no
| heat walk ban. No welfare chip, in marketing; suits active_family; consider long_daily_exercise, needs_company; fawn
| portrait (eight AKC colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function saluData(string $path): mixed
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

/** @return array<string, mixed> the Saluki seeder row of (stage, from, key) */
function saluRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'saluki'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("saluki.{$stage}.{$from}.{$key->value}");

    return $row;
}

function saluPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->saluki()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function saluPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function saluLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Saluki;
        $config = BreedConfig::where('breed_slug', 'saluki')->sole();

        expect($breed->value)->toBe('saluki')
            ->and($breed->slug())->toBe('saluki')
            ->and(BreedType::fromSlug('saluki'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1360, 'breeds.saluki'])
            ->and($config->search_keywords)->toBe(['saluki', 'gazelle hound', 'persian greyhound', 'arabian hound', 'perzijski hrt', 'gazelji hrt'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) saluData('proposed_game_parameters.saluki.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Saluki pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = saluPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('saluki');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'salukis']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_16_120000_add_saluki_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'saluki']))->toThrow(QueryException::class);
        // The Bearded Collie (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bearded_collie']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'saluki']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Saluki)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'saluki')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki')->count());

        BreedConfig::where('breed_slug', 'saluki')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'saluki')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'saluki')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'saluki');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'saluki'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SALUKI)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_SALUKI');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.saluki';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(saluRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(saluData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(saluRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(saluData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(saluRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(saluData('saluki.lifespan.senior_from.months'))
            ->and(saluRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * saluRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(saluData('saluki.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1086'])
            ->and(saluData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(saluRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', saluData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(saluRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SALUKI)
            ->and(saluRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(saluRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(saluData("{$pgp}.exercise_minutes_adult.value"))
            ->and(saluRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(saluRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(saluData('saluki.exercise.adult.quote'))
            ->and(saluRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1086')
            ->and(saluRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(saluRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(saluData("{$pgp}.exercise_minutes_senior.value"))
            ->and(saluRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(saluRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(saluRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SALUKI) {
                $decided++;
                expect($row['breed_slug'])->toBe('saluki')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.saluki.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) saluData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 2× a week (PetMD "once or twice a week", M5-R13), tooth
        // brushing every day (WebMD, M5-R15), puppy socialisation (PetMD, M5-R17). No ear / nail / bath task (conditional or
        // no frequency), no groomer task (no source), no meal gap (PetMD names GDV only).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['saluki'];
        expect(json_encode([saluData('saluki'), saluData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(saluData("{$pgp}.grooming.value"))->toBe(2)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('saluki')
            ->and(saluData('saluki.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['saluki'])->toBe(['S1087', saluData("{$pgp}.socialisation.quote")])
            ->and(saluData("{$pgp}.socialisation.quote"))->toBe(saluData('saluki.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1088', saluData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(saluData("{$pgp}.tooth_brushing.quote"))->toBe(saluData('saluki.suitability.webmd_teeth.quote'))
            ->and(saluData('saluki.suitability.webmd_nails.quote'))->toContain('regularly')
            ->and(saluData('saluki.suitability.petmd_ears.quote'))->toContain('after a bath')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('saluki');
    });

    it('takes the breed-level numbers from the sourced saluki entries', function () {
        expect(saluRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16.0, 29.5])
            ->and(saluData('saluki.adult_weight.pdsa.value'))->toEqual(['male' => [16, 29.5], 'female' => [16, 29.5]])
            ->and(saluRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(saluData('saluki.adult_weight.pdsa.quote'))
            ->and(saluRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe('S1086')
            ->and(saluRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(saluRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(saluData('saluki.trainability.coren_rank.value'))
            ->and(saluRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(saluData('saluki.trainability.coren_tier.value'))->toBe('Average')
            ->and(saluRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('saluki.lifespan.pdsa')
            ->and(saluRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(saluRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(saluData('saluki.lifespan.pdsa.quote'));

        $grooming = saluRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S1087')
            ->and($grooming['quote'])->toBe(saluData('saluki.suitability.petmd_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(saluRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1087')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
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
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('saluki'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = saluPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('saluki', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('saluki', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed breed\'s speed (Coren tier Average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = saluPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'saluki', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = saluPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(saluLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Saluki)
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
        foreach (['border_collie', 'saluki'] as $breed) {
            $pin = saluPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(saluLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['saluki'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('saluki')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = saluPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Saluki after the Bearded Collie with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'saluki', 'bearded_collie');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'saluki', 'slug' => 'saluki', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.saluki',
            'search_keywords' => seededBreedConfig('saluki')['search_keywords'], 'sort_order' => 1360,
            'suitability' => [
                'suits' => ['active_family'],
                'consider' => ['long_daily_exercise', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 269 S1085, AKC S1091)', function () {
    it('samples DNA only in the colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('saluki')
            ->and(PetDnaService::hasAppearance('saluki'))->toBeTrue()
            ->and(config('breed_appearance.saluki.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.saluki.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('saluki', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('saluki', $traits);
            expect($prompt)->toContain('Saluki')->toContain('feathering')
                ->not->toMatch('/\b(merle|dapple|brindle|cropped|docked|clipped|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.saluki');
        expect($standard)->toBe(['fawn', 'cream', 'white', 'golden', 'red', 'grizzle and tan', 'tricolour (white, black and tan)', 'black and tan'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['fawn', 'cream', 'white', 'golden', 'red', 'grizzle-tan', 'tricolour', 'black-tan'])
            ->and(saluData('saluki.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait fawn (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Saluki);

        expect($traits['coat_color'])->toBe('fawn')
            ->and($portrait->prompt(BreedType::Saluki))->toContain('Saluki')->toContain('fawn')
            ->and(BreedPortraitService::relativeFile(BreedType::Saluki))->toBe('dog/saluki.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Saluki);
        expect($v1['prompt_anchor'])->toContain('Saluki')->toContain('fawn')->toContain('feathering')
            ->not->toMatch('/\b(merle|dapple|brindle|cropped|docked|clipped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('fawn');

        $pet = saluPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Saluki')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.saluki'),
            config('breed_suitability.breeds.saluki'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Saluki);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(saluData('proposed_game_parameters.saluki.marketing.value'))->toContain('in marketing: yes')
            // The McMillan 2024 value only via Wikipedia (tier C) never decides the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'saluki')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(120);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families; consider long daily exercise and company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Saluki);
        expect($tags['suits'])->toBe(['active_family'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('sheds')->not->toContain('frequent_grooming');

        expect(saluData('saluki.exercise.pdsa.quote'))->toBe('Salukis need over 2 hours of exercise daily.')
            ->and(saluData('saluki.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(saluData('saluki.behaviour.other_pets.quote'))->toContain('strong chase instinct')
            ->and(saluData('saluki.behaviour.children.quote'))->toContain('older children');
    });
});
