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
| M5-R10-63 — Akita (appended after the Bloodhound: AKC 2024 No. 59)
|--------------------------------------------------------------------------
| The AKC "Akita" = the American type (FCI No. 344 American Akita), not the Japanese Akita Inu.
| Sourced data: docs/research/dog-data/data.json `akita` (S506–S512) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.akita` (exercise 120 min — PDSA "Minimum exercise
| (per day): 2 hours", the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages
| 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (Coren rank 54, Average), paid breed, Border Collie care rates, brushing
| once a week (PDSA, M5-R13), puppy socialisation with the PDSA quote (M5-R17), the digestion warning with the PDSA
| gap quote (M5-R16), a groomer reminder every 12 program weeks (PDSA "every three months or so", M5-R18)). The
| senior boundary / senior arrival 89 (0.75 × Kennel Club / BSAVA 2004 survey median 9 y 11 m — PDSA "Under 10
| years" has no lower bound) is Claude's suggestion, verified=false, open for David. Suitability: suits
| active_family; consider long_daily_exercise, experienced_owner, not_for_small_children, needs_company, sheds,
| chases_small_pets (PDSA "high prey drive"); no shorter_lifespan (PDSA vs PetMD conflict); red fawn portrait with a
| black mask; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function akiData(string $path): mixed
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

/** @return array<string, mixed> the Akita seeder row of (stage, from, key) */
function akiRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'akita'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("akita.{$stage}.{$from}.{$key->value}");

    return $row;
}

function akiPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->akita()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function akiPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function akiLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Akita;
        $config = BreedConfig::where('breed_slug', 'akita')->sole();

        expect($breed->value)->toBe('akita')
            ->and($breed->slug())->toBe('akita')
            ->and(BreedType::fromSlug('akita'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 590, 'breeds.akita'])
            // Never "akita inu": the Japanese Akita Inu is a different breed (FCI 255).
            ->and($config->search_keywords)->toBe(['akita', 'american akita', 'ameriška akita', 'ameriska akita', 'great japanese dog'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) akiData('proposed_game_parameters.akita.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = akiData('proposed_game_parameters.akita.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Akita pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = akiPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('akita');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords); the Japanese Akita Inu is not this breed.
            ->and($refused(['breed_type' => 'american_akita']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'akita_inu']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_26_120000_add_akita_breed.php');
        // down() refuses while an Akita exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'akita']))->toThrow(QueryException::class);
        // The Bloodhound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bloodhound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'akita']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Akita);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'akita')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'akita')->count());

        BreedConfig::where('breed_slug', 'akita')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'akita')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'akita')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'akita');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'akita');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'akita'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AKITA)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_AKITA_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_AKITA', 'PROPOSED_R10_AKITA_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.akita';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(akiRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(akiData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(akiRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(akiData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(akiRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(akiData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.92 y (Kennel Club / BSAVA 2004 survey median, S511) = 89 — the closest rule row,
            // Claude's suggestion (no McMillan 2024 / VetCompass value reachable, no RKC page) → verified=false.
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(akiData('akita.lifespan.senior_from.months'))
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(akiData("{$pgp}.senior_boundary_months.value"))
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * akiRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(akiData('akita.lifespan.senior_from.derived_from'))->toBe(['S11', 'S511'])
            ->and(akiData('akita.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S511')
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_AKITA_SENIOR)
            ->and(akiData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_AKITA_SENIOR)
            ->and(akiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(89);

        expect(akiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(akiData("{$pgp}.exercise_minutes_adult.value"))
            ->and(akiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(akiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(akiData('akita.exercise.adult.quote'))
            ->and(akiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S508')
            ->and(akiRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(akiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(akiData("{$pgp}.exercise_minutes_senior.value"))
            ->and(akiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(akiRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(akiData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(akiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(akiData("{$pgp}.learning_multiplier.value"))
            ->and(akiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AKITA) {
                $decided++;
                expect($row['breed_slug'])->toBe('akita')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.akita.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) akiData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13, socialisation
        // M5-R17, digestion warning M5-R16, groomer reminder M5-R18); the ear cleaning has no frequency → no task.
        expect(json_encode([akiData('akita'), akiData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(akiData("{$pgp}.grooming.value"))->toBe(1)
            ->and(akiData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(akiData("{$pgp}.professional_grooming.decision"))->toContain('M5-R18')
            ->and(akiData("{$pgp}.professional_grooming.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS['akita']['groomer_visit'][3])
            ->and(akiData('akita.suitability.pdsa_professional_grooming.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS['akita']['groomer_visit'][3])
            ->and(akiData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(akiData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['akita'][1])
            ->and(akiData('akita.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['akita'][1])
            ->and(akiData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['akita'][1])
            ->and(akiData('akita.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['akita'][1]);
    });

    it('takes the breed-level numbers from the sourced akita entries', function () {
        // PDSA (S508): one range 31–59 kg for both sexes (no weight in the FCI / AKC standards).
        expect(akiRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([31, 59])
            ->and(akiData('akita.adult_weight.pdsa.value'))->toEqual([31, 59])
            ->and(akiRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(akiData('akita.adult_weight.pdsa.source_id'))
            ->and(akiRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(akiData('akita.adult_weight.pdsa.quote'))
            ->and(akiRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(akiData('akita.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 54 (S35), Average tier → learning 1.0.
            ->and(akiRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(54)
            ->and(akiRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(akiData('akita.trainability.coren_rank.quote'))
            ->and(akiData('akita.trainability.coren_rank.value'))->toBe(54)
            ->and(akiRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('akita.lifespan.median_kc_survey')
            ->and(akiRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S511')
            ->and(akiRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.9167)
            ->and(akiRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(akiData('akita.lifespan.median_kc_survey.quote'));

        // Brushing once a week (PDSA "at minimum a weekly brush" → 1) — the rule, verified.
        $grooming = akiRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S508')
            ->and($grooming['quote'])->toBe(akiData('akita.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(akiData('proposed_game_parameters.akita.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(akiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(akiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S508')
            // The digestion warning (M5-R16): 60 minutes after a meal, PDSA gap quote.
            ->and(akiRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(akiRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S508');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'akita');
        // M5-R18: the parent's groomer reminder, once per 84 program days ("every three months or so", PDSA S508);
        // no child care task (ear cleaning "occasional", PetMD S510 — no number) and no heat rule (not flat-faced).
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::GroomerVisitFrequency->value])
            ->and($care[0]['value'])->toBe([1, 84])
            ->and($care[0]['source_id'])->toBe('S508')
            ->and($care[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
            ->and($care[0]['verified'])->toBeTrue();

        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (89 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_AKITA_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.akita.senior_boundary_months')
                ->and($row['value'])->toBe(akiData('proposed_game_parameters.akita.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" (PDSA S508).
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

        expect($general('akita'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = akiPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 89-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'young 20 months (capped)' => [20, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 88 months' => [88, LifeStage::Adult, 120, 12000],
        'senior 89 months' => [89, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 89 months, 13 months after the Bernese Mountain Dog (76)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('akita', 88))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('akita', 89))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bernese-mountain-dog', 75))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bernese-mountain-dog', 76))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = akiPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'akita', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = akiPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(akiLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Akita)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 89 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'akita'] as $breed) {
            $pin = akiPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(akiLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['akita'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(89)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('akita')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = akiPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Akita after the Bloodhound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'akita', 'bloodhound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'akita', 'slug' => 'akita', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.akita',
            'search_keywords' => seededBreedConfig('akita')['search_keywords'], 'sort_order' => 590,
            'suitability' => [
                'suits' => ['active_family'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — red fawn with a black mask, pinto, brindle, white; never merle or liver)', function () {
    it('samples DNA in the four colours of the appearance block only, never merle, liver or drop ears', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('akita')
            ->and(PetDnaService::hasAppearance('akita'))->toBeTrue()
            ->and(config('breed_appearance.akita.verified'))->toBeFalse()
            ->and(config('breed_appearance.akita.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample('akita', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('double coat')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['tail'])->toContain('curled');

            $prompt = $prompts->imagePrompt('akita', $traits);
            expect($prompt)->toContain('Akita')
                ->not->toMatch('/\b(merle|liver|docked|cropped|drop ears|floppy ears|sickle tail)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['red fawn with a black mask', 'pinto (white with large, evenly placed patches on the head and body)', 'brindle with a black mask', 'solid white with no mask'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.akita'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.akita'), 'key'))->toBe(['fawn', 'pinto', 'brindle', 'white']);
    });

    it('draws the register portrait red fawn with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Akita);

        expect($traits['coat_color'])->toBe('red fawn with a black mask')
            ->and($portrait->prompt(BreedType::Akita))->toContain('Akita')->toContain('red fawn with a black mask')
            ->and(BreedPortraitService::relativeFile(BreedType::Akita))->toBe('dog/akita.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Akita);
        expect($v1['prompt_anchor'])->toContain('Akita')->toContain('red fawn coat and a black mask')->toContain('strongly erect ears')
            ->not->toMatch('/\b(merle|liver|docked|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red fawn with a black mask', 'pinto (white with large, evenly placed patches)', 'brindle with a black mask'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = akiPet(89, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Akita')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.akita'),
            config('breed_suitability.breeds.akita'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'akita')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '32.1', '14.3'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Akita);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No Breed Watch category reachable, not flat-faced → no welfare chip; PDSA vs PetMD lifespan conflict →
            // no shorter_lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(akiData('proposed_game_parameters.akita.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (90 PetMD, 103 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'akita')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(90)->not->toContain(103)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives one suits chip and six consider chips, chases_small_pets last; never family_pet, children, other_pets or apartment', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Akita);
        expect($tags['suits'])->toBe(['active_family'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('family_pet')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('large_home')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('frequent_grooming')->not->toContain('drools');

        expect(akiData('akita.exercise.adult.quote'))->toBe('Minimum exercise (per day): 2 hours')
            ->and(akiData('akita.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(akiData('akita.behaviour.small_children.quote'))->toContain('young children')
            ->and(akiData('akita.behaviour.time_alone.quote'))->toContain('leaving them alone');
    });
});
