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
| M5-R10-137 — German Pinscher (appended after the Bracco Italiano: AKC 2024 No. 133)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `german_pinscher` (S1029–S1034) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.german_pinscher` (exercise 60 min — RKC "Up to 1 hour per day" —,
| senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"), arrival
| 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing once a week (RKC,
| M5-R13), puppy socialisation with the PetMD quote (S1032, M5-R17), ear cleaning every week + a bath reminder every 6 weeks
| (PetMD S1032) and a nail trim every 4 weeks (WebMD S1033); no tooth task (daily brushing only one option), no groomer task,
| no meal gap, no heat walk ban. No welfare chip, in marketing; suits family_pet, children; consider
| independent_experienced_owner, needs_mental_stimulation, sheds, chases_small_pets; black and tan portrait (one of two standard colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gpData(string $path): mixed
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

/** @return array<string, mixed> the German Pinscher seeder row of (stage, from, key) */
function gpRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'german-pinscher'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("german-pinscher.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gpPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->germanPinscher()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gpPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gpLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GermanPinscher;
        $config = BreedConfig::where('breed_slug', 'german-pinscher')->sole();

        expect($breed->value)->toBe('german_pinscher')
            ->and($breed->slug())->toBe('german-pinscher')
            ->and(BreedType::fromSlug('german-pinscher'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1280, 'breeds.german_pinscher'])
            ->and($config->search_keywords)->toContain('german pinscher')->toContain('nemški pinč')->toContain('deutscher pinscher')
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gpData('proposed_game_parameters.german_pinscher.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a German Pinscher pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gpPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('german_pinscher');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'german_pincher']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_06_120000_add_german_pinscher_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'german_pinscher']))->toThrow(QueryException::class);
        // The Bracco Italiano (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bracco_italiano']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_pinscher']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GermanPinscher)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'german-pinscher')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher')->count());

        BreedConfig::where('breed_slug', 'german-pinscher')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'german-pinscher')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'german-pinscher')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'german-pinscher');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'german-pinscher'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_PINSCHER)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_GERMAN_PINSCHER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.german_pinscher';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gpRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gpData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gpRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gpData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gpData('german_pinscher.lifespan.senior_from.months'))
            ->and(gpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gpRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gpData('german_pinscher.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1031'])
            ->and(gpData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(gpRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', gpData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(gpRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_PINSCHER)
            ->and(gpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(gpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gpData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(gpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gpData('german_pinscher.exercise.adult.quote'))
            ->and(gpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1031')
            ->and(gpRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(gpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gpData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(gpRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_PINSCHER) {
                $decided++;
                expect($row['breed_slug'])->toBe('german-pinscher')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.german_pinscher.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gpData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing once a week (RKC, M5-R13), puppy socialisation (PetMD, M5-R17),
        // ear cleaning every week + a bath reminder every 6 weeks (PetMD) and a nail trim every 4 weeks (WebMD). No tooth task
        // (daily brushing is only one of three options), no groomer task, no meal gap.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['german_pinscher'];
        expect(json_encode([gpData('german_pinscher'), gpData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(gpData("{$pgp}.grooming.value"))->toBe(1)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('german_pinscher')
            ->and(gpData('german_pinscher.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['german_pinscher'])->toBe(['S1032', gpData("{$pgp}.socialisation.quote")])
            ->and(gpData("{$pgp}.socialisation.quote"))->toBe(gpData('german_pinscher.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['ear_cleaning', 'nail_trim', 'bath'])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S1032', gpData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(gpData("{$pgp}.ear_care.quote"))->toBe(gpData('german_pinscher.suitability.petmd_ears.quote'))
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'month', 'S1033', gpData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(gpData("{$pgp}.nail_trimming.quote"))->toBe(gpData('german_pinscher.suitability.webmd_nails.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'six_weeks', 'S1032', gpData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(gpData("{$pgp}.bathing.quote"))->toBe(gpData('german_pinscher.suitability.petmd_bathing.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('german_pinscher');
    });

    it('takes the breed-level numbers from the sourced german_pinscher entries', function () {
        expect(gpRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([14.0, 20.0])
            ->and(gpData('german_pinscher.adult_weight.fci.value'))->toEqual(['male' => [14, 20], 'female' => [14, 20]])
            ->and(gpRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(gpData('german_pinscher.adult_weight.fci.quote'))
            ->and(gpRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(gpRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('german_pinscher.lifespan.rkc')
            ->and(gpRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(gpRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(gpData('german_pinscher.lifespan.rkc.quote'));

        $grooming = gpRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S1031')
            ->and($grooming['quote'])->toBe(gpData('german_pinscher.suitability.rkc_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(gpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1032')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 42],
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::NailTrimFrequency->value => [1, 28],
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
            StageParamKey::EarCleaningFrequency, StageParamKey::NailTrimFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('german-pinscher'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gpPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('german-pinscher', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('german-pinscher', 90))->toBe(LifeStage::Senior);
    });

    it('learns like the mixed breed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gpPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'german_pinscher', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gpPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gpLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GermanPinscher)
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
        foreach (['border_collie', 'german_pinscher'] as $breed) {
            $pin = gpPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gpLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['german_pinscher'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('german_pinscher')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gpPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the German Pinscher after the Bracco Italiano with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'german_pinscher', 'bracco_italiano');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'german_pinscher', 'slug' => 'german-pinscher', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.german_pinscher',
            'search_keywords' => seededBreedConfig('german_pinscher')['search_keywords'], 'sort_order' => 1280,
            'suitability' => [
                'suits' => ['family_pet', 'children'],
                'consider' => ['independent_experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 184 S1030)', function () {
    it('samples DNA only in the two colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('german_pinscher')
            ->and(PetDnaService::hasAppearance('german_pinscher'))->toBeTrue()
            ->and(config('breed_appearance.german_pinscher.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.german_pinscher.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('german_pinscher', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('german_pinscher', $traits);
            expect($prompt)->toContain('German Pinscher')->toContain('drop ears')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|white markings|white patches|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.german_pinscher');
        expect($standard)->toBe(['lacquer black with red-brown markings (black and tan)', 'self-coloured deer red (red to dark red-brown)'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-tan', 'red'])
            ->and(gpData('german_pinscher.appearance.colours.value'))->toBe(['black and tan', 'red']);
    });

    it('draws the register portrait black and tan (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GermanPinscher);

        expect($traits['coat_color'])->toBe('lacquer black with red-brown markings (black and tan)')
            ->and($portrait->prompt(BreedType::GermanPinscher))->toContain('German Pinscher')->toContain('black and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::GermanPinscher))->toBe('dog/german-pinscher.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GermanPinscher);
        expect($v1['prompt_anchor'])->toContain('German Pinscher')->toContain('black')->toContain('drop ears')
            ->not->toMatch('/\b(merle|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black and tan');

        $pet = gpPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('German Pinscher')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.german_pinscher'),
            config('breed_suitability.breeds.german_pinscher'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::GermanPinscher);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(gpData('proposed_game_parameters.german_pinscher.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-pinscher')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families and children; consider an independent dog for an experienced owner, mental stimulation, shedding and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::GermanPinscher);
        expect($tags['suits'])->toBe(['family_pet', 'children'])
            ->and($tags['consider'])->toBe(['independent_experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('needs_company')->not->toContain('secure_fencing')->not->toContain('experienced_owner')->not->toContain('frequent_grooming')->not->toContain('long_daily_exercise');

        expect(gpData('german_pinscher.suitability.rkc_size_of_home.quote'))->toBe('Small house')
            ->and(gpData('german_pinscher.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(gpData('german_pinscher.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(gpData('german_pinscher.behaviour.time_alone.quote'))->toBeNull();
    });
});
