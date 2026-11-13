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
| M5-R10-108 — Norwich Terrier (appended after the Norwich Terrier: AKC 2024 No. 104 "Norwich Terrier")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `norwich_terrier` (S811–S817) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.norwich_terrier` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy / young 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound
| "Over 10 years", S813 — no McMillan 2024 value reachable), arrival 2 / 9 / 36 / 90, learning × 1.2 (Coren rank 38,
| Above average), paid breed, Border Collie care rates, brushing 2× a week (RKC "More than once a week" → PetMD "at least
| twice a week", M5-R13), tooth brushing 1× a week (M5-R15), nail trimming every 4 weeks (M5-R19) and a bath reminder every
| 12 weeks (M5-R18) from PetMD S814, puppy socialisation with the PetMD quote (S814, M5-R17), no meal gap, no heat warning.
| Not a welfare breed (RKC Breed Watch Category 2 without points) → in marketing; red portrait with erect ears.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ntData(string $path): mixed
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

/** @return array<string, mixed> the Norwich Terrier seeder row of (stage, from, key) */
function ntRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'norwich-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("norwich_terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ntPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->norwichTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ntPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ntLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::NorwichTerrier;
        $config = BreedConfig::where('breed_slug', 'norwich-terrier')->sole();

        expect($breed->value)->toBe('norwich_terrier')
            ->and($breed->slug())->toBe('norwich-terrier')
            ->and(BreedType::fromSlug('norwich-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1010, 'breeds.norwich_terrier'])
            ->and($config->search_keywords)->toBe(['norwich terrier', 'norwich terier', 'norwiški terier', 'norwich'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ntData('proposed_game_parameters.norwich_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ntData('proposed_game_parameters.norwich_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Norwich Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ntPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('norwich_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'norwich']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'norfolk_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_29_120000_add_norwich_terrier_breed.php');
        // down() refuses while a Norwich Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'norwich_terrier']))->toThrow(QueryException::class);
        // The Japanese Chin (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'japanese_chin']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'norwich_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::NorwichTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'norwich-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwich-terrier')->count());

        BreedConfig::where('breed_slug', 'norwich-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'norwich-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'norwich-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'norwich-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwich-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'norwich-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NORWICH_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_NORWICH_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.norwich_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ntRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ntData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ntRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ntData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ntRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ntData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 10 y × 12) = 90 (RKC lower bound "Over 10 years", S813 — no McMillan value reachable) → runbook rule, verified.
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ntData('norwich_terrier.lifespan.senior_from.months'))
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ntRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ntData('norwich_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S813'])
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', ntData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_NORWICH_TERRIER)
            ->and(ntRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(ntRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90)
            // Wikipedia's McMillan figure (tier C), the PetMD lower bound and Dogs Trust's 7 y stay alternatives.
            ->and(ntData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_wikipedia_mcmillan_14_y' => 126, 'senior_petmd_12_y' => 108, 'senior_dogs_trust_7_y' => 84]);

        expect(ntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ntData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ntData('norwich_terrier.exercise.adult.quote'))
            ->and(ntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ntData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ntRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S813')
            ->and(ntRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ntData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ntRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(ntRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ntData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(ntRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ntData("{$pgp}.learning_multiplier.value"))
            ->and(ntRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NORWICH_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('norwich-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.norwich_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ntData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 2× a week (M5-R13), tooth brushing 1× a week (M5-R15), nail trimming every
        // 4 weeks (M5-R19) and a bath reminder every 12 weeks (M5-R18) from PetMD S814, puppy socialisation with the PetMD
        // quote (M5-R17). No meal gap and no heat warning (no quote), no ear / groomer task (no frequency).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['norwich_terrier'];
        expect(json_encode([ntData('norwich_terrier'), ntData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ntData("{$pgp}.grooming.value"))->toBe(2)
            ->and(ntData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('norwich_terrier')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('norwich_terrier')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['norwich_terrier'])->toBe(['S814', ntData("{$pgp}.socialisation.quote")])
            ->and(ntData("{$pgp}.socialisation.quote"))->toBe(ntData('norwich_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim', 'bath'])
            ->and([$tasks['tooth_brushing'][0], $tasks['tooth_brushing'][1]])->toBe([1, 'week'])
            ->and([$tasks['nail_trim'][0], $tasks['nail_trim'][1]])->toBe([1, 'month'])
            ->and([$tasks['bath'][0], $tasks['bath'][1]])->toBe([1, 'three_months'])
            ->and($tasks['tooth_brushing'][3])->toBe(ntData("{$pgp}.tooth_brushing.quote"))
            ->and($tasks['nail_trim'][3])->toBe(ntData("{$pgp}.nail_trimming.quote"))
            ->and($tasks['bath'][3])->toBe(ntData("{$pgp}.bathing.quote"))
            ->and(ntData("{$pgp}.tooth_brushing.value"))->toBe([1, 7])
            ->and(ntData("{$pgp}.nail_trimming.value"))->toBe([1, 28])
            ->and(ntData("{$pgp}.bathing.value"))->toBe([1, 84])
            ->and(ntData("{$pgp}.tooth_brushing.quote"))->toBe(ntData('norwich_terrier.suitability.petmd_teeth.quote'))
            ->and(ntData("{$pgp}.nail_trimming.quote"))->toBe(ntData('norwich_terrier.suitability.petmd_nails.quote'))
            ->and(ntData("{$pgp}.bathing.quote"))->toBe(ntData('norwich_terrier.suitability.petmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced norwich_terrier entries', function () {
        // UKC breed standard (S815): ideal weight about 12 lb = 5.4 kg (both sexes).
        expect(ntRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.4, 5.4])
            ->and(ntData('norwich_terrier.adult_weight.ukc.value'))->toEqual(['male' => [5.4, 5.4], 'female' => [5.4, 5.4]])
            ->and(ntRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ntData('norwich_terrier.adult_weight.ukc.source_id'))
            ->and(ntRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ntData('norwich_terrier.adult_weight.ukc.quote'))
            ->and(ntRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(ntData('norwich_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(ntRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(38)
            ->and(ntData('norwich_terrier.trainability.coren_rank.value'))->toBe(38)
            ->and(ntRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('norwich_terrier.lifespan.rkc')
            ->and(ntRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S813')
            ->and(ntRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(ntRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ntData('norwich_terrier.lifespan.rkc.quote'))
            // No McMillan value reached → the median stays null (never invented).
            ->and(ntData('norwich_terrier.lifespan.median_uk.value'))->toBeNull();

        // Brushing 2× a week: RKC "More than once a week" has no number → PetMD "at least twice a week" → 2.
        $grooming = ntRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S814')
            ->and($grooming['quote'])->toBe(ntData('norwich_terrier.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(ntData('proposed_game_parameters.norwich_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(ntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S814')
            ->and(ntRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwich-terrier');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::ToothBrushingFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S814 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        // Care-task frequency rows are breed-specific and skipped (teeth, nails, bath).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('norwich-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ntPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('norwich-terrier', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('norwich-terrier', 90))->toBe(LifeStage::Senior);
    });

    it('learns 1.2× as fast (Coren rank 38, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ntPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'norwich_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ntPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ntLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::NorwichTerrier)
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
        foreach (['border_collie', 'norwich_terrier'] as $breed) {
            $pin = ntPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ntLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['norwich_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('norwich_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ntPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Norwich Terrier after the Japanese Chin with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'norwich_terrier', 'japanese_chin');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'norwich_terrier', 'slug' => 'norwich-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.norwich_terrier',
            'search_keywords' => seededBreedConfig('norwich_terrier')['search_keywords'], 'sort_order' => 1010,
            'suitability' => [
                'suits' => ['apartment', 'family_pet', 'children'],
                'consider' => ['needs_mental_stimulation', 'secure_fencing', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 72 S812)', function () {
    it('samples DNA in the four standard colours only, never white, merle or brindle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('norwich_terrier')
            ->and(PetDnaService::hasAppearance('norwich_terrier'))->toBeTrue()
            ->and(config('breed_appearance.norwich_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.norwich_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->not->toContain('muzzle');

        $standard = array_column(config('breed_appearance.norwich_terrier.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('norwich_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('norwich_terrier', $traits);
            expect($prompt)->toContain('Norwich Terrier')->toContain('erect')
                ->not->toMatch('/\b(merle|tricolou?r|brindle|white patch)/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.norwich_terrier');
        expect($standard)->toBe(['red', 'wheaten', 'black and tan', 'grizzle'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['red', 'wheaten', 'black-tan', 'grizzle'])
            // Red carries the highest weight → the portrait default.
            ->and(config('breed_appearance.norwich_terrier.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.norwich_terrier.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in red', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::NorwichTerrier);

        expect($traits['coat_color'])->toBe('red')
            ->and($portrait->prompt(BreedType::NorwichTerrier))->toContain('Norwich Terrier')->toContain('red')->toContain('erect')
            ->and(BreedPortraitService::relativeFile(BreedType::NorwichTerrier))->toBe('dog/norwich-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::NorwichTerrier);
        expect($v1['prompt_anchor'])->toContain('Norwich Terrier')->toContain('red')->toContain('erect')
            ->not->toMatch('/\b(merle|tricolou?r)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('red');

        $pet = ntPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Norwich Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and keeps the breed in marketing', function () {
        $app = json_encode([
            config('breed_appearance.norwich_terrier'),
            config('breed_suitability.breeds.norwich_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwich-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '7.41', '4.02'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::NorwichTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced; RKC Breed Watch Category 2 without points → no welfare chip.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(ntData('norwich_terrier.health.breed_watch.value'))->toStartWith('RKC Breed Watch Category 2')
            ->and(ntData('proposed_game_parameters.norwich_terrier.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            // The alternatives (126 / 108 / 84) are never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwich-terrier')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([90]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an apartment, a family and children; consider mental stimulation, fencing, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::NorwichTerrier);
        expect($tags['suits'])->toBe(['apartment', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'secure_fencing', 'sheds', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('active_family')->not->toContain('low_shedding')->not->toContain('small_children')
            ->and($tags['suits'])->not->toContain('often_alone')->not->toContain('large_home')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('needs_company');

        expect(ntData('norwich_terrier.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(ntData('norwich_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(ntData('norwich_terrier.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and(ntData('norwich_terrier.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years')
            ->and(ntData('norwich_terrier.behaviour.children.quote'))->toContain('get along well with children')
            ->and(ntData('norwich_terrier.behaviour.containment.quote'))->toContain('well-fenced yard')
            // No "prey drive" wording → no chases_small_pets (Cairn Terrier precedent).
            ->and(ntData('norwich_terrier.behaviour.other_pets.quote'))->not->toContain('prey drive');
    });
});
