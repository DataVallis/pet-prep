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
| M5-R10-83 — Alaskan Malamute (appended after the Lagotto Romagnolo: AKC 2024 No. 79)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `alaskan_malamute` (S637–S641) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.alaskan_malamute` (exercise 120 min — PDSA "a minimum of two hours of
| heavy exercise every day"; the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages
| 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (Coren rank 50, Average), paid breed, Border Collie care rates, brushing every
| day (PDSA "daily brushing", M5-R13), puppy socialisation and the digestion warning with PDSA quotes (M5-R17 / M5-R16),
| the child's ear-care game once a week and the parent's bath reminder every 6 weeks (PetMD, M5-R20 / M5-R19)). The senior
| boundary / senior arrival 90 (0.75 × PDSA lower bound "Over 10 years") is Claude's suggestion, verified=false, open for
| David. Suitability: suits active_family; consider long_daily_exercise / experienced_owner / not_for_small_children /
| needs_company / sheds / frequent_grooming / heat_sensitive / chases_small_pets; grey and white portrait; no welfare chip,
| in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function amData(string $path): mixed
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

/** @return array<string, mixed> the Alaskan Malamute seeder row of (stage, from, key) */
function amRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'alaskan-malamute'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("alaskan_malamute.{$stage}.{$from}.{$key->value}");

    return $row;
}

function amPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->alaskanMalamute()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function amPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function amLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AlaskanMalamute;
        $config = BreedConfig::where('breed_slug', 'alaskan-malamute')->sole();

        expect($breed->value)->toBe('alaskan_malamute')
            ->and($breed->slug())->toBe('alaskan-malamute')
            ->and(BreedType::fromSlug('alaskan-malamute'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 770, 'breeds.alaskan_malamute'])
            ->and($config->search_keywords)->toBe(['alaskan malamute', 'malamute', 'malamut', 'aljaški malamut', 'aljaski malamut', 'malamute de l alaska'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) amData('proposed_game_parameters.alaskan_malamute.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = amData('proposed_game_parameters.alaskan_malamute.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Alaskan Malamute pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = amPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('alaskan_malamute');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'malamute']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'aljaski_malamut']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_23_120000_add_alaskan_malamute_breed.php');
        // down() refuses while an Alaskan Malamute exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'alaskan_malamute']))->toThrow(QueryException::class);
        // The Lagotto Romagnolo (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'lagotto_romagnolo']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'alaskan_malamute']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AlaskanMalamute);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'alaskan-malamute')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute')->count());

        BreedConfig::where('breed_slug', 'alaskan-malamute')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'alaskan-malamute')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'alaskan-malamute')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'alaskan-malamute');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'alaskan-malamute'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ALASKAN_MALAMUTE)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_ALASKAN_MALAMUTE', 'PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.alaskan_malamute';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(amRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(amData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(amRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(amData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(amRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(amData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PDSA lower bound "Over 10 years", S639) = 90 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(amData('alaskan_malamute.lifespan.senior_from.months'))
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(amData("{$pgp}.senior_boundary_months.value"))
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * amRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(amData('alaskan_malamute.lifespan.senior_from.derived_from'))->toBe(['S11', 'S639'])
            ->and(amData('alaskan_malamute.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S639')
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR)
            ->and(amData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR)
            ->and(amRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(amRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(amData("{$pgp}.exercise_minutes_adult.value"))
            ->and(amRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(amRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(amData('alaskan_malamute.exercise.adult.quote'))
            ->and(amRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S639')
            ->and(amRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(amRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(amData("{$pgp}.exercise_minutes_senior.value"))
            ->and(amRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(amRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(amData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(amRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(amData("{$pgp}.learning_multiplier.value"))
            ->and(amRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ALASKAN_MALAMUTE) {
                $decided++;
                expect($row['breed_slug'])->toBe('alaskan-malamute')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.alaskan_malamute.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) amData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (brushing M5-R13,
        // meal gap M5-R16, socialisation M5-R17, ears M5-R20, bath M5-R19); the groomer "every six months" is longer than
        // every care period → no reminder.
        expect(json_encode([amData('alaskan_malamute'), amData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(amData("{$pgp}.grooming.value"))->toBe(7)
            ->and(amData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(amData("{$pgp}.professional_grooming.decision"))->toContain('M5-R18')->toContain('brez opomnika')
            ->and(amData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(amData("{$pgp}.bathing.decision"))->toContain('M5-R19')
            ->and(amData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(amData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['alaskan_malamute'][1])
            ->and(amData('alaskan_malamute.suitability.pdsa_meal_gap.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['alaskan_malamute'][1])
            ->and(amData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['alaskan_malamute'][1])
            ->and(amData('alaskan_malamute.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['alaskan_malamute'][1]);
    });

    it('takes the breed-level numbers from the sourced alaskan_malamute entries', function () {
        // PDSA (S639) "34-39 kg" for both sexes — the FCI standard gives desirable freighting sizes only.
        expect(amRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([34.0, 39.0])
            ->and(amData('alaskan_malamute.adult_weight.pdsa.value'))->toEqual(['male' => [34, 39], 'female' => [34, 39]])
            ->and(amRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(amData('alaskan_malamute.adult_weight.pdsa.source_id'))
            ->and(amRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(amData('alaskan_malamute.adult_weight.pdsa.quote'))
            ->and(amRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(amData('alaskan_malamute.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 50 (S35), Average tier → learning 1.0.
            ->and(amRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(50)
            ->and(amRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(amData('alaskan_malamute.trainability.coren_rank.quote'))
            ->and(amData('alaskan_malamute.trainability.coren_rank.value'))->toBe(50)
            ->and(amRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('alaskan_malamute.lifespan.pdsa')
            ->and(amRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S639')
            ->and(amRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(amRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(amData('alaskan_malamute.lifespan.pdsa.quote'));

        // Brushing every day (PDSA "daily brushing" → 7) — the rule, verified.
        $grooming = amRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S639')
            ->and($grooming['quote'])->toBe(amData('alaskan_malamute.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(amData('proposed_game_parameters.alaskan_malamute.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(amRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(amRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S639')
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice.
            ->and(amRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(amRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S639');

        // Care tasks (David 2026-10-24): the child's ear-care game once a week and the parent's bath reminder every
        // 6 weeks (PetMD S640); no groomer reminder ("around once every six months"), no tooth / nail task.
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 42],
            StageParamKey::EarCleaningFrequency->value => [1, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->values()->all())->toBe(['S640'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['alaskan_malamute']['ear_cleaning'][3])->toBe(amData('proposed_game_parameters.alaskan_malamute.ear_care.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['alaskan_malamute']['bath'][3])->toBe(amData('proposed_game_parameters.alaskan_malamute.bathing.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_ALASKAN_MALAMUTE_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.alaskan_malamute.senior_boundary_months')
                ->and($row['value'])->toBe(amData('proposed_game_parameters.alaskan_malamute.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" with the breed's own
            // PDSA quotes (S639).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('alaskan-malamute'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = amPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 90-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Samoyed and 18 months before the Lagotto Romagnolo (108)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('alaskan-malamute', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('alaskan-malamute', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('samoyed', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('lagotto-romagnolo', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('lagotto-romagnolo', 108))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = amPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'alaskan_malamute', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = amPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(amLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AlaskanMalamute)
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
        foreach (['border_collie', 'alaskan_malamute'] as $breed) {
            $pin = amPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(amLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['alaskan_malamute'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('alaskan_malamute')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = amPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Alaskan Malamute after the Lagotto Romagnolo with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'alaskan_malamute', 'lagotto_romagnolo');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'alaskan_malamute', 'slug' => 'alaskan-malamute', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.alaskan_malamute',
            'search_keywords' => seededBreedConfig('alaskan_malamute')['search_keywords'], 'sort_order' => 770,
            'suitability' => [
                'suits' => ['active_family'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S638, never blue eyes)', function () {
    it('samples DNA in the five standard colours of the appearance block only, with a coarse coat and a plume tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('alaskan_malamute')
            ->and(PetDnaService::hasAppearance('alaskan_malamute'))->toBeTrue()
            ->and(config('breed_appearance.alaskan_malamute.verified'))->toBeFalse()
            ->and(config('breed_appearance.alaskan_malamute.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('alaskan_malamute', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('coarse guard coat')
                ->and($traits['tail'])->toContain('waving plume')
                ->and($traits['eye_color'])->toContain('brown');

            $prompt = $prompts->imagePrompt('alaskan_malamute', $traits);
            expect($prompt)->toContain('Alaskan Malamute')
                ->not->toMatch('/\b(blue eyes|merle|brindle|docked|cropped|Husky|Samoyed|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'grey and white with a grey cap and white face markings',
            'black and white with a dark cap and white face markings',
            'sable and white with white face markings',
            'red and white with white face markings and a brown nose',
            'all white',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.alaskan_malamute'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.alaskan_malamute'), 'key'))->toBe(['grey-white', 'black-white', 'sable-white', 'red-white', 'white'])
            ->and(amData('alaskan_malamute.appearance.colours.value'))->toBe(['grey and white', 'black and white', 'sable and white', 'red and white', 'all white']);
    });

    it('draws the register portrait grey and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AlaskanMalamute);

        expect($traits['coat_color'])->toBe('grey and white with a grey cap and white face markings')
            ->and($portrait->prompt(BreedType::AlaskanMalamute))->toContain('Alaskan Malamute')->toContain('grey and white')
            ->and(BreedPortraitService::relativeFile(BreedType::AlaskanMalamute))->toBe('dog/alaskan-malamute.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AlaskanMalamute);
        expect($v1['prompt_anchor'])->toContain('Alaskan Malamute')->toContain('grey and white')->toContain('brown almond-shaped eyes')
            ->not->toMatch('/\b(merle|docked|shaved)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(array_slice(array_column(config('breed_colours.alaskan_malamute'), 'value'), 0, 3))
            ->and($v1['visual_traits']['eye_color'])->toBe('brown');

        $pet = amPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Alaskan Malamute')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.alaskan_malamute'),
            config('breed_suitability.breeds.alaskan_malamute'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AlaskanMalamute);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; PDSA "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(amData('proposed_game_parameters.alaskan_malamute.marketing.source_id'))->toBe('S639')
            ->and(amData('proposed_game_parameters.alaskan_malamute.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic or low-shedding (PDSA "Malamutes shed a lot …").
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'alaskan-malamute')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family chip plus eight consider chips; never family_pet, children, small_children, other_pets, low_shedding or large_home', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AlaskanMalamute);
        expect($tags['suits'])->toBe(['active_family'])
            ->and($tags['suits'])->not->toContain('family_pet')->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('large_home')->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets']);

        expect(amData('alaskan_malamute.exercise.adult.quote'))->toBe('Malamutes need a minimum of two hours of heavy exercise every day.')
            ->and(amData('alaskan_malamute.exercise.heat.quote'))->toBe('Alaskan Malamutes can have trouble in hot weather because of their thick coat.')
            ->and(amData('alaskan_malamute.behaviour.experienced_owner.quote'))->toContain('first time dog owners')
            ->and(amData('alaskan_malamute.behaviour.small_children.quote'))->toContain('injure small children')
            ->and(amData('alaskan_malamute.behaviour.other_pets.quote'))->toContain('very strong prey drive')
            ->and(amData('alaskan_malamute.behaviour.time_alone.quote'))->toContain('more than four hours')
            ->and(amData('alaskan_malamute.suitability.pdsa_shedding.quote'))->toStartWith('Malamutes shed a lot');
    });
});
