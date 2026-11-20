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
| M5-R10-129 — American Hairless Terrier (appended after the Norwegian Elkhound: AKC 2024 No. 125)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `american_hairless_terrier` (S967–S979) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.american_hairless_terrier` (exercise 20 min — Dogs NSW
| "Exercise: 20 min/day"; no RKC / PDSA page exists —, senior 15 min, puppy 10 min × age capped at 20, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, puppy socialisation with the PetMD
| quote (S971, M5-R17), tooth brushing every day (WebMD S972, M5-R15), nail trim every week (AHTCA S977, M5-R19), bath reminder
| every week (PetMD S971, M5-R19); no brushing (hairless), no ear / groomer task, no meal gap, no heat warning). The senior
| boundary / senior arrival 126 (0.75 × PetMD lower bound "14–16 years") is Claude's suggestion, verified=false, open for
| David. Suitability: suits children; consider sun_protection / cold_sensitive / needs_mental_stimulation / chases_small_pets;
| hairless portrait, pink skin with black spots; no welfare chip, in marketing. Not FCI-recognised.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ahData(string $path): mixed
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

/** @return array<string, mixed> the American Hairless Terrier seeder row of (stage, from, key) */
function ahRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'american-hairless-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("american-hairless-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ahPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->americanHairlessTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ahPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ahLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AmericanHairlessTerrier;
        $config = BreedConfig::where('breed_slug', 'american-hairless-terrier')->sole();

        expect($breed->value)->toBe('american_hairless_terrier')
            ->and($breed->slug())->toBe('american-hairless-terrier')
            ->and(BreedType::fromSlug('american-hairless-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1200, 'breeds.american_hairless_terrier'])
            ->and($config->search_keywords)->toBe(['american hairless terrier', 'hairless terrier', 'aht', 'ameriški goli terier', 'ameriski goli terier', 'goli terier'])
            // Legacy fallback = the adult step goal (20 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ahData('proposed_game_parameters.american_hairless_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(2000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ahData('proposed_game_parameters.american_hairless_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an American Hairless Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ahPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('american_hairless_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'aht']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'hairless_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_25_120000_add_american_hairless_terrier_breed.php');
        // down() refuses while an American Hairless Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'american_hairless_terrier']))->toThrow(QueryException::class);
        // The Norwegian Elkhound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'norwegian_elkhound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'american_hairless_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AmericanHairlessTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'american-hairless-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-hairless-terrier')->count());

        BreedConfig::where('breed_slug', 'american-hairless-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'american-hairless-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'american-hairless-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'american-hairless-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-hairless-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'american-hairless-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_HAIRLESS_TERRIER)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_AMERICAN_HAIRLESS_TERRIER_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_AMERICAN_HAIRLESS_TERRIER', 'PROPOSED_R10_AMERICAN_HAIRLESS_TERRIER_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.american_hairless_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ahRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ahData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ahRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ahData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ahRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ahData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 14 y (PetMD lower bound "14–16 years", S971) = 126 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value exists) → verified=false, open for David.
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ahData('american_hairless_terrier.lifespan.senior_from.months'))
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ahData("{$pgp}.senior_boundary_months.value"))
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ahRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ahData('american_hairless_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S971'])
            ->and(ahData('american_hairless_terrier.lifespan.mcmillan_2024.source_id'))->toBeNull()
            // Dogs Victoria 12–14 y → 108 is David's alternative, never seeded.
            ->and(ahData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_victoria_12_y"))->toBe(108)
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S971')
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(ahRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_AMERICAN_HAIRLESS_TERRIER_SENIOR)
            ->and(ahData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_AMERICAN_HAIRLESS_TERRIER_SENIOR)
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(126);

        expect(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ahData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(20)
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ahData('american_hairless_terrier.exercise.adult.quote'))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ahData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S973')
            ->and(ahRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(20)
            ->and(ahRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ahData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ahRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(15)
            ->and(ahRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ahData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(20)
            ->and(ahRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ahData("{$pgp}.learning_multiplier.value"))
            ->and(ahRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_HAIRLESS_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('american-hairless-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.american_hairless_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ahData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: puppy socialisation with the PetMD quote (M5-R17), tooth brushing every
        // day (WebMD, M5-R15), nail trim every week (AHTCA, M5-R19), the weekly bath reminder (PetMD, M5-R19). No brushing
        // (hairless, no frequency), no ear / groomer task, no meal gap (no quote), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['american_hairless_terrier'];
        expect(json_encode([ahData('american_hairless_terrier'), ahData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ahData($pgp))->not->toHaveKey('grooming')
            ->and(BreedStageParamsSeeder::DOG_GROOMING)->not->toHaveKey('american_hairless_terrier')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('american_hairless_terrier')
            ->and(ahData('american_hairless_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['american_hairless_terrier'])->toBe(['S971', ahData("{$pgp}.socialisation.quote")])
            ->and(ahData("{$pgp}.socialisation.quote"))->toBe(ahData('american_hairless_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S972', ahData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(ahData("{$pgp}.tooth_brushing.quote"))->toBe(ahData('american_hairless_terrier.suitability.webmd_teeth_nails.quote'))
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'week', 'S977', ahData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(ahData("{$pgp}.nail_trimming.quote"))->toBe(ahData('american_hairless_terrier.suitability.ahtca_nails.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'week', 'S971', ahData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(ahData("{$pgp}.bathing.quote"))->toBe(ahData('american_hairless_terrier.suitability.petmd_bath.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('american_hairless_terrier');
    });

    it('takes the breed-level numbers from the sourced american_hairless_terrier entries', function () {
        // PetMD (S971): 12–28 lb → 5.4–12.7 kg (the standards give no weight).
        expect(ahRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.4, 12.7])
            ->and(ahData('american_hairless_terrier.adult_weight.petmd.value'))->toEqual(['male' => [5.4, 12.7], 'female' => [5.4, 12.7]])
            ->and(ahRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ahData('american_hairless_terrier.adult_weight.petmd.source_id'))
            ->and(ahRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ahData('american_hairless_terrier.adult_weight.petmd.quote'))
            ->and(ahRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(ahData('american_hairless_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(ahRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(ahData('american_hairless_terrier.growth.adult_weight_reached.quote'))
            // Not ranked by Coren → no rank, learning 1.0.
            ->and(ahRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(ahData('american_hairless_terrier.trainability.coren_rank.value'))->toBeNull()
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('american_hairless_terrier.lifespan.petmd')
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S971')
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.0)
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ahData('american_hairless_terrier.lifespan.petmd.quote'))
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S971')
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-hairless-terrier');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::GroomingSessionsPerWeek->value)->all())->toBe([]);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 7],
            StageParamKey::NailTrimFrequency->value => [1, 7],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S971', 'S972', 'S977'])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the senior boundary / senior arrival (Claude's suggestion) and the general teething chewing chance.
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing([
                'senior.'.StageParamKey::StartsAtMonths->value,
                'senior.'.StageParamKey::ArrivalAgeMonths->value,
                'puppy.'.StageParamKey::ChewingChancePerDay->value,
            ]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R17: the socialisation quote (PetMD S971) for this breed.
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R19: tooth brushing (WebMD S972), nail trim (AHTCA S977), bath reminder (PetMD S971).
            StageParamKey::ToothBrushingFrequency, StageParamKey::NailTrimFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('american-hairless-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 20 minutes = 2,000 steps and 15 minutes = 1,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ahPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (capped at adult already)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 20, 2000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 20, 2000],
        'adult 36 months' => [36, LifeStage::Adult, 20, 2000],
        'adult 125 months' => [125, LifeStage::Adult, 20, 2000],
        'senior 126 months' => [126, LifeStage::Senior, 15, 1500],
    ]);

    it('becomes senior at 126 months, later than the Norwegian Elkhound', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('american-hairless-terrier', 125))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('american-hairless-terrier', 126))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('norwegian-elkhound', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('american-hairless-terrier', 108))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ahPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'american_hairless_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ahPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ahLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AmericanHairlessTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 126 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'american_hairless_terrier'] as $breed) {
            $pin = ahPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ahLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['american_hairless_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(126)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('american_hairless_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ahPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the American Hairless Terrier after the Norwegian Elkhound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'american_hairless_terrier', 'norwegian_elkhound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'american_hairless_terrier', 'slug' => 'american-hairless-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.american_hairless_terrier',
            'search_keywords' => seededBreedConfig('american_hairless_terrier')['search_keywords'], 'sort_order' => 1200,
            'suitability' => [
                'suits' => ['children'],
                'consider' => ['sun_protection', 'cold_sensitive', 'needs_mental_stimulation', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — UKC S968, Official Standard S967)', function () {
    it('samples DNA in the four parti-coloured skins of the appearance block only, hairless with a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('american_hairless_terrier')
            ->and(PetDnaService::hasAppearance('american_hairless_terrier'))->toBeTrue()
            ->and(config('breed_appearance.american_hairless_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.american_hairless_terrier.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('american_hairless_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('hairless')
                ->and($traits['tail'])->toContain('undocked')
                ->and($traits['coat_color'])->toContain('skin');

            $prompt = $prompts->imagePrompt('american_hairless_terrier', $traits);
            expect($prompt)->toContain('American Hairless Terrier')->toContain('erect')
                ->not->toMatch('/\b(merle|albino|brindle|cropped|docked tail|long coat|fluffy|sable|bobtail)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.american_hairless_terrier.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(4)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.american_hairless_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.american_hairless_terrier'), 'key'))->toBe(['pink-black-spots', 'pink-chocolate-spots', 'grey-pink-patches', 'pink-tan-spots'])
            ->and(ahData('american_hairless_terrier.appearance.colours.value'))->toBe($standard)
            ->and(ahData('american_hairless_terrier.appearance.colours.quote'))->toContain('parti-colored');
    });

    it('draws the register portrait with pink skin and black spots', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AmericanHairlessTerrier);

        expect($traits['coat_color'])->toBe('parti-coloured: pink skin with black spots')
            ->and($portrait->prompt(BreedType::AmericanHairlessTerrier))->toContain('American Hairless Terrier')->toContain('hairless')
            ->and(BreedPortraitService::relativeFile(BreedType::AmericanHairlessTerrier))->toBe('dog/american-hairless-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AmericanHairlessTerrier);
        expect($v1['prompt_anchor'])->toContain('American Hairless Terrier')->toContain('hairless')->toContain('undocked')
            ->not->toMatch('/\b(merle|albino|brindle|docked tail|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('parti-coloured pink skin with black spots');

        $pet = ahPet(126, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('American Hairless Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.american_hairless_terrier'),
            config('breed_suitability.breeds.american_hairless_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-hairless-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AmericanHairlessTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; 12–16 years → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(ahData('proposed_game_parameters.american_hairless_terrier.marketing.value'))->toContain('in marketing: yes')
            // Marketed for allergy sufferers by others — never a hypoallergenic / low_shedding tag here.
            ->and($tags['suits'])->not->toContain('low_shedding')
            ->and(ahData('american_hairless_terrier.suitability.allergies_note.notes'))->toContain('never a hypoallergenic')
            // The alternatives (84 Dogs Trust, 108 Dogs Victoria) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-hairless-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children; consider sun, cold, mental stimulation and small pets; never small_children, apartment or family_pet', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AmericanHairlessTerrier);
        expect($tags['suits'])->toBe(['children'])
            ->and($tags['consider'])->toBe(['sun_protection', 'cold_sensitive', 'needs_mental_stimulation', 'chases_small_pets'])
            // PetMD asks for supervision of young children → never small_children; no RKC home key fact → never apartment;
            // no "family pet" statement → no family_pet; conflicting statements on other pets → no other_pets.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('family_pet')
            ->not->toContain('other_pets')->not->toContain('large_home')->not->toContain('active_family')
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('needs_company')
            ->not->toContain('secure_fencing')->not->toContain('chews_when_bored')->not->toContain('frequent_grooming');

        expect(ahData('american_hairless_terrier.behaviour.children.quote'))->toBe('These dogs can be great playmates for children thanks to their rambunctious energy.')
            ->and(ahData('american_hairless_terrier.behaviour.children.notes'))->toContain('supervise young children')
            ->and(ahData('american_hairless_terrier.suitability.ahtca_sun.quote'))->toContain('Sunscreen')
            ->and(ahData('american_hairless_terrier.health.bare_skin.source_id'))->toBe('S977')
            ->and(ahData('american_hairless_terrier.suitability.petmd_cold.quote'))->toContain('sweater')
            ->and(ahData('american_hairless_terrier.behaviour.mental_stimulation.quote'))->toContain('mental stimulation')
            ->and(ahData('american_hairless_terrier.behaviour.other_pets.quote'))->toMatch('/(high|strong) prey drive/');
    });
});
