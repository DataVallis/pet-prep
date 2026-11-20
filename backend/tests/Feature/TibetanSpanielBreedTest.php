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
| M5-R10-130 — Tibetan Spaniel (appended after the American Hairless Terrier: AKC 2024 No. 126)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `tibetan_spaniel` (S980–S983) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.tibetan_spaniel` (exercise 60 min — PDSA "around an hour of exercise every
| day", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 137 (0.75 × McMillan
| 2024 median 15.2 y), arrival 2 / 9 / 36 / 137, learning × 1.0 (Coren 46, "Average"), paid breed, Border Collie care rates,
| brushing 2× a week (PDSA "a couple of times per week", M5-R13), puppy socialisation with the PetMD quote (S983, M5-R17),
| tooth brushing every day, ear check every week, nail trim + bath every 4 weeks (WebMD S982, M5-R15 / R18 / R19 / R20); no
| groomer task (no frequency), no meal gap (no quote), no heat walk ban (not flat-faced). No welfare chip, in marketing; suits
| active_family, family_pet; consider needs_company, older_children, secure_fencing, heat_sensitive; golden portrait (one of six
| common colours — the FCI standard permits all colours except merle).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function tspData(string $path): mixed
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

/** @return array<string, mixed> the Tibetan Spaniel seeder row of (stage, from, key) */
function tspRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'tibetan-spaniel'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("tibetan-spaniel.{$stage}.{$from}.{$key->value}");

    return $row;
}

function tspPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->tibetanSpaniel()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function tspPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function tspLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::TibetanSpaniel;
        $config = BreedConfig::where('breed_slug', 'tibetan-spaniel')->sole();

        expect($breed->value)->toBe('tibetan_spaniel')
            ->and($breed->slug())->toBe('tibetan-spaniel')
            ->and(BreedType::fromSlug('tibetan-spaniel'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1210, 'breeds.tibetan_spaniel'])
            ->and($config->search_keywords)->toBe(['tibetan spaniel', 'tibbie', 'tibetanski španjel', 'tibetanski spanjel', 'tibetan španjel', 'španjel', 'spaniel'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) tspData('proposed_game_parameters.tibetan_spaniel.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = tspData('proposed_game_parameters.tibetan_spaniel.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Tibetan Spaniel pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = tspPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('tibetan_spaniel');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'tibbie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'tibetan']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_26_120000_add_tibetan_spaniel_breed.php');
        // down() refuses while a Tibetan Spaniel exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'tibetan_spaniel']))->toThrow(QueryException::class);
        // The American Hairless Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'american_hairless_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'tibetan_spaniel']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::TibetanSpaniel);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'tibetan-spaniel')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-spaniel')->count());

        BreedConfig::where('breed_slug', 'tibetan-spaniel')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'tibetan-spaniel')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'tibetan-spaniel')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'tibetan-spaniel');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-spaniel');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'tibetan-spaniel'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_SPANIEL)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_TIBETAN_SPANIEL');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.tibetan_spaniel';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(tspRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(tspData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(tspRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tspData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(tspRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tspData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 15.2 y (McMillan 2024 median, S771 — the runbook's first choice) → confirmed rule.
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(tspData('tibetan_spaniel.lifespan.senior_from.months'))
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * tspRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(tspData('tibetan_spaniel.lifespan.senior_from.derived_from'))->toBe(['S11', 'S771'])
            // The Dogs Trust rule of thumb is background only — never the seeded boundary.
            ->and(tspData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', tspData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_SPANIEL)
            ->and(tspRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(tspRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(137);

        expect(tspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tspData("{$pgp}.exercise_minutes_adult.value"))
            ->and(tspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tspData('tibetan_spaniel.exercise.adult.quote'))
            ->and(tspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tspData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(tspRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S981')
            ->and(tspRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tspRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tspData("{$pgp}.exercise_minutes_senior.value"))
            ->and(tspRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(tspRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(tspData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(tspRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(tspData("{$pgp}.learning_multiplier.value"))
            ->and(tspRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_TIBETAN_SPANIEL) {
                $decided++;
                expect($row['breed_slug'])->toBe('tibetan-spaniel')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.tibetan_spaniel.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) tspData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 2× a week (M5-R13, PDSA), puppy socialisation with the PetMD quote
        // (M5-R17), tooth brushing every day, ear check every week, nail trim + bath every 4 weeks (WebMD, M5-R15 / R18 / R19 / R20),
        // the fenced yard = the secure_fencing chip, hot weather = the heat_sensitive chip. No groomer task (no frequency), no meal gap
        // (no quote), no heat walk ban (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['tibetan_spaniel'];
        expect(json_encode([tspData('tibetan_spaniel'), tspData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(tspData("{$pgp}.grooming.value"))->toBe(2)
            ->and(tspData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('tibetan_spaniel')
            ->and(tspData('tibetan_spaniel.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['tibetan_spaniel'])->toBe(['S983', tspData("{$pgp}.socialisation.quote")])
            ->and(tspData("{$pgp}.socialisation.quote"))->toBe(tspData('tibetan_spaniel.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'nail_trim', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S982', tspData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(tspData("{$pgp}.tooth_brushing.quote"))->toBe(tspData('tibetan_spaniel.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S982', tspData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(tspData("{$pgp}.ear_care.quote"))->toBe(tspData('tibetan_spaniel.suitability.webmd_ears.quote'))
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'month', 'S982', tspData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(tspData("{$pgp}.nail_trimming.quote"))->toBe(tspData('tibetan_spaniel.suitability.webmd_nails.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S982', tspData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(tspData("{$pgp}.bathing.quote"))->toBe(tspData('tibetan_spaniel.suitability.webmd_bath.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('tibetan_spaniel');
    });

    it('takes the breed-level numbers from the sourced tibetan_spaniel entries', function () {
        // FCI-Standard N° 231 (S980): ideal 4.1–6.8 kg for both sexes.
        expect(tspRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.1, 6.8])
            ->and(tspData('tibetan_spaniel.adult_weight.fci.value'))->toEqual(['male' => [4.1, 6.8], 'female' => [4.1, 6.8]])
            ->and(tspRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(tspData('tibetan_spaniel.adult_weight.fci.source_id'))
            ->and(tspRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(tspData('tibetan_spaniel.adult_weight.fci.quote'))
            ->and(tspRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(tspData('tibetan_spaniel.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 46, "Average" tier → 1.0.
            ->and(tspRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(46)
            ->and(tspData('tibetan_spaniel.trainability.coren_rank.value'))->toBe(46)
            ->and(tspData('tibetan_spaniel.trainability.coren_tier.value'))->toBe('Average')
            ->and(tspRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('tibetan_spaniel.lifespan.median_uk')
            ->and(tspRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S771')
            ->and(tspRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(15.2)
            ->and(tspRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(tspData('tibetan_spaniel.lifespan.median_uk.quote'));

        // Brushing 2× a week: PDSA "a couple of times per week" → 2 (Border Terrier precedent).
        $grooming = tspRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S981')
            ->and($grooming['quote'])->toBe(tspData('tibetan_spaniel.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(tspData('proposed_game_parameters.tibetan_spaniel.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(tspRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(tspRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S983')
            ->and(tspRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-spaniel');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::NailTrimFrequency->value => [1, 28],
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S982'])
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
            // M5-R17: the socialisation quote (PetMD S983) for this breed.
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R18 / R19 / R20: tooth brushing, ear check, nail trim, bath reminder (WebMD S982).
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::NailTrimFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('tibetan-spaniel'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = tspPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 136 months' => [136, LifeStage::Adult, 60, 6000],
        'senior 137 months' => [137, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 137 months, later than the Border Terrier (128)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('tibetan-spaniel', 136))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('tibetan-spaniel', 137))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('border-terrier', 128))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('tibetan-spaniel', 128))->toBe(LifeStage::Adult);
    });

    it('learns like the mixed breed (Coren "Average" tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = tspPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'tibetan_spaniel', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = tspPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(tspLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::TibetanSpaniel)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 137 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'tibetan_spaniel'] as $breed) {
            $pin = tspPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(tspLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['tibetan_spaniel'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(137)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('tibetan_spaniel')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = tspPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Tibetan Spaniel after the American Hairless Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'tibetan_spaniel', 'american_hairless_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'tibetan_spaniel', 'slug' => 'tibetan-spaniel', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.tibetan_spaniel',
            'search_keywords' => seededBreedConfig('tibetan_spaniel')['search_keywords'], 'sort_order' => 1210,
            'suitability' => [
                'suits' => ['active_family', 'family_pet'],
                'consider' => ['needs_company', 'older_children', 'secure_fencing', 'heat_sensitive'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 231 S980, never merle)', function () {
    it('samples DNA only in the six common colours, never merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('tibetan_spaniel')
            ->and(PetDnaService::hasAppearance('tibetan_spaniel'))->toBeTrue()
            ->and(config('breed_appearance.tibetan_spaniel.verified'))->toBeFalse()
            ->and(config('breed_appearance.tibetan_spaniel.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.tibetan_spaniel.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('tibetan_spaniel', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('tibetan_spaniel', $traits);
            expect($prompt)->toContain('Tibetan Spaniel')->toContain('pendant')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|flat-faced)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.tibetan_spaniel');
        expect($standard)->toBe(['golden', 'red', 'sable', 'cream', 'black and tan', 'black'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['golden', 'red', 'sable', 'cream', 'black-tan', 'black'])
            ->and(tspData('tibetan_spaniel.appearance.colours.value'))->toBe($standard)
            ->and(tspData('tibetan_spaniel.appearance.colours.quote'))->toBe('All colours and mixture of colours permissible, except merle which is unacceptable.');
    });

    it('draws the register portrait golden (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::TibetanSpaniel);

        expect($traits['coat_color'])->toBe('golden')
            ->and($portrait->prompt(BreedType::TibetanSpaniel))->toContain('Tibetan Spaniel')->toContain('golden')
            ->and(BreedPortraitService::relativeFile(BreedType::TibetanSpaniel))->toBe('dog/tibetan-spaniel.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::TibetanSpaniel);
        expect($v1['prompt_anchor'])->toContain('Tibetan Spaniel')->toContain('golden')
            ->not->toMatch('/\b(merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('golden');

        $pet = tspPet(137, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Tibetan Spaniel')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.tibetan_spaniel'),
            config('breed_suitability.breeds.tibetan_spaniel'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-spaniel')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::TibetanSpaniel);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed for conformation problems → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(tspData('proposed_game_parameters.tibetan_spaniel.marketing.value'))->toContain('in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'tibetan-spaniel')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families; consider company, older children, a fenced yard and hot weather', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::TibetanSpaniel);
        expect($tags['suits'])->toBe(['active_family', 'family_pet'])
            ->and($tags['consider'])->toBe(['needs_company', 'older_children', 'secure_fencing', 'heat_sensitive'])
            // WebMD names older children only → never children / small_children; no RKC page → never apartment / large_home;
            // 60 min → no long_daily_exercise; 2× a week brushing → no frequent_grooming; no prey-drive statement → no chases_small_pets.
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('not_for_small_children')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming')
            ->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('experienced_owner')->not->toContain('cold_sensitive');

        expect(tspData('tibetan_spaniel.behaviour.family.quote'))->toBe('Tibetan Spaniels generally get along well with everyone and will make good family pets.')
            ->and(tspData('tibetan_spaniel.behaviour.children.quote'))->toBe('This is why Tibbies are better suited for families with older children who can handle them responsibly.')
            ->and(tspData('tibetan_spaniel.behaviour.time_alone.quote'))->toBe('Tibetan Spaniels are also prone to separation anxiety.')
            ->and(tspData('tibetan_spaniel.exercise.fencing.quote'))->toBe('The only thing you need to have in your house is a secure fenced yard for them.')
            ->and(tspData('tibetan_spaniel.exercise.heat.quote'))->toBe('Hot weather can trigger overheating and respiratory problems in Tibbies.')
            ->and(tspData('tibetan_spaniel.lifespan.pdsa.quote'))->toBe('Over 12 years');
    });
});
