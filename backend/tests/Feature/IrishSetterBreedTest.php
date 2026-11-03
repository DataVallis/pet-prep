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
| M5-R10-89 — Irish Setter (appended after the Brussels Griffon: AKC 2024 No. 85 "Irish Setter")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `irish_setter` (S675–S681) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.irish_setter` (exercise 120 min — RKC "More than 2 hours per day" —, senior
| 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound 10 y), arrival 2 / 9 / 36 / 90, learning
| × 1.2 (Coren rank 35, Above average), paid breed, Border Collie care rates, brushing 3× a week (PDSA "2 or 3 times per week",
| M5-R13), puppy socialisation with the PDSA quote (M5-R17), care tasks with a PetMD frequency (ears once a week M5-R20, nails
| once a month M5-R19). Not a welfare breed (RKC Breed Watch Category 1): suits active_family / children / other_pets /
| large_home; consider long_daily_exercise / needs_company / sheds / frequent_grooming; rich chestnut portrait; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function irsData(string $path): mixed
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

/** @return array<string, mixed> the Irish Setter seeder row of (stage, from, key) */
function irsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'irish-setter'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("irish-setter.{$stage}.{$from}.{$key->value}");

    return $row;
}

function irsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->irishSetter()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function irsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function irsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::IrishSetter;
        $config = BreedConfig::where('breed_slug', 'irish-setter')->sole();

        expect($breed->value)->toBe('irish_setter')
            ->and($breed->slug())->toBe('irish-setter')
            ->and(BreedType::fromSlug('irish-setter'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 830, 'breeds.irish_setter'])
            ->and($config->search_keywords)->toBe(['irish setter', 'irish red setter', 'red setter', 'setter', 'irski seter', 'irski rdeči seter', 'irski rdeci seter', 'rdeči seter', 'rdeci seter', 'seter'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) irsData('proposed_game_parameters.irish_setter.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = irsData('proposed_game_parameters.irish_setter.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Irish Setter pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = irsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('irish_setter');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'red_setter']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'irish-setter']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_05_120000_add_irish_setter_breed.php');
        // down() refuses while an Irish Setter exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'irish_setter']))->toThrow(QueryException::class);
        // The Brussels Griffon (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'brussels_griffon']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'irish_setter']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::IrishSetter);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'irish-setter')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-setter')->count());

        BreedConfig::where('breed_slug', 'irish-setter')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'irish-setter')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'irish-setter')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'irish-setter');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-setter');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'irish-setter'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_IRISH_SETTER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_IRISH_SETTER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.irish_setter';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(irsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(irsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(irsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(irsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(irsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(irsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S676) = 90 — the runbook's third lifespan source.
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(irsData('irish_setter.lifespan.senior_from.months'))
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(irsData("{$pgp}.senior_boundary_months.value"))
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * irsRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(irsData('irish_setter.lifespan.senior_from.derived_from'))->toBe(['S11', 'S676'])
            ->and(irsData('irish_setter.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S676')
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_IRISH_SETTER)
            ->and(irsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(irsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(irsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(irsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(irsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(irsData('irish_setter.exercise.adult.quote'))
            ->and(irsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S676')
            ->and(irsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(irsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(irsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(irsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(irsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(irsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(irsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(irsData("{$pgp}.learning_multiplier.value"))
            ->and(irsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_IRISH_SETTER) {
                $decided++;
                expect($row['breed_slug'])->toBe('irish-setter')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.irish_setter.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) irsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 3× a week (M5-R13, PDSA), socialisation (M5-R17), ears / nails (M5-R20 / R19).
        // No meal gap (no wait-after-eating quote), no summer walk warning (not flat-faced).
        expect(json_encode([irsData('irish_setter'), irsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(irsData("{$pgp}.grooming.value"))->toBe(3)
            ->and(irsData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(irsData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('irish_setter')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['irish_setter'])->toBe(['S678', irsData('irish_setter.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['irish_setter'][1])->toBe(irsData("{$pgp}.socialisation.quote"))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['irish_setter']))->toBe(['ear_cleaning', 'nail_trim'])
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('irish_setter');

        // The care tasks quote their data.json entries verbatim.
        foreach (BreedStageParamsSeeder::DOG_CARE_TASKS['irish_setter'] as $task => [$times, $period, $source, $quote, $ref]) {
            expect(irsData($ref)['quote'])->toBe($quote, $task)
                ->and(irsData($ref)['source_id'])->toBe($source, $task);
        }
        expect(array_map(fn (array $t) => [$t[0], $t[1]], BreedStageParamsSeeder::DOG_CARE_TASKS['irish_setter']))
            ->toBe(['ear_cleaning' => [1, 'week'], 'nail_trim' => [1, 'month']]);
    });

    it('takes the breed-level numbers from the sourced irish_setter entries', function () {
        // AKC breed standard (S680): bitch 60 lb, dog about 70 lb → 27.2–31.8 kg.
        expect(irsRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([27.2, 31.8])
            ->and(irsData('irish_setter.adult_weight.akc.value'))->toEqual(['male' => [31.8, 31.8], 'female' => [27.2, 27.2]])
            ->and(irsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(irsData('irish_setter.adult_weight.akc.source_id'))
            ->and(irsRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(irsData('irish_setter.adult_weight.akc.quote'))
            ->and(irsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(irsData('irish_setter.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 35 (Above average) → learning 1.2.
            ->and(irsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(35)
            ->and(irsData('irish_setter.trainability.coren_rank.value'))->toBe(35)
            ->and(irsRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('irish_setter.lifespan.rkc')
            ->and(irsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S676')
            ->and(irsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(irsRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(irsData('irish_setter.lifespan.rkc.quote'));

        // Brushing 3× a week: RKC "More than once a week" has no number → PDSA "2 or 3 times per week" (upper bound), verified.
        $grooming = irsRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S678')
            ->and($grooming['quote'])->toBe(irsData('irish_setter.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(irsData('proposed_game_parameters.irish_setter.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and(BreedStageParamsSeeder::DOG_GROOMING['irish_setter'][4])->toBeFalse()
            ->and(irsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(irsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S678')
            ->and(irsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-setter');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
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
            // M5-R17: the breed's own socialisation quote (PDSA S678).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            // M5-R19 / R20: the breed's care tasks (the Border Collie has none).
            ->reject(fn (array $r) => str_ends_with($r['key'], '_frequency') || $r['key'] === StageParamKey::NoWalkInHeat->value)
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('irish-setter'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = irsPet($arrival);
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

    it('becomes senior at 90 months, earlier than the Brussels Griffon', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('irish-setter', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('irish-setter', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('brussels-griffon', 90))->toBe(LifeStage::Adult);
    });

    it('learns faster than the mixed breed (Coren rank 35, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = irsPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'irish_setter', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = irsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(irsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::IrishSetter)
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
        foreach (['border_collie', 'irish_setter'] as $breed) {
            $pin = irsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(irsLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['irish_setter'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('irish_setter')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = irsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Irish Setter after the Brussels Griffon with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'irish_setter', 'brussels_griffon');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'irish_setter', 'slug' => 'irish-setter', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.irish_setter',
            'search_keywords' => seededBreedConfig('irish_setter')['search_keywords'], 'sort_order' => 830,
            'suitability' => [
                'suits' => ['active_family', 'children', 'other_pets', 'large_home'],
                'consider' => ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S675 one colour)', function () {
    it('samples DNA in the one standard colour only, never red and white or black', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('irish_setter')
            ->and(PetDnaService::hasAppearance('irish_setter'))->toBeTrue()
            ->and(config('breed_appearance.irish_setter.verified'))->toBeFalse()
            ->and(config('breed_appearance.irish_setter.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 100) as $seed) {
            $traits = $dna->sample('irish_setter', $seed)['traits'];
            $colours[$traits['coat_color']] = true;

            $prompt = $prompts->imagePrompt('irish_setter', $traits);
            expect($prompt)->toContain('Irish Setter')
                ->not->toMatch('/\b(merle|brindle|red and white|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $standard = array_column(config('breed_appearance.irish_setter.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(1)
            ->and(array_keys($colours))->toBe($standard)
            ->and(array_column(config('breed_colours.irish_setter'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.irish_setter'), 'key'))->toBe(['chestnut'])
            ->and(irsData('irish_setter.appearance.colours.value'))->toBe(['rich chestnut']);
    });

    it('draws the register portrait rich chestnut red', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::IrishSetter);

        expect($traits['coat_color'])->toStartWith('rich chestnut red')
            ->and($portrait->prompt(BreedType::IrishSetter))->toContain('Irish Setter')->toContain('chestnut')
            ->and(BreedPortraitService::relativeFile(BreedType::IrishSetter))->toBe('dog/irish-setter.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::IrishSetter);
        expect($v1['prompt_anchor'])->toContain('Irish Setter')->toContain('chestnut')
            ->not->toMatch('/\b(merle|brindle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark hazel')
            ->and($v1['visual_traits']['color_scheme'])->toBe('rich chestnut red');

        $pet = irsPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Irish Setter')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.irish_setter'),
            config('breed_suitability.breeds.irish_setter'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-setter')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', 'risk of'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::IrishSetter);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->not->toContain('shorter_lifespan')->not->toContain('hips_hind_legs')
            ->and(irsData('proposed_game_parameters.irish_setter.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (116 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-setter')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(116)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family, children, other pets and a large home; consider exercise, company, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::IrishSetter);
        expect($tags['suits'])->toBe(['active_family', 'children', 'other_pets', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('apartment')
            ->and($tags['consider'])->not->toContain('often_alone')->not->toContain('chases_small_pets');

        expect(irsData('irish_setter.exercise.adult.quote'))->toBe('More than 2 hours per day')
            ->and(irsData('irish_setter.behaviour.children.notes'))->toContain('toddlers')
            ->and(irsData('irish_setter.behaviour.time_alone.quote'))->toContain('human contact')
            ->and(irsData('irish_setter.suitability.rkc_size_of_home.quote'))->toBe('Large house');
    });
});
