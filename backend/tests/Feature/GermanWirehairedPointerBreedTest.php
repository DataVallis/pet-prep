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
| M5-R10-65 — German Wirehaired Pointer (appended after the Cardigan Welsh Corgi: AKC 2024 No. 61)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `german_wirehaired_pointer` (S519–S526 + PDSA S188, one page for both
| German Pointers) and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.german_wirehaired_pointer` (exercise 120 min — RKC "Exercise: More than 2 hours per day" —,
| senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 — 0.75 × RKC lower bound "Over 10 years"; the
| Dogs Trust McMillan 2024 table could not be fetched —, arrival 2 / 9 / 36 / 90, learning × 1.0 (Coren rank 44,
| Average), paid breed, Border Collie care rates, brushing 2× a week (PDSA "once or twice a week", M5-R13), puppy
| socialisation with the PetMD quote (M5-R17), ear cleaning once every 4 weeks (PetMD, M5-R20); no meal gap (PDSA has
| no gap quote); suitability: suits active_family / family_pet / children / large_home, consider long_daily_exercise /
| needs_mental_stimulation / needs_company / sheds / chases_small_pets; liver roan portrait (liver colours only, never
| black); RKC Breed Watch Category 1, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gwpData(string $path): mixed
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

/** @return array<string, mixed> the German Wirehaired Pointer seeder row of (stage, from, key) */
function gwpRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'german-wirehaired-pointer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("german-wirehaired-pointer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gwpPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->germanWirehairedPointer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gwpPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gwpLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GermanWirehairedPointer;
        $config = BreedConfig::where('breed_slug', 'german-wirehaired-pointer')->sole();

        expect($breed->value)->toBe('german_wirehaired_pointer')
            ->and($breed->slug())->toBe('german-wirehaired-pointer')
            ->and(BreedType::fromSlug('german-wirehaired-pointer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 610, 'breeds.german_wirehaired_pointer'])
            ->and($config->search_keywords)->toBe(['german wirehaired pointer', 'deutsch drahthaar', 'drahthaar', 'gwp', 'nemški ostrodlaki ptičar', 'nemski ostrodlaki pticar', 'drahtar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gwpData('proposed_game_parameters.german_wirehaired_pointer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gwpData('proposed_game_parameters.german_wirehaired_pointer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a German Wirehaired Pointer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gwpPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('german_wirehaired_pointer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'deutsch_drahthaar']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'drahthaar']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_28_120000_add_german_wirehaired_pointer_breed.php');
        // down() refuses while a German Wirehaired Pointer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'german_wirehaired_pointer']))->toThrow(QueryException::class);
        // The Cardigan Welsh Corgi (previous dog migration) and the German Shorthaired Pointer stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cardigan_welsh_corgi']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_shorthaired_pointer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_wirehaired_pointer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GermanWirehairedPointer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'german-wirehaired-pointer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-wirehaired-pointer')->count());

        BreedConfig::where('breed_slug', 'german-wirehaired-pointer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'german-wirehaired-pointer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'german-wirehaired-pointer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'german-wirehaired-pointer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-wirehaired-pointer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'german-wirehaired-pointer'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_WIREHAIRED_POINTER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_GERMAN_WIREHAIRED_POINTER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.german_wirehaired_pointer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gwpRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gwpData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gwpRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gwpData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gwpRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gwpData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S521) = 90 — the runbook's third choice.
            ->and(gwpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gwpData('german_wirehaired_pointer.lifespan.senior_from.months'))
            ->and(gwpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gwpRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gwpData('german_wirehaired_pointer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S521'])
            ->and(gwpData('german_wirehaired_pointer.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(gwpRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S521')
            ->and(gwpRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(gwpRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(gwpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gwpData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gwpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gwpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gwpData('german_wirehaired_pointer.exercise.adult.quote'))
            ->and(gwpRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S521')
            ->and(gwpRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(gwpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gwpData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gwpRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gwpRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gwpData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gwpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gwpData("{$pgp}.learning_multiplier.value"))
            ->and(gwpRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GERMAN_WIREHAIRED_POINTER) {
                $decided++;
                expect($row['breed_slug'])->toBe('german-wirehaired-pointer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.german_wirehaired_pointer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gwpData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17, ear cleaning M5-R20). Hand-stripping twice a year has no program period
        // → a proposal awaiting David (the only one); no meal-gap quote → no row.
        expect(json_encode([gwpData('german_wirehaired_pointer'), gwpData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(gwpData("{$pgp}.grooming.value"))->toBe(2)
            ->and(gwpData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(gwpData("{$pgp}.ear_care.decision"))->toContain('M5-R20')
            ->and(gwpData("{$pgp}.hand_stripping.decision"))->toContain('čaka Davida')
            ->and(gwpData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('german_wirehaired_pointer')
            ->and(gwpData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['german_wirehaired_pointer'][1])
            ->and(gwpData('german_wirehaired_pointer.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['german_wirehaired_pointer'][1]);
    });

    it('takes the breed-level numbers from the sourced german_wirehaired_pointer entries', function () {
        // RKC standard (S522): dogs 25–34 kg, bitches 20.5–29 kg → 20.5–34 kg (FCI / AKC give no weight).
        expect(gwpRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.5, 34.0])
            ->and(gwpData('german_wirehaired_pointer.adult_weight.rkc_standard.value'))->toEqual(['male' => [25, 34], 'female' => [20.5, 29]])
            ->and(gwpRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gwpData('german_wirehaired_pointer.adult_weight.rkc_standard.source_id'))
            ->and(gwpRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(gwpData('german_wirehaired_pointer.adult_weight.rkc_standard.quote'))
            ->and(gwpRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(gwpData('german_wirehaired_pointer.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 44 (S35), Average tier → learning 1.0.
            ->and(gwpRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(44)
            ->and(gwpRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(gwpData('german_wirehaired_pointer.trainability.coren_rank.quote'))
            ->and(gwpData('german_wirehaired_pointer.trainability.coren_rank.value'))->toBe(44)
            ->and(gwpRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('german_wirehaired_pointer.lifespan.rkc')
            ->and(gwpRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S521')
            ->and(gwpRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(gwpRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(gwpData('german_wirehaired_pointer.lifespan.rkc.quote'));

        // Brushing 2× a week (RKC "More than once a week" has no number → PDSA "once or twice a week" → 2) — the rule, verified.
        $grooming = gwpRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S188')
            ->and($grooming['quote'])->toBe(gwpData('german_wirehaired_pointer.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(gwpData('proposed_game_parameters.german_wirehaired_pointer.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(gwpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(gwpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S524')
            ->and(gwpRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['quote'])->toBe(gwpData('german_wirehaired_pointer.behaviour.socialisation.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-wirehaired-pointer');
        // No digestion warning: PDSA (S188) describes GDV but gives no gap after meals.
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);
        // M5-R20: the child's ear-cleaning mini-game once per 28 program days ("once a month", PetMD S524); no other
        // care task (stripping twice a year has no program period, baths "rarely") and no heat rule (not flat-faced).
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::EarCleaningFrequency->value])
            ->and($care[0]['value'])->toBe([1, 28])
            ->and($care[0]['source_id'])->toBe('S524')
            ->and($care[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
            ->and($care[0]['verified'])->toBeTrue()
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['german_wirehaired_pointer']['ear_cleaning'][3])->toBe(gwpData('proposed_game_parameters.german_wirehaired_pointer.ear_care.quote'));
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S524).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('german-wirehaired-pointer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gwpPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
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

    it('becomes senior at 90 months, like the Weimaraner and 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('german-wirehaired-pointer', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('german-wirehaired-pointer', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('weimaraner', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gwpPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'german_wirehaired_pointer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gwpPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gwpLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GermanWirehairedPointer)
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
        foreach (['border_collie', 'german_wirehaired_pointer'] as $breed) {
            $pin = gwpPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gwpLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['german_wirehaired_pointer'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('german_wirehaired_pointer')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gwpPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the German Wirehaired Pointer after the Cardigan Welsh Corgi with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'german_wirehaired_pointer', 'cardigan_welsh_corgi');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'german_wirehaired_pointer', 'slug' => 'german-wirehaired-pointer', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.german_wirehaired_pointer',
            'search_keywords' => seededBreedConfig('german_wirehaired_pointer')['search_keywords'], 'sort_order' => 610,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home'],
                'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — liver colours, never black)', function () {
    it('samples DNA in the three standard liver colours of the appearance block only, never black or tricolour', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('german_wirehaired_pointer')
            ->and(PetDnaService::hasAppearance('german_wirehaired_pointer'))->toBeTrue()
            ->and(config('breed_appearance.german_wirehaired_pointer.verified'))->toBeFalse()
            ->and(config('breed_appearance.german_wirehaired_pointer.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('coat_length');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('german_wirehaired_pointer', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('wiry')
                ->and($traits['tail'])->toContain('undocked')
                ->and($traits['eye_color'])->toContain('hazel');

            $prompt = $prompts->imagePrompt('german_wirehaired_pointer', $traits);
            expect($prompt)->toContain('German Wirehaired Pointer')
                ->not->toMatch('/\b(black|tricolou?r|merle|docked tail|cropped|Shorthaired)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['liver roan with solid liver patches and a liver head', 'liver and white', 'solid liver'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.german_wirehaired_pointer'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.german_wirehaired_pointer'), 'key'))->toBe(['liver-roan', 'liver-white', 'liver']);
    });

    it('draws the register portrait liver roan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GermanWirehairedPointer);

        expect($traits['coat_color'])->toBe('liver roan with solid liver patches and a liver head')
            ->and($portrait->prompt(BreedType::GermanWirehairedPointer))->toContain('German Wirehaired Pointer')->toContain('liver roan')
            ->and(BreedPortraitService::relativeFile(BreedType::GermanWirehairedPointer))->toBe('dog/german-wirehaired-pointer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GermanWirehairedPointer);
        expect($v1['prompt_anchor'])->toContain('German Wirehaired Pointer')->toContain('liver roan')
            ->not->toMatch('/\b(black|tricolou?r|docked tail)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['liver roan', 'liver and white', 'solid liver'])
            ->and($v1['visual_traits']['eye_color'])->toBe('hazel to dark brown');

        $pet = gwpPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('German Wirehaired Pointer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.german_wirehaired_pointer'),
            config('breed_suitability.breeds.german_wirehaired_pointer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-wirehaired-pointer')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::GermanWirehairedPointer);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S521) → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(gwpData('german_wirehaired_pointer.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(gwpData('proposed_game_parameters.german_wirehaired_pointer.marketing.source_id'))->toBe('S521')
            ->and(gwpData('proposed_game_parameters.german_wirehaired_pointer.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and McMillan 2024 via Wikipedia (117) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'german-wirehaired-pointer')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(117);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family, family, children and large-home chips plus long exercise, mental stimulation, company, shedding and prey drive; never small_children, other_pets, apartment or frequent_grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::GermanWirehairedPointer);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('frequent_grooming')->not->toContain('experienced_owner')->not->toContain('not_for_small_children')->not->toContain('chews_when_bored');

        expect(gwpData('german_wirehaired_pointer.suitability.rkc_exercise.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(gwpData('german_wirehaired_pointer.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(gwpData('german_wirehaired_pointer.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(gwpData('german_wirehaired_pointer.behaviour.family.quote'))->toBe('They can make great family dogs when trained and socialized from an early age.')
            ->and(gwpData('german_wirehaired_pointer.suitability.petmd_children.quote'))->toContain('do well with children who have been taught')
            ->and(gwpData('german_wirehaired_pointer.suitability.petmd_children.notes'))->toContain('supervised')
            ->and(gwpData('german_wirehaired_pointer.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(gwpData('german_wirehaired_pointer.exercise.mental.quote'))->toContain('mental stimulation')
            ->and(gwpData('german_wirehaired_pointer.behaviour.time_alone.quote'))->toBe('but your GWP should never be left alone for long periods.');
    });
});
