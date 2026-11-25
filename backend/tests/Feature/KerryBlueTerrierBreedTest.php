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
| M5-R10-139 — Kerry Blue Terrier (appended after the Bedlington Terrier: AKC 2024 No. 135)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `kerry_blue_terrier` (S1043–S1050) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.kerry_blue_terrier` (exercise 60 min — PetMD "about one hour of exercise
| every day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning × 1.2 (Coren rank
| 35, Above average), paid breed, Border Collie care rates). Claude's proposals, open for David (verified=false): the senior
| boundary / senior arrival 112 (0.75 × McMillan 2024 12.4 y, only via Wikipedia S1050) and brushing 4× a week (VCA "every other
| day", M5-R13). Puppy socialisation with the WebMD quote (S1047, M5-R17), tooth brushing every day, ear cleaning every 2 weeks,
| a groomer reminder every 6 weeks and a bath reminder every 4 weeks (all PetMD S1046); no nail task, no meal gap, no heat walk
| ban. No welfare chip, in marketing; suits family_pet, children, low_shedding; consider frequent_grooming, needs_company; blue
| portrait (one of four standard shades).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function kbData(string $path): mixed
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

/** @return array<string, mixed> the Kerry Blue Terrier seeder row of (stage, from, key) */
function kbRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'kerry-blue-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("kerry-blue-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function kbPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->kerryBlueTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function kbPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function kbLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::KerryBlueTerrier;
        $config = BreedConfig::where('breed_slug', 'kerry-blue-terrier')->sole();

        expect($breed->value)->toBe('kerry_blue_terrier')
            ->and($breed->slug())->toBe('kerry-blue-terrier')
            ->and(BreedType::fromSlug('kerry-blue-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1300, 'breeds.kerry_blue_terrier'])
            ->and($config->search_keywords)->toBe(['kerry blue terrier', 'kerry blue', 'kerry blue terier', 'kerry'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) kbData('proposed_game_parameters.kerry_blue_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000)
            ->and(BreedConfig::where('breed_slug', 'bedlington-terrier')->value('sort_order') + 10)->toBe(1300)
            ->and(__('breeds.kerry_blue_terrier', [], 'en'))->toBe('Kerry Blue Terrier')
            ->and(__('breeds.kerry_blue_terrier', [], 'sl'))->toBe('Kerry blue terier');

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Kerry Blue Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = kbPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('kerry_blue_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'kerry_blue_terier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_10_120000_add_kerry_blue_terrier_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'kerry_blue_terrier']))->toThrow(QueryException::class);
        // The Bedlington Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bedlington_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'kerry_blue_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::KerryBlueTerrier)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'kerry-blue-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier')->count());

        BreedConfig::where('breed_slug', 'kerry-blue-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'kerry-blue-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'kerry-blue-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'kerry-blue-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier');
        $own = ['CONFIRMED_R10_KERRY_BLUE_TERRIER', 'PROPOSED_R10_KERRY_BLUE_TERRIER_SENIOR', 'PROPOSED_R13_KERRY_BLUE_TERRIER'];

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'kerry-blue-terrier'));
        foreach ($own as $name) {
            expect($others->where('decision', constant(BreedStageParamsSeeder::class.'::'.$name))->all())->toBe([], $name);
        }
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, $own, true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.kerry_blue_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(kbRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(kbData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(kbRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(kbData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        foreach (['young', 'adult'] as $stage) {
            expect(kbRow($stage, 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
                ->and(kbRow($stage, 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', kbData("{$pgp}.stage_boundaries_months.derived_from")))
                ->and(kbRow($stage, 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_KERRY_BLUE_TERRIER);
        }
        expect(kbRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(kbData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12.4 y (McMillan 2024, only via Wikipedia S1050, tier C) = 111.6 → 112 — Claude's suggestion → verified=false.
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(kbData('kerry_blue_terrier.lifespan.senior_from.months'))
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(kbData("{$pgp}.senior_boundary_months.value"))
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * kbRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(kbData('kerry_blue_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1050'])
            ->and(kbData('kerry_blue_terrier.lifespan.median_uk.source_id'))->toBeNull()
            // VCA 10 y → 90, WebMD 12 y → 108 and Dogs Trust > 7 y → 84 are David's alternatives, never seeded.
            ->and(kbData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_vca_10_y' => 90, 'senior_webmd_12_y' => 108, 'senior_dogs_trust_7_y' => 84])
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S1050')
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(kbRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_KERRY_BLUE_TERRIER_SENIOR)
            ->and(kbRow('senior', 0, StageParamKey::ArrivalAgeMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_KERRY_BLUE_TERRIER_SENIOR)
            ->and(kbData("{$pgp}.senior_boundary_months.decision"))->toBe(BreedStageParamsSeeder::PROPOSED_R10_KERRY_BLUE_TERRIER_SENIOR)
            ->and(BreedStageParamsSeeder::PROPOSED_R10_KERRY_BLUE_TERRIER_SENIOR)->toStartWith('predlog Claude 2026-11-25')->toContain('čaka Davida')
            ->and(kbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(112);

        expect(kbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(kbData("{$pgp}.exercise_minutes_adult.value"))
            ->and(kbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(kbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(kbData('kerry_blue_terrier.exercise.adult.quote'))
            ->and(kbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1046')
            ->and(kbRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(kbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(kbData("{$pgp}.exercise_minutes_senior.value"))
            ->and(kbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(kbRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(kbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(kbData("{$pgp}.learning_multiplier.value"))
            ->and(kbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2)
            ->and(kbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(kbData('kerry_blue_terrier.trainability.coren_rank.value'))
            ->and(kbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(35);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_KERRY_BLUE_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('kerry-blue-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.kerry_blue_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) kbData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 4× a week (VCA, M5-R13, Claude's proposal), puppy socialisation
        // (WebMD S1047, M5-R17), tooth brushing every day, ear cleaning every 2 weeks, a groomer reminder every 6 weeks and a bath
        // reminder every 4 weeks (all PetMD S1046). No nail task (no frequency), no meal gap.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['kerry_blue_terrier'];
        expect(json_encode([kbData('kerry_blue_terrier'), kbData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('not built"')
            ->and(kbData("{$pgp}.grooming.value"))->toBe(4)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('kerry_blue_terrier')
            ->and(kbData('kerry_blue_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['kerry_blue_terrier'])->toBe(['S1047', kbData("{$pgp}.socialisation.quote")])
            ->and(kbData("{$pgp}.socialisation.quote"))->toBe(kbData('kerry_blue_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'groomer_visit', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1046', kbData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(kbData("{$pgp}.tooth_brushing.quote"))->toBe(kbData('kerry_blue_terrier.suitability.petmd_teeth.quote'))
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'two_weeks', 'S1046', kbData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(kbData("{$pgp}.ear_care.quote"))->toBe(kbData('kerry_blue_terrier.suitability.petmd_ears.quote'))
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'six_weeks', 'S1046', kbData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(kbData("{$pgp}.professional_grooming.quote"))->toBe(kbData('kerry_blue_terrier.suitability.petmd_groomer.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S1046', kbData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(kbData("{$pgp}.bathing.quote"))->toBe(kbData('kerry_blue_terrier.suitability.petmd_bath.quote'))
            ->and($tasks)->not->toHaveKey('nail_trim')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('kerry_blue_terrier');
    });

    it('takes the breed-level numbers from the sourced kerry_blue_terrier entries', function () {
        expect(kbRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([15, 18])
            ->and(kbData('kerry_blue_terrier.adult_weight.fci.value'))->toEqual(['male' => [15, 18], 'female' => [15, 18]])
            ->and(kbRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(kbData('kerry_blue_terrier.adult_weight.fci.quote'))
            ->and(kbRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(kbRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(kbData('kerry_blue_terrier.growth.adult_weight_reached.quote'))
            ->and(kbRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('kerry_blue_terrier.lifespan.mcmillan_2024_wikipedia')
            ->and(kbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.4)
            ->and(kbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(kbData('kerry_blue_terrier.lifespan.mcmillan_2024_wikipedia.value'))
            ->and(kbRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(kbData('kerry_blue_terrier.lifespan.mcmillan_2024_wikipedia.quote'));

        $grooming = kbRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(4)
            ->and($grooming['source_id'])->toBe('S1048')
            ->and($grooming['quote'])->toBe(kbData('kerry_blue_terrier.suitability.vca_grooming.quote'))
            ->and($grooming['quote'])->toBe(kbData('proposed_game_parameters.kerry_blue_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeFalse()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R13_KERRY_BLUE_TERRIER)
            ->and(kbData('proposed_game_parameters.kerry_blue_terrier.grooming.decision'))->toBe(BreedStageParamsSeeder::PROPOSED_R13_KERRY_BLUE_TERRIER)
            ->and($grooming['notes'])->toContain('every other day')->toContain('ceil(4 / 2)')
            ->and(kbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1047')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe(collect([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::EarCleaningFrequency->value => [1, 14],
            StageParamKey::GroomerVisitFrequency->value => [1, 42],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])->sortKeys()->all())
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the senior boundary / senior arrival and the brushing (Claude's suggestions) and the general teething chewing chance.
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing([
                'senior.'.StageParamKey::StartsAtMonths->value,
                'senior.'.StageParamKey::ArrivalAgeMonths->value,
                'all.'.StageParamKey::GroomingSessionsPerWeek->value,
                'puppy.'.StageParamKey::ChewingChancePerDay->value,
            ]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek,
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::GroomerVisitFrequency,
            StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('kerry-blue-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = kbPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 111 months' => [111, LifeStage::Adult, 60, 6000],
        'senior 112 months' => [112, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 112 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('kerry-blue-terrier', 111))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('kerry-blue-terrier', 112))->toBe(LifeStage::Senior);
    });

    it('learns faster than the mixed breed (Coren rank 35, Above average → × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = kbPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'kerry_blue_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = kbPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(kbLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::KerryBlueTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 112 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'kerry_blue_terrier'] as $breed) {
            $pin = kbPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(kbLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['kerry_blue_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(112)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('kerry_blue_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = kbPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Kerry Blue Terrier after the Bedlington Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'kerry_blue_terrier', 'bedlington_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'kerry_blue_terrier', 'slug' => 'kerry-blue-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.kerry_blue_terrier',
            'search_keywords' => seededBreedConfig('kerry_blue_terrier')['search_keywords'], 'sort_order' => 1300,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 3 S1043)', function () {
    it('samples DNA only in the four blue shades of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('kerry_blue_terrier')
            ->and(PetDnaService::hasAppearance('kerry_blue_terrier'))->toBeTrue()
            ->and(config('breed_appearance.kerry_blue_terrier.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.kerry_blue_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('kerry_blue_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('kerry_blue_terrier', $traits);
            expect($prompt)->toContain('Kerry Blue Terrier')->toContain('V-shaped')->toContain('blue')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|tan|white|wheaten|black coat|solid black|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.kerry_blue_terrier');
        expect($standard)->toBe(['blue (blue-grey)', 'blue with black points (darker muzzle, head, ears, tail and feet)', 'deep slate blue', 'light blue grey'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['blue', 'blue-black-points', 'deep-slate-blue', 'light-blue-grey'])
            ->and(array_column($colours, 'en'))->toBe(['Blue', 'Blue with black points', 'Deep slate blue', 'Light blue-grey'])
            ->and(array_column($colours, 'sl'))->toBe(['Moder', 'Moder s črnimi odtenki', 'Temno skrilasto moder', 'Svetlo modrosiv'])
            ->and(array_column(config('breed_appearance.kerry_blue_terrier.traits.coat_color'), 'weight'))->toBe([40, 30, 15, 15])
            ->and(kbData('kerry_blue_terrier.appearance.colours.value'))->toBe(['blue', 'blue with black points', 'deep slate blue', 'light blue grey']);
    });

    it('draws the register portrait blue (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::KerryBlueTerrier);

        expect($traits['coat_color'])->toBe('blue (blue-grey)')
            ->and($portrait->prompt(BreedType::KerryBlueTerrier))->toContain('Kerry Blue Terrier')->toContain('blue')
            ->and(BreedPortraitService::relativeFile(BreedType::KerryBlueTerrier))->toBe('dog/kerry-blue-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::KerryBlueTerrier);
        expect($v1['prompt_anchor'])->toContain('Kerry Blue Terrier')->toContain('blue')->toContain('V-shaped')
            ->not->toMatch('/\b(merle|cropped|tan|white)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('blue');

        $pet = kbPet(112, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Kerry Blue Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.kerry_blue_terrier'),
            config('breed_suitability.breeds.kerry_blue_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::KerryBlueTerrier);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(kbData('proposed_game_parameters.kerry_blue_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'kerry-blue-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(90)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families, children and low shedding; consider grooming and company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::KerryBlueTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'low_shedding'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('active_family')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('long_daily_exercise')->not->toContain('shorter_lifespan')
            ->not->toContain('secure_fencing')->not->toContain('chases_small_pets')->not->toContain('experienced_owner');

        $config = config('breed_suitability.breeds.kerry_blue_terrier');
        $proposed = kbData('proposed_game_parameters.kerry_blue_terrier.suitability_tags');
        foreach (['suits', 'consider'] as $kind) {
            expect(array_map(fn (array $t) => [$t['tag'], $t['source_ids'], $t['refs']], $config[$kind]))
                ->toBe(array_map(fn (array $t) => [$t['tag'], $t['source_ids'], $t['refs']], $proposed[$kind]));
        }
        expect(kbData('kerry_blue_terrier.suitability.webmd_shedding.quote'))->toContain('sheds very little')
            ->and(kbData('kerry_blue_terrier.behaviour.children.quote'))->toContain('good with children')
            ->and(kbData('kerry_blue_terrier.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(kbData('kerry_blue_terrier.behaviour.other_pets.quote'))->not->toContain('prey drive');
    });
});
