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
| M5-R10-124 — Spinone Italiano (appended after the Xoloitzcuintli: AKC 2024 No. 120)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `spinone_italiano` (S934–S941) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.spinone_italiano` (exercise 120 min — PDSA "ideally more
| than 2 hours per day", the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages
| 9 / 36 / 90 (0.75 × PDSA lower bound "Over 10 years"), arrival 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren),
| paid breed, Border Collie care rates, brushing 3× a week (PDSA "2 or 3 times per week", M5-R13), puppy socialisation
| with the PDSA quote (S936, M5-R17), meal / exercise gap (PetMD S937, M5-R16), tooth brushing every day and nail
| trimming once a week (WebMD S938, M5-R15 / M5-R19); no ear / bath / groomer task (no frequency). No welfare chip, in
| marketing; suits active_family, family_pet, children; consider long_daily_exercise, needs_company; white-and-orange
| portrait (one of five FCI colours), natural tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function spiData(string $path): mixed
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

/** @return array<string, mixed> the Spinone Italiano seeder row of (stage, from, key) */
function spiRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'spinone-italiano'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("spinone_italiano.{$stage}.{$from}.{$key->value}");

    return $row;
}

function spiPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->spinoneItaliano()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function spiPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function spiLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SpinoneItaliano;
        $config = BreedConfig::where('breed_slug', 'spinone-italiano')->sole();

        expect($breed->value)->toBe('spinone_italiano')
            ->and($breed->slug())->toBe('spinone-italiano')
            ->and(BreedType::fromSlug('spinone-italiano'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1150, 'breeds.spinone_italiano'])
            ->and($config->search_keywords)->toBe(['spinone italiano', 'italian spinone', 'spinone', 'spinoni', 'italian griffon', 'italian wire-haired pointer', 'italijanski ostrodlaki ptičar', 'italijanski ostrodlaki pticar', 'ptičar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) spiData('proposed_game_parameters.spinone_italiano.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = spiData('proposed_game_parameters.spinone_italiano.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Spinone Italiano pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = spiPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('spinone_italiano');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'italian_spinone']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'spinone']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_18_120000_add_spinone_italiano_breed.php');
        // down() refuses while a Spinone Italiano exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'spinone_italiano']))->toThrow(QueryException::class);
        // The Xoloitzcuintli (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'xoloitzcuintli']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'spinone_italiano']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SpinoneItaliano);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'spinone-italiano')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'spinone-italiano')->count());

        BreedConfig::where('breed_slug', 'spinone-italiano')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'spinone-italiano')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'spinone-italiano')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'spinone-italiano');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'spinone-italiano');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'spinone-italiano'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SPINONE_ITALIANO)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_SPINONE_ITALIANO');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.spinone_italiano';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(spiRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(spiData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(spiRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(spiData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(spiRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(spiData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PDSA lower bound "Over 10 years", S936 — no McMillan 2024 / VetCompass value) → confirmed rule.
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(spiData('spinone_italiano.lifespan.senior_from.months'))
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * spiRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(spiData('spinone_italiano.lifespan.senior_from.derived_from'))->toBe(['S11', 'S936'])
            // The Dogs Trust rule of thumb and the tier-C KC survey are background only — never the seeded boundary.
            ->and(spiData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(spiData("{$pgp}.stage_boundaries_months.alternatives.senior_kc_survey_2004_8_7_y_tier_c"))->toBe(78)
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', spiData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SPINONE_ITALIANO)
            ->and(spiRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(spiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(spiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(spiData("{$pgp}.exercise_minutes_adult.value"))
            ->and(spiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(spiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(spiData('spinone_italiano.exercise.adult.quote'))
            ->and(spiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(spiData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(spiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S936')
            ->and(spiRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(spiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(spiData("{$pgp}.exercise_minutes_senior.value"))
            ->and(spiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(spiRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(spiData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(spiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(spiData("{$pgp}.learning_multiplier.value"))
            ->and(spiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SPINONE_ITALIANO) {
                $decided++;
                expect($row['breed_slug'])->toBe('spinone-italiano')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.spinone_italiano.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) spiData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 3× a week (M5-R13, PDSA), puppy socialisation with the PDSA
        // quote (M5-R17), the meal / exercise gap (PetMD, M5-R16), tooth brushing every day and nail trimming once a week
        // (WebMD, M5-R15 / M5-R19). No ear / bath / groomer task (no frequency), no heat warning. Walks on a lead,
        // hand-stripping and beard care have no mechanic → open for David (breed doc).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['spinone_italiano'];
        expect(json_encode([spiData('spinone_italiano'), spiData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(spiData("{$pgp}.grooming.value"))->toBe(3)
            ->and(spiData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['spinone_italiano'])->toBe(['S937', spiData("{$pgp}.meal_exercise_gap.quote")])
            ->and(spiData("{$pgp}.meal_exercise_gap.quote"))->toBe(spiData('spinone_italiano.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['spinone_italiano'])->toBe(['S936', spiData("{$pgp}.socialisation.quote")])
            ->and(spiData("{$pgp}.socialisation.quote"))->toBe(spiData('spinone_italiano.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S938', spiData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'week', 'S938', spiData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(spiData("{$pgp}.tooth_brushing.quote"))->toBe(spiData('spinone_italiano.suitability.webmd_teeth_nails.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('spinone_italiano');
    });

    it('takes the breed-level numbers from the sourced spinone_italiano entries', function () {
        // FCI standard N° 165 (S935): males 32–37 kg, females 28–30 kg → 28–37 kg overall.
        expect(spiRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([28.0, 37.0])
            ->and(spiData('spinone_italiano.adult_weight.fci.value'))->toEqual(['male' => [32, 37], 'female' => [28, 30]])
            ->and(spiRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(spiData('spinone_italiano.adult_weight.fci.source_id'))
            ->and(spiRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(spiData('spinone_italiano.adult_weight.fci.quote'))
            ->and(spiRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(spiData('spinone_italiano.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Not ranked by Coren → 1.0.
            ->and(spiRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(spiData('spinone_italiano.trainability.coren_rank.value'))->toBeNull()
            ->and(spiRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('spinone_italiano.lifespan.pdsa')
            ->and(spiRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S936')
            ->and(spiRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(spiRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(spiData('spinone_italiano.lifespan.pdsa.quote'));

        // Brushing 3× a week: PDSA "2 or 3 times per week" → the upper bound 3 (WebMD "weekly brushing" = alternative).
        $grooming = spiRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S936')
            ->and($grooming['quote'])->toBe(spiData('spinone_italiano.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(spiData('proposed_game_parameters.spinone_italiano.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(spiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(spiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S936')
            ->and(spiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        // The digestion warning (M5-R16): 60 minutes, PetMD "Don’t allow your dog to exercise for an hour before or after eating."
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'spinone-italiano');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S937')
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::NailTrimFrequency->value => [1, 7],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->values()->all())->toBe(['S938'])
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
            // M5-R16 / M5-R17: the meal gap (PetMD S937) and socialisation (PDSA S936) quotes for this breed.
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / M5-R19: tooth brushing and nail trimming (WebMD S938).
            StageParamKey::ToothBrushingFrequency, StageParamKey::NailTrimFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('spinone-italiano'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = spiPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age, below the cap)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months like the Australian Shepherd, earlier than the Xoloitzcuintli', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('spinone-italiano', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('spinone-italiano', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('xoloitzcuintli', 90))->toBe(LifeStage::Adult);
    });

    it('learns like the mixed breed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = spiPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'spinone_italiano', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = spiPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(spiLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SpinoneItaliano)
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
        foreach (['border_collie', 'spinone_italiano'] as $breed) {
            $pin = spiPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(spiLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['spinone_italiano'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('spinone_italiano')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = spiPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Spinone Italiano after the Xoloitzcuintli with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'spinone_italiano', 'xoloitzcuintli');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'spinone_italiano', 'slug' => 'spinone-italiano', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.spinone_italiano',
            'search_keywords' => seededBreedConfig('spinone_italiano')['search_keywords'], 'sort_order' => 1150,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['long_daily_exercise', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 165 S935)', function () {
    it('samples DNA only in the five FCI colours — never black, tricolour or tan, never a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('spinone_italiano')
            ->and(PetDnaService::hasAppearance('spinone_italiano'))->toBeTrue()
            ->and(config('breed_appearance.spinone_italiano.verified'))->toBeFalse()
            ->and(config('breed_appearance.spinone_italiano.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.spinone_italiano.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('spinone_italiano', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('spinone_italiano', $traits);
            expect($prompt)->toContain('Spinone Italiano')->toContain('beard')
                ->not->toMatch('/\b(black|tricolou?r|tan|merle|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.spinone_italiano');
        expect($standard)->toBe(['white with orange markings', 'brown roan', 'white with brown markings', 'orange roan', 'pure white'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['orange-white', 'brown-roan', 'brown-white', 'orange-roan', 'white'])
            ->and(spiData('spinone_italiano.appearance.colours.value'))->toBe($standard)
            ->and(spiData('spinone_italiano.appearance.colours.quote'))->toBe('Pure white, white with orange markings, white speckled with orange,');
    });

    it('draws the register portrait white with orange markings (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SpinoneItaliano);

        expect($traits['coat_color'])->toBe('white with orange markings')
            ->and($portrait->prompt(BreedType::SpinoneItaliano))->toContain('Spinone Italiano')->toContain('white with orange markings')
            ->and(BreedPortraitService::relativeFile(BreedType::SpinoneItaliano))->toBe('dog/spinone-italiano.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SpinoneItaliano);
        expect($v1['prompt_anchor'])->toContain('Spinone Italiano')->toContain('orange markings')
            ->not->toMatch('/\b(black|tricolou?r|merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('ochre')
            ->and($v1['visual_traits']['color_scheme'])->toBe('white with orange markings');

        $pet = spiPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Spinone Italiano')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.spinone_italiano'),
            config('breed_suitability.breeds.spinone_italiano'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'spinone-italiano')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::SpinoneItaliano);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed for conformation problems → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(spiData('proposed_game_parameters.spinone_italiano.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 78 KC 2004 survey) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'spinone-italiano')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(78);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families with children; consider the long daily exercise and the need for company', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::SpinoneItaliano);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company'])
            // Supervise very small children (WebMD / PetMD) → no small_children; no RKC key facts → never apartment; WebMD
            // names only "a prey drive" → no chases_small_pets; PDSA vs WebMD on brushing → no frequent_grooming.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('chases_small_pets')->not->toContain('frequent_grooming')
            ->not->toContain('shorter_lifespan');

        expect(spiData('spinone_italiano.behaviour.family.quote'))->toBe('Once socialised, they fit in well with family life.')
            ->and(spiData('spinone_italiano.behaviour.children.quote'))->toBe("They're generally regarded to be good with other dogs and kids,")
            ->and(spiData('spinone_italiano.behaviour.time_alone.quote'))->toBe('However, be aware that leaving them alone for long periods of time will often lead to them getting into trouble.')
            ->and(spiData('spinone_italiano.lifespan.pdsa.quote'))->toBe('Over 10 years');
    });

    it('records the sourced needs without a game mechanic for David (lead / fenced area, hand-stripping, beard care)', function () {
        foreach (['on_lead', 'hand_stripping', 'beard_care'] as $need) {
            expect(spiData("proposed_game_parameters.spinone_italiano.{$need}.decision"))->toContain('potrebna nova naloga')
                ->and(spiData("proposed_game_parameters.spinone_italiano.{$need}.quote"))->not->toBeNull();
        }
    });
});
