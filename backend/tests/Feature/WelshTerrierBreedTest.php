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
| M5-R10-127 — Welsh Terrier (appended after the Mudi: AKC 2024 No. 123)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `welsh_terrier` (S954–S959) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.welsh_terrier` (exercise 60 min — RKC "Up to 1 hour per day" —, senior
| 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12 years"), arrival
| 2 / 9 / 36 / 108, learning × 1.0 (Coren "Average" tier), paid breed, Border Collie care rates, brushing 3× a week (RKC
| "More than once a week" → PDSA "two-three times per week", M5-R13), puppy socialisation with the PDSA quote (S957, M5-R17),
| tooth brushing every day (WebMD S959, M5-R15), groomer + bath reminders every 8 weeks (PetMD S958, M5-R19 / R20); no nail /
| ear task (no frequency), no meal gap (no quote), no heat warning. No welfare chip, in marketing; suits active_family,
| family_pet, children; consider defensive_older_children (new key), secure_fencing, sheds, chases_small_pets; black-and-tan portrait
| (one of two FCI colours), natural tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function wtData(string $path): mixed
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

/** @return array<string, mixed> the Welsh Terrier seeder row of (stage, from, key) */
function wtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'welsh-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("welsh-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function wtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->welshTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function wtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function wtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::WelshTerrier;
        $config = BreedConfig::where('breed_slug', 'welsh-terrier')->sole();

        expect($breed->value)->toBe('welsh_terrier')
            ->and($breed->slug())->toBe('welsh-terrier')
            ->and(BreedType::fromSlug('welsh-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1180, 'breeds.welsh_terrier'])
            ->and($config->search_keywords)->toBe(['welsh terrier', 'welsh', 'welshie', 'valižanski terier', 'valizanski terier', 'velški terier', 'welsh terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) wtData('proposed_game_parameters.welsh_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = wtData('proposed_game_parameters.welsh_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Welsh Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = wtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('welsh_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'welsh']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'welsh_springer_spaniel']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_21_120000_add_welsh_terrier_breed.php');
        // down() refuses while a Welsh Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'welsh_terrier']))->toThrow(QueryException::class);
        // The Mudi (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'mudi']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'welsh_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::WelshTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'welsh-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'welsh-terrier')->count());

        BreedConfig::where('breed_slug', 'welsh-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'welsh-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'welsh-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'welsh-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'welsh-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'welsh-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WELSH_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_WELSH_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.welsh_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(wtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(wtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(wtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(wtRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wtData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S956 — no McMillan 2024 / VetCompass value) → confirmed rule.
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(wtData('welsh_terrier.lifespan.senior_from.months'))
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * wtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(wtData('welsh_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S956'])
            // The Dogs Trust rule of thumb is background only — never the seeded boundary.
            ->and(wtData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', wtData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_WELSH_TERRIER)
            ->and(wtRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(wtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(wtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(wtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(wtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wtData('welsh_terrier.exercise.adult.quote'))
            ->and(wtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wtData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(wtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S956')
            ->and(wtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(wtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(wtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(wtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(wtData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(wtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(wtData("{$pgp}.learning_multiplier.value"))
            ->and(wtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WELSH_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('welsh-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.welsh_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) wtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 3× a week (M5-R13, RKC → PDSA), puppy socialisation with the PDSA
        // quote (M5-R17), tooth brushing every day (WebMD, M5-R15), groomer + bath reminders every 8 weeks (PetMD, M5-R19 / R20),
        // the fence below ground = the secure_fencing chip. No nail / ear task (no frequency), no meal gap (no quote), no heat warning.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['welsh_terrier'];
        expect(json_encode([wtData('welsh_terrier'), wtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(wtData("{$pgp}.grooming.value"))->toBe(3)
            ->and(wtData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('welsh_terrier')
            ->and(wtData('welsh_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['welsh_terrier'])->toBe(['S957', wtData("{$pgp}.socialisation.quote")])
            ->and(wtData("{$pgp}.socialisation.quote"))->toBe(wtData('welsh_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'groomer_visit', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S959', wtData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(wtData("{$pgp}.tooth_brushing.quote"))->toBe(wtData('welsh_terrier.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'two_months', 'S958', wtData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(wtData("{$pgp}.professional_grooming.quote"))->toBe(wtData('welsh_terrier.suitability.petmd_professional_grooming.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'two_months', 'S958', wtData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(wtData("{$pgp}.bathing.quote"))->toBe(wtData('welsh_terrier.suitability.petmd_bath.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('welsh_terrier');
    });

    it('takes the breed-level numbers from the sourced welsh_terrier entries', function () {
        // FCI-Standard N°78 (S955): 9–9.5 kg for both sexes.
        expect(wtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.0, 9.5])
            ->and(wtData('welsh_terrier.adult_weight.fci.value'))->toEqual(['male' => [9, 9.5], 'female' => [9, 9.5]])
            ->and(wtRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(wtData('welsh_terrier.adult_weight.fci.source_id'))
            ->and(wtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(wtData('welsh_terrier.adult_weight.fci.quote'))
            ->and(wtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(wtData('welsh_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren "Average" tier, no readable rank number → rank not recorded, 1.0.
            ->and(wtRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(wtData('welsh_terrier.trainability.coren_rank.value'))->toBeNull()
            ->and(wtData('welsh_terrier.trainability.coren_tier.value'))->toBe('Average Working/Obedience Intelligence')
            ->and(wtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('welsh_terrier.lifespan.rkc')
            ->and(wtRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S956')
            ->and(wtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(wtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(wtData('welsh_terrier.lifespan.rkc.quote'));

        // Brushing 3× a week: RKC "More than once a week" has no number → PDSA "two-three times per week" → 3.
        $grooming = wtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S957')
            ->and($grooming['quote'])->toBe(wtData('welsh_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(wtData('proposed_game_parameters.welsh_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(wtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(wtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S957')
            ->and(wtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'welsh-terrier');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 56],
            StageParamKey::GroomerVisitFrequency->value => [1, 56],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S958', 'S959'])
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
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the socialisation quote (PDSA S957) for this breed.
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R19 / R20: tooth brushing (WebMD S959), groomer + bath reminders (PetMD S958).
            StageParamKey::ToothBrushingFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('welsh-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = wtPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months like the Pointer, later than the Mudi', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('welsh-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('welsh-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pointer', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mudi', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('welsh-terrier', 90))->toBe(LifeStage::Adult);
    });

    it('learns like the mixed breed (Coren "Average" tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = wtPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'welsh_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = wtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(wtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::WelshTerrier)
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
        foreach (['border_collie', 'welsh_terrier'] as $breed) {
            $pin = wtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(wtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['welsh_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('welsh_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = wtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Welsh Terrier after the Mudi with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'welsh_terrier', 'mudi');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'welsh_terrier', 'slug' => 'welsh-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.welsh_terrier',
            'search_keywords' => seededBreedConfig('welsh_terrier')['search_keywords'], 'sort_order' => 1180,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['defensive_older_children', 'secure_fencing', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N°78 S955)', function () {
    it('samples DNA only in the two FCI colours, never a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('welsh_terrier')
            ->and(PetDnaService::hasAppearance('welsh_terrier'))->toBeTrue()
            ->and(config('breed_appearance.welsh_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.welsh_terrier.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.welsh_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('welsh_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('welsh_terrier', $traits);
            expect($prompt)->toContain('Welsh Terrier')->toContain('V-shaped')
                ->not->toMatch('/\b(tricolou?r|merle|brindle|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.welsh_terrier');
        expect($standard)->toBe(['black and tan', 'black grizzle and tan'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-tan', 'black-grizzle-tan'])
            ->and(wtData('welsh_terrier.appearance.colours.value'))->toBe($standard)
            ->and(wtData('welsh_terrier.appearance.colours.quote'))->toBe('Black and tan for preference, or black grizzle and tan, free from black pencilling on toes.');
    });

    it('draws the register portrait black and tan (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::WelshTerrier);

        expect($traits['coat_color'])->toBe('black and tan')
            ->and($portrait->prompt(BreedType::WelshTerrier))->toContain('Welsh Terrier')->toContain('black and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::WelshTerrier))->toBe('dog/welsh-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::WelshTerrier);
        expect($v1['prompt_anchor'])->toContain('Welsh Terrier')->toContain('black and tan')
            ->not->toMatch('/\b(tricolou?r|merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black and tan');

        $pet = wtPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Welsh Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.welsh_terrier'),
            config('breed_suitability.breeds.welsh_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'welsh-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::WelshTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed for conformation problems → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(wtData('proposed_game_parameters.welsh_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'welsh-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families and children; consider small children, a fence below ground, shedding and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::WelshTerrier);
        expect(config('breed_suitability.vocabulary.defensive_older_children'))->toBe('consider')
            ->and($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['defensive_older_children', 'secure_fencing', 'sheds', 'chases_small_pets'])
            // WebMD advises against small children → never small_children; RKC "Small house" → never apartment / large_home;
            // 60 min → no long_daily_exercise; 3× a week brushing → no frequent_grooming; no separation statement → no needs_company.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('not_for_small_children')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming')
            ->not->toContain('needs_company')->not->toContain('shorter_lifespan')->not->toContain('experienced_owner');

        expect(wtData('welsh_terrier.behaviour.family.quote'))->toBe('They love children and are always up for any game on offer, making them excellent family pets.')
            ->and(wtData('welsh_terrier.behaviour.small_children.quote'))->toBe("they're not recommended for households with small children due to their defensive reflexes.")
            ->and(wtData('welsh_terrier.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(wtData('welsh_terrier.exercise.fencing.quote'))->toBe('Fences should extend 6" belowground to keep a Welsh terrier from tunneling underneath.')
            ->and(wtData('welsh_terrier.lifespan.rkc.quote'))->toBe('Over 12 years');
    });
});
