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
| M5-R10-80 — Irish Wolfhound (appended after the Soft Coated Wheaten Terrier: AKC 2024 No. 76)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `irish_wolfhound` (S615–S624) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.irish_wolfhound` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (Coren
| rank 41, Average), paid breed, Border Collie care rates, brushing 2× a week (RKC "More than once a week" → PDSA "around
| twice a week", M5-R13), puppy socialisation with the PetMD quote (M5-R17), the digestion warning with the PetMD quote
| (M5-R16), ear cleaning every 2 weeks (PetMD "every two to four weeks", M5-R20)). The senior boundary / senior arrival 68
| (0.75 × Orleifson 2017 median 7.5 y — RKC "Under 10 years" has no lower bound) is Claude's suggestion, verified=false,
| open for David. Suitability: suits large_home; consider long_daily_exercise, shorter_lifespan, not_for_small_children,
| needs_company, frequent_grooming, chases_small_pets; grey portrait; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function iwhData(string $path): mixed
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

/** @return array<string, mixed> the Irish Wolfhound seeder row of (stage, from, key) */
function iwhRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'irish-wolfhound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("irish_wolfhound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function iwhPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->irishWolfhound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function iwhPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function iwhLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::IrishWolfhound;
        $config = BreedConfig::where('breed_slug', 'irish-wolfhound')->sole();

        expect($breed->value)->toBe('irish_wolfhound')
            ->and($breed->slug())->toBe('irish-wolfhound')
            ->and(BreedType::fromSlug('irish-wolfhound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 740, 'breeds.irish_wolfhound'])
            ->and($config->search_keywords)->toBe(['irish wolfhound', 'wolfhound', 'cu faoil', 'irski volčji hrt', 'irski volcji hrt', 'volčji hrt', 'volcji hrt', 'irski hrt'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) iwhData('proposed_game_parameters.irish_wolfhound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = iwhData('proposed_game_parameters.irish_wolfhound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Irish Wolfhound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = iwhPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('irish_wolfhound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'wolfhound']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'irski_volcji_hrt']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_14_120000_add_irish_wolfhound_breed.php');
        // down() refuses while an Irish Wolfhound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'irish_wolfhound']))->toThrow(QueryException::class);
        // The Soft Coated Wheaten Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'soft_coated_wheaten_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'irish_wolfhound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::IrishWolfhound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'irish-wolfhound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-wolfhound')->count());

        BreedConfig::where('breed_slug', 'irish-wolfhound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'irish-wolfhound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'irish-wolfhound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'irish-wolfhound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-wolfhound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'irish-wolfhound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_IRISH_WOLFHOUND)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_IRISH_WOLFHOUND_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_IRISH_WOLFHOUND', 'PROPOSED_R10_IRISH_WOLFHOUND_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.irish_wolfhound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(iwhRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(iwhData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(iwhRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(iwhData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(iwhRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(iwhData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 7.5 y × 12) = round(67.5) = 68 (Orleifson 2017 median, S623) — the closest rule row,
            // Claude's suggestion (no McMillan 2024 / VetCompass value reachable, RKC "Under 10 years" has no lower bound)
            // → verified=false, open for David.
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(iwhData('irish_wolfhound.lifespan.senior_from.months'))
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(iwhData("{$pgp}.senior_boundary_months.value"))
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * iwhRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(iwhData('irish_wolfhound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S623'])
            ->and(iwhData('irish_wolfhound.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S623')
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_IRISH_WOLFHOUND_SENIOR)
            ->and(iwhData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_IRISH_WOLFHOUND_SENIOR)
            ->and(iwhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(68);

        expect(iwhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(iwhData("{$pgp}.exercise_minutes_adult.value"))
            ->and(iwhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(iwhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(iwhData('irish_wolfhound.exercise.adult.quote'))
            ->and(iwhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S617')
            ->and(iwhRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(iwhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(iwhData("{$pgp}.exercise_minutes_senior.value"))
            ->and(iwhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(iwhRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(iwhData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(iwhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(iwhData("{$pgp}.learning_multiplier.value"))
            ->and(iwhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_IRISH_WOLFHOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('irish-wolfhound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.irish_wolfhound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) iwhData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13, socialisation
        // M5-R17, digestion warning M5-R16, ear cleaning M5-R20); bath "as needed" and hand-stripping every six months → no task.
        expect(json_encode([iwhData('irish_wolfhound'), iwhData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(iwhData("{$pgp}.grooming.value"))->toBe(2)
            ->and(iwhData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(iwhData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(iwhData("{$pgp}.bathing.decision"))->toContain('brez naloge')
            ->and(iwhData("{$pgp}.professional_grooming.decision"))->toContain('brez opomnika')
            ->and(iwhData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(iwhData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['irish_wolfhound'][1])
            ->and(iwhData('irish_wolfhound.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['irish_wolfhound'][1])
            ->and(iwhData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['irish_wolfhound'][1])
            ->and(iwhData('irish_wolfhound.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['irish_wolfhound'][1]);
    });

    it('takes the breed-level numbers from the sourced irish_wolfhound entries', function () {
        // PDSA (S619): 40–55 kg, both sexes (the standards give minimums only).
        expect(iwhRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([40, 55])
            ->and(iwhData('irish_wolfhound.adult_weight.pdsa.value'))->toEqual(['male' => [40, 55], 'female' => [40, 55]])
            ->and(iwhRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(iwhData('irish_wolfhound.adult_weight.pdsa.source_id'))
            ->and(iwhRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(iwhData('irish_wolfhound.adult_weight.pdsa.quote'))
            ->and(iwhRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(iwhData('irish_wolfhound.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 41 (S35), Average tier → learning 1.0.
            ->and(iwhRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(41)
            ->and(iwhRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(iwhData('irish_wolfhound.trainability.coren_rank.quote'))
            ->and(iwhData('irish_wolfhound.trainability.coren_rank.value'))->toBe(41)
            ->and(iwhRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('irish_wolfhound.lifespan.median_peer_reviewed')
            ->and(iwhRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S623')
            ->and(iwhRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(7.5)
            ->and(iwhRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(iwhData('irish_wolfhound.lifespan.median_peer_reviewed.quote'));

        // Brushing 2× a week (RKC "More than once a week" has no number → PDSA "around twice a week") — the rule, verified.
        $grooming = iwhRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S619')
            ->and($grooming['quote'])->toBe(iwhData('irish_wolfhound.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(iwhData('proposed_game_parameters.irish_wolfhound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(iwhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(iwhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S620')
            // The digestion warning (M5-R16): 60 minutes after a meal, PetMD quote.
            ->and(iwhRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(iwhRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S620');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-wolfhound');
        // M5-R20: the child's ear-cleaning mini-game once per 14 program days ("every two to four weeks", PetMD S620 → the
        // lower bound); no other care task (bath "as needed", hand-stripping every six months, nails / teeth no number) and
        // no heat rule (not flat-faced).
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::EarCleaningFrequency->value])
            ->and($care[0]['value'])->toBe([1, 14])
            ->and($care[0]['source_id'])->toBe('S620')
            ->and($care[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
            ->and($care[0]['verified'])->toBeTrue()
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['irish_wolfhound']['ear_cleaning'][3])->toBe(iwhData('proposed_game_parameters.irish_wolfhound.ear_care.quote'));
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (68 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_IRISH_WOLFHOUND_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.irish_wolfhound.senior_boundary_months')
                ->and($row['value'])->toBe(iwhData('proposed_game_parameters.irish_wolfhound.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" (PetMD S620).
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

        expect($general('irish-wolfhound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = iwhPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 68-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 11 months (10 min × age)' => [11, LifeStage::Young, 110, 11000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'young 20 months (capped)' => [20, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 67 months' => [67, LifeStage::Adult, 120, 12000],
        'senior 68 months' => [68, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 68 months, 5 months after the Saint Bernard (63)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('irish-wolfhound', 67))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('irish-wolfhound', 68))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('saint-bernard', 62))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('saint-bernard', 63))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = iwhPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'irish_wolfhound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = iwhPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(iwhLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::IrishWolfhound)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 68 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'irish_wolfhound'] as $breed) {
            $pin = iwhPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(iwhLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['irish_wolfhound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(68)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('irish_wolfhound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = iwhPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Irish Wolfhound after the Soft Coated Wheaten Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'irish_wolfhound', 'soft_coated_wheaten_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'irish_wolfhound', 'slug' => 'irish-wolfhound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.irish_wolfhound',
            'search_keywords' => seededBreedConfig('irish-wolfhound')['search_keywords'], 'sort_order' => 740,
            'suitability' => [
                'suits' => ['large_home'],
                'consider' => ['long_daily_exercise', 'shorter_lifespan', 'not_for_small_children', 'needs_company', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — grey, grey brindle, wheaten, fawn, red, black, pure white)', function () {
    it('samples DNA in the seven standard colours of the appearance block only, never merle or parti-colour', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('irish_wolfhound')
            ->and(PetDnaService::hasAppearance('irish_wolfhound'))->toBeTrue()
            ->and(config('breed_appearance.irish_wolfhound.verified'))->toBeFalse()
            ->and(config('breed_appearance.irish_wolfhound.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample('irish_wolfhound', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('wiry')
                ->and($traits['ear_carriage'])->toContain('rose ears');

            $prompt = $prompts->imagePrompt('irish_wolfhound', $traits);
            expect($prompt)->toContain('Irish Wolfhound')
                ->not->toMatch('/\b(merle|parti|liver|tricolou?r|docked|cropped)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['grey', 'grey brindle', 'wheaten', 'fawn', 'red', 'black', 'pure white'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.irish_wolfhound'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.irish_wolfhound'), 'key'))->toBe(['grey', 'grey-brindle', 'wheaten', 'fawn', 'red', 'black', 'white']);
    });

    it('draws the register portrait grey', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::IrishWolfhound);

        expect($traits['coat_color'])->toBe('grey')
            ->and($portrait->prompt(BreedType::IrishWolfhound))->toContain('Irish Wolfhound')->toContain('grey')
            ->and(BreedPortraitService::relativeFile(BreedType::IrishWolfhound))->toBe('dog/irish-wolfhound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::IrishWolfhound);
        expect($v1['prompt_anchor'])->toContain('Irish Wolfhound')->toContain('wiry coat')->toContain('rose ears')
            ->not->toMatch('/\b(docked|cropped|merle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['grey', 'grey brindle', 'wheaten'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = iwhPet(68, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Irish Wolfhound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip (Breed Watch points not returned) and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.irish_wolfhound'),
            config('breed_suitability.breeds.irish_wolfhound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-wolfhound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '2720 days', '4.95'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::IrishWolfhound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toContain('shorter_lifespan')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'hips_hind_legs', 'eyelid_problems']))->toBe([])
            ->and(iwhData('proposed_game_parameters.irish_wolfhound.marketing.source_id'))->toBe('S617')
            ->and(iwhData('proposed_game_parameters.irish_wolfhound.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (89 via Wikipedia, 54 PetMD / VCA) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'irish-wolfhound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(89)->not->toContain(54);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives one suits chip and six consider chips; never children, small_children, other_pets, apartment or sheds', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::IrishWolfhound);
        expect($tags['suits'])->toBe(['large_home'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('family_pet')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'shorter_lifespan', 'not_for_small_children', 'needs_company', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('experienced_owner')->not->toContain('heat_sensitive');

        expect(iwhData('irish_wolfhound.exercise.adult.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(iwhData('irish_wolfhound.behaviour.family.quote'))->toContain('smaller children')
            ->and(iwhData('irish_wolfhound.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(iwhData('irish_wolfhound.lifespan.rkc.quote'))->toBe('Lifespan: Under 10 years');
    });
});
