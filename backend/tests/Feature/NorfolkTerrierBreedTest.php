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
| M5-R10-134 — Norfolk Terrier (appended after the Finnish Lapphund: AKC 2024 No. 130)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `norfolk_terrier` (S1009–S1018) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.norfolk_terrier` (exercise 60 min — RKC "Up to 1 hour per day" —,
| senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"), arrival
| 2 / 9 / 36 / 90, learning × 0.7 (Coren 56, Fair), paid breed, Border Collie care rates, brushing twice a week (RKC "More
| than once a week" → Woodgreen "once or twice a week", M5-R13), puppy socialisation with the PDSA quote (S1012, M5-R17),
| tooth brushing every day (WebMD S1015), ear cleaning every week + a bath reminder every 4 weeks (PetMD S1014) and a
| groomer / stripping reminder every 12 weeks (PDSA S1012); no nail task (no frequency), no meal gap, no heat walk ban.
| No welfare chip, in marketing; suits apartment, children; consider needs_company, secure_fencing, frequent_grooming,
| chases_small_pets; red portrait (one of four standard colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function nfData(string $path): mixed
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

/** @return array<string, mixed> the Norfolk Terrier seeder row of (stage, from, key) */
function nfRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'norfolk-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("norfolk-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function nfPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->norfolkTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function nfPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function nfLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::NorfolkTerrier;
        $config = BreedConfig::where('breed_slug', 'norfolk-terrier')->sole();

        expect($breed->value)->toBe('norfolk_terrier')
            ->and($breed->slug())->toBe('norfolk-terrier')
            ->and(BreedType::fromSlug('norfolk-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1250, 'breeds.norfolk_terrier'])
            ->and($config->search_keywords)->toContain('norfolk terrier')->toContain('norfolk terier')->toContain('norfolški terier')
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) nfData('proposed_game_parameters.norfolk_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Norfolk Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = nfPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('norfolk_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'norfolk_terier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_03_120000_add_norfolk_terrier_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'norfolk_terrier']))->toThrow(QueryException::class);
        // The Finnish Lapphund (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'finnish_lapphund']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'norfolk_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::NorfolkTerrier)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'norfolk-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier')->count());

        BreedConfig::where('breed_slug', 'norfolk-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'norfolk-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'norfolk-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'norfolk-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'norfolk-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NORFOLK_TERRIER)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_NORFOLK_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.norfolk_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(nfRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(nfData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(nfRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(nfData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(nfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(nfData('norfolk_terrier.lifespan.senior_from.months'))
            ->and(nfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * nfRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(nfData('norfolk_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1011'])
            ->and(nfData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(nfRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', nfData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(nfRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_NORFOLK_TERRIER)
            ->and(nfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(nfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nfData("{$pgp}.exercise_minutes_adult.value"))
            ->and(nfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(nfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(nfData('norfolk_terrier.exercise.adult.quote'))
            ->and(nfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1011')
            ->and(nfRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(nfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nfData("{$pgp}.exercise_minutes_senior.value"))
            ->and(nfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(nfRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(nfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NORFOLK_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('norfolk-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.norfolk_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) nfData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing twice a week (Woodgreen, M5-R13), puppy socialisation (PDSA, M5-R17),
        // tooth brushing every day (WebMD), ear cleaning every week + a bath reminder every 4 weeks (PetMD) and a groomer /
        // stripping reminder every 12 weeks (PDSA). No nail task (no frequency), no meal gap.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['norfolk_terrier'];
        expect(json_encode([nfData('norfolk_terrier'), nfData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(nfData("{$pgp}.grooming.value"))->toBe(2)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('norfolk_terrier')
            ->and(nfData('norfolk_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['norfolk_terrier'])->toBe(['S1012', nfData("{$pgp}.socialisation.quote")])
            ->and(nfData("{$pgp}.socialisation.quote"))->toBe(nfData('norfolk_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath', 'groomer_visit'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1015', nfData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(nfData("{$pgp}.tooth_brushing.quote"))->toBe(nfData('norfolk_terrier.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S1014', nfData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(nfData("{$pgp}.ear_care.quote"))->toBe(nfData('norfolk_terrier.suitability.petmd_ears.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S1014', nfData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(nfData("{$pgp}.bathing.quote"))->toBe(nfData('norfolk_terrier.suitability.petmd_bathing.quote'))
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'three_months', 'S1012', nfData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(nfData("{$pgp}.professional_grooming.quote"))->toBe(nfData('norfolk_terrier.suitability.pdsa_grooming.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('norfolk_terrier');
    });

    it('takes the breed-level numbers from the sourced norfolk_terrier entries', function () {
        expect(nfRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.0, 5.4])
            ->and(nfData('norfolk_terrier.adult_weight.ukc.value'))->toEqual(['male' => [5.0, 5.4], 'female' => [5.0, 5.4]])
            ->and(nfRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(nfData('norfolk_terrier.adult_weight.ukc.quote'))
            ->and(nfRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(nfRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('norfolk_terrier.lifespan.rkc')
            ->and(nfRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(nfRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(nfData('norfolk_terrier.lifespan.rkc.quote'));

        $grooming = nfRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S1013')
            ->and($grooming['quote'])->toBe(nfData('norfolk_terrier.suitability.woodgreen_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(nfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1012')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::GroomerVisitFrequency->value => [1, 84],
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
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency, StageParamKey::GroomerVisitFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('norfolk-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = nfPet($arrival);
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

        expect($service->stageForAge('norfolk-terrier', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('norfolk-terrier', 90))->toBe(LifeStage::Senior);
    });

    it('learns slower than the mixed breed (Coren 56, Fair) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = nfPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'norfolk_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = nfPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(nfLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::NorfolkTerrier)
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
        foreach (['border_collie', 'norfolk_terrier'] as $breed) {
            $pin = nfPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(nfLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['norfolk_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('norfolk_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = nfPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Norfolk Terrier after the Finnish Lapphund with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'norfolk_terrier', 'finnish_lapphund');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'norfolk_terrier', 'slug' => 'norfolk-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.norfolk_terrier',
            'search_keywords' => seededBreedConfig('norfolk_terrier')['search_keywords'], 'sort_order' => 1250,
            'suitability' => [
                'suits' => ['apartment', 'children'],
                'consider' => ['needs_company', 'secure_fencing', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 272 S1010)', function () {
    it('samples DNA only in the four colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('norfolk_terrier')
            ->and(PetDnaService::hasAppearance('norfolk_terrier'))->toBeTrue()
            ->and(config('breed_appearance.norfolk_terrier.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.norfolk_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('norfolk_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('norfolk_terrier', $traits);
            expect($prompt)->toContain('Norfolk Terrier')->toContain('drop ears')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|white markings|white patches|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.norfolk_terrier');
        expect($standard)->toBe(['red', 'wheaten', 'black and tan', 'grizzle'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['red', 'wheaten', 'black-tan', 'grizzle'])
            ->and(nfData('norfolk_terrier.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait red (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::NorfolkTerrier);

        expect($traits['coat_color'])->toBe('red')
            ->and($portrait->prompt(BreedType::NorfolkTerrier))->toContain('Norfolk Terrier')->toContain('red')
            ->and(BreedPortraitService::relativeFile(BreedType::NorfolkTerrier))->toBe('dog/norfolk-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::NorfolkTerrier);
        expect($v1['prompt_anchor'])->toContain('Norfolk Terrier')->toContain('red')->toContain('drop ears')
            ->not->toMatch('/\b(merle|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('red');

        $pet = nfPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Norfolk Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.norfolk_terrier'),
            config('breed_suitability.breeds.norfolk_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::NorfolkTerrier);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(nfData('proposed_game_parameters.norfolk_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norfolk-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits flats and children; consider company, a secure fence, grooming and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::NorfolkTerrier);
        expect($tags['suits'])->toBe(['apartment', 'children'])
            ->and($tags['consider'])->toBe(['needs_company', 'secure_fencing', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('needs_mental_stimulation')->not->toContain('long_daily_exercise');

        expect(nfData('norfolk_terrier.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(nfData('norfolk_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(nfData('norfolk_terrier.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(nfData('norfolk_terrier.behaviour.time_alone.quote'))->toBe("These people-loving dogs don't enjoy being left alone for long stretches of time.");
    });
});
