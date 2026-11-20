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
| M5-R10-131 — Black Russian Terrier (appended after the Tibetan Spaniel: AKC 2024 No. 127)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `black_russian_terrier` (S984–S992) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.black_russian_terrier` (exercise 120 min — RKC "More than 2 hours per day" —,
| senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"), arrival
| 2 / 9 / 36 / 90, learning × 1.0 (unranked), paid breed, Border Collie care rates, brushing every day (RKC "Every day", M5-R13),
| puppy socialisation with the PetMD quote (S989, M5-R17), meal / exercise gap 60 min (PetMD S989 bloat, M5-R16), ear check
| every week (PetMD), nail trim every week + groomer every 6 weeks (VCA S990, M5-R14 / R18 / R20); no tooth / bath task (no
| frequency), no heat walk ban (not flat-faced). No welfare chip, in marketing; suits active_family, family_pet, children,
| large_home, low_shedding; consider long_daily_exercise, frequent_grooming, not_for_small_children; black portrait (one of two colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function brtData(string $path): mixed
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

/** @return array<string, mixed> the Black Russian Terrier seeder row of (stage, from, key) */
function brtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'black-russian-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("black-russian-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function brtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->blackRussianTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function brtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function brtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BlackRussianTerrier;
        $config = BreedConfig::where('breed_slug', 'black-russian-terrier')->sole();

        expect($breed->value)->toBe('black_russian_terrier')
            ->and($breed->slug())->toBe('black-russian-terrier')
            ->and(BreedType::fromSlug('black-russian-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1220, 'breeds.black_russian_terrier'])
            ->and($config->search_keywords)->toContain('black russian terrier')->toContain('russian black terrier')->toContain('ruski črni terier')
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) brtData('proposed_game_parameters.black_russian_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Black Russian Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = brtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('black_russian_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'russian_black_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_27_120000_add_black_russian_terrier_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'black_russian_terrier']))->toThrow(QueryException::class);
        // The Tibetan Spaniel (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'tibetan_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'black_russian_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BlackRussianTerrier)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'black-russian-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'black-russian-terrier')->count());

        BreedConfig::where('breed_slug', 'black-russian-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'black-russian-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'black-russian-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'black-russian-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'black-russian-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'black-russian-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BLACK_RUSSIAN_TERRIER)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BLACK_RUSSIAN_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.black_russian_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(brtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(brtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(brtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(brtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(brtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(brtData('black_russian_terrier.lifespan.senior_from.months'))
            ->and(brtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * brtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(brtData('black_russian_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S986'])
            ->and(brtData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(brtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', brtData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(brtRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BLACK_RUSSIAN_TERRIER)
            ->and(brtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(brtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(brtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(brtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(brtData('black_russian_terrier.exercise.adult.quote'))
            ->and(brtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S986')
            ->and(brtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(brtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(brtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(brtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(brtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BLACK_RUSSIAN_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('black-russian-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.black_russian_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) brtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing every day (RKC, M5-R13), puppy socialisation (PetMD, M5-R17),
        // the meal gap (PetMD bloat, M5-R16), ear check every week (PetMD), nail trim every week + groomer every 6 weeks (VCA).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['black_russian_terrier'];
        expect(json_encode([brtData('black_russian_terrier'), brtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(brtData("{$pgp}.grooming.value"))->toBe(7)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['black_russian_terrier'])->toBe(['S989', brtData('black_russian_terrier.exercise.after_meals.quote')])
            ->and(brtData("{$pgp}.meal_exercise_gap.quote"))->toBe(brtData('black_russian_terrier.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['black_russian_terrier'])->toBe(['S989', brtData("{$pgp}.socialisation.quote")])
            ->and(brtData("{$pgp}.socialisation.quote"))->toBe(brtData('black_russian_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['ear_cleaning', 'nail_trim', 'groomer_visit'])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S989', brtData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(brtData("{$pgp}.ear_care.quote"))->toBe(brtData('black_russian_terrier.suitability.petmd_ears.quote'))
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'week', 'S990', brtData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(brtData("{$pgp}.nail_trimming.quote"))->toBe(brtData('black_russian_terrier.suitability.vca_nails.quote'))
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'six_weeks', 'S990', brtData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(brtData("{$pgp}.professional_grooming.quote"))->toBe(brtData('black_russian_terrier.suitability.vca_professional_grooming.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('black_russian_terrier');
    });

    it('takes the breed-level numbers from the sourced black_russian_terrier entries', function () {
        expect(brtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([45, 60])
            ->and(brtData('black_russian_terrier.adult_weight.fci.value'))->toEqual(['male' => [50, 60], 'female' => [45, 50]])
            ->and(brtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(brtData('black_russian_terrier.adult_weight.fci.quote'))
            ->and(brtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(brtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('black_russian_terrier.lifespan.rkc')
            ->and(brtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(brtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(brtData('black_russian_terrier.lifespan.rkc.quote'));

        $grooming = brtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S986')
            ->and($grooming['quote'])->toBe(brtData('black_russian_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(brtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S989')
            ->and(brtRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'black-russian-terrier');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::GroomerVisitFrequency->value => [1, 42],
            StageParamKey::NailTrimFrequency->value => [1, 7],
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
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek, StageParamKey::MealExerciseGapMinutes,
            StageParamKey::EarCleaningFrequency, StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('black-russian-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = brtPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('black-russian-terrier', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('black-russian-terrier', 90))->toBe(LifeStage::Senior);
    });

    it('learns like the mixed breed (unranked) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = brtPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'black_russian_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = brtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(brtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BlackRussianTerrier)
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
        foreach (['border_collie', 'black_russian_terrier'] as $breed) {
            $pin = brtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(brtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['black_russian_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('black_russian_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = brtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Black Russian Terrier after the Tibetan Spaniel with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'black_russian_terrier', 'tibetan_spaniel');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'black_russian_terrier', 'slug' => 'black-russian-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.black_russian_terrier',
            'search_keywords' => seededBreedConfig('black_russian_terrier')['search_keywords'], 'sort_order' => 1220,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home', 'low_shedding'],
                'consider' => ['long_daily_exercise', 'frequent_grooming', 'not_for_small_children'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 327 S985)', function () {
    it('samples DNA only in the two standard colours, never white markings or grey patches', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('black_russian_terrier')
            ->and(PetDnaService::hasAppearance('black_russian_terrier'))->toBeTrue()
            ->and(config('breed_appearance.black_russian_terrier.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.black_russian_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('black_russian_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('black_russian_terrier', $traits);
            expect($prompt)->toContain('Black Russian Terrier')->toContain('hanging')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|white markings|flat-faced)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.black_russian_terrier');
        expect($standard)->toBe(['black', 'black with intermingling grey hairs'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black', 'black-grey'])
            ->and(brtData('black_russian_terrier.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait black (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BlackRussianTerrier);

        expect($traits['coat_color'])->toBe('black')
            ->and($portrait->prompt(BreedType::BlackRussianTerrier))->toContain('Black Russian Terrier')->toContain('black')
            ->and(BreedPortraitService::relativeFile(BreedType::BlackRussianTerrier))->toBe('dog/black-russian-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BlackRussianTerrier);
        expect($v1['prompt_anchor'])->toContain('Black Russian Terrier')->toContain('black')
            ->not->toMatch('/\b(merle|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black');

        $pet = brtPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Black Russian Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.black_russian_terrier'),
            config('breed_suitability.breeds.black_russian_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'black-russian-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%', '10.9'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BlackRussianTerrier);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(brtData('proposed_game_parameters.black_russian_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'black-russian-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(98);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families in a large home; consider long exercise, daily grooming and no small children', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BlackRussianTerrier);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home', 'low_shedding'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'frequent_grooming', 'not_for_small_children'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('needs_company')->not->toContain('experienced_owner');

        expect(brtData('black_russian_terrier.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(brtData('black_russian_terrier.suitability.rkc_shedding.quote'))->toBe('No')
            ->and(brtData('black_russian_terrier.behaviour.older_children.quote'))->toBe('Children | Older kids');
    });
});
