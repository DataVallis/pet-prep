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
| M5-R10-57 — Samoyed (appended after the Chesapeake Bay Retriever: AKC 2024 No. 53)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `samoyed` (S463–S471) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.samoyed` (exercise 120 min — PDSA "a minimum of two hours exercise
| every day"; the RKC page was not reachable —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 1.2 (Coren rank 33, Above average), paid breed, Border Collie care rates, brushing every day
| (PDSA "grooming on daily basis", M5-R13), puppy socialisation with the PetMD quote (M5-R17), the digestion warning
| with the PDSA quote (M5-R16)). The senior boundary / senior arrival 90 (0.75 × PDSA lower bound "Over 10 years") is
| Claude's suggestion, verified=false, open for David. Suitability: suits active_family / family_pet / children,
| consider long_daily_exercise / needs_mental_stimulation / needs_company / secure_fencing / may_herd_children / sheds /
| frequent_grooming / heat_sensitive (new chip) / chases_small_pets; pure white portrait; no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function samData(string $path): mixed
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

/** @return array<string, mixed> the Samoyed seeder row of (stage, from, key) */
function samRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'samoyed'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("samoyed.{$stage}.{$from}.{$key->value}");

    return $row;
}

function samPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->samoyed()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function samPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function samLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Samoyed;
        $config = BreedConfig::where('breed_slug', 'samoyed')->sole();

        expect($breed->value)->toBe('samoyed')
            ->and($breed->slug())->toBe('samoyed')
            ->and(BreedType::fromSlug('samoyed'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 530, 'breeds.samoyed'])
            ->and($config->search_keywords)->toBe(['samoyed', 'samojed', 'sammy', 'samoyede', 'samojede', 'samojedski pes', 'samoyed dog'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) samData('proposed_game_parameters.samoyed.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = samData('proposed_game_parameters.samoyed.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Samoyed pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = samPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('samoyed');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'sammy']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'samojed']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_14_120000_add_samoyed_breed.php');
        // down() refuses while a Samoyed exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'samoyed']))->toThrow(QueryException::class);
        // The Chesapeake Bay Retriever (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chesapeake_bay_retriever']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'samoyed']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Samoyed);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'samoyed')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'samoyed')->count());

        BreedConfig::where('breed_slug', 'samoyed')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'samoyed')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'samoyed')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'samoyed');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'samoyed');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'samoyed'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SAMOYED)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_SAMOYED_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_SAMOYED', 'PROPOSED_R10_SAMOYED_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.samoyed';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(samRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(samData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(samRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(samData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(samRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(samData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PDSA lower bound "Over 10 years", S467) = 90 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(samData('samoyed.lifespan.senior_from.months'))
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(samData("{$pgp}.senior_boundary_months.value"))
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * samRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(samData('samoyed.lifespan.senior_from.derived_from'))->toBe(['S11', 'S467'])
            ->and(samData('samoyed.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S467')
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SAMOYED_SENIOR)
            ->and(samData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_SAMOYED_SENIOR)
            ->and(samRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(samRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(samData("{$pgp}.exercise_minutes_adult.value"))
            ->and(samRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(samRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(samData('samoyed.exercise.adult.quote'))
            ->and(samRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S467')
            ->and(samRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(samRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(samData("{$pgp}.exercise_minutes_senior.value"))
            ->and(samRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(samRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(samData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(samRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(samData("{$pgp}.learning_multiplier.value"))
            ->and(samRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SAMOYED) {
                $decided++;
                expect($row['breed_slug'])->toBe('samoyed')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.samoyed.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) samData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); the groomer, nails / ears / teeth and heat care
        // have no mechanic → open for David (M5-R18 / R19 / R20 / R15, M5-R14 or a new ROADMAP task).
        expect(json_encode([samData('samoyed'), samData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(samData("{$pgp}.grooming.value"))->toBe(7)
            ->and(samData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(samData("{$pgp}.professional_grooming.decision"))->toContain('M5-R18')
            ->and(samData("{$pgp}.nails_ears_teeth.decision"))->toContain('M5-R19')->toContain('M5-R20')->toContain('M5-R15')
            ->and(samData("{$pgp}.heat_care.decision"))->toContain('M5-R14')
            ->and(samData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(samData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['samoyed'][1])
            ->and(samData('samoyed.suitability.pdsa_meal_gap.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['samoyed'][1])
            ->and(samData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['samoyed'][1])
            ->and(samData('samoyed.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['samoyed'][1]);
    });

    it('takes the breed-level numbers from the sourced samoyed entries', function () {
        // PDSA (S467) "16-29 kg" for both sexes — no breed standard gives a weight.
        expect(samRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16.0, 29.0])
            ->and(samData('samoyed.adult_weight.pdsa.value'))->toEqual(['male' => [16, 29], 'female' => [16, 29]])
            ->and(samRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(samData('samoyed.adult_weight.pdsa.source_id'))
            ->and(samRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(samData('samoyed.adult_weight.pdsa.quote'))
            ->and(samRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(samData('samoyed.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 33 (S35), Above average tier → learning 1.2.
            ->and(samRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(33)
            ->and(samRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(samData('samoyed.trainability.coren_rank.quote'))
            ->and(samData('samoyed.trainability.coren_rank.value'))->toBe(33)
            ->and(samRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('samoyed.lifespan.pdsa')
            ->and(samRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S467')
            ->and(samRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(samRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(samData('samoyed.lifespan.pdsa.quote'));

        // Brushing every day (PDSA "grooming on daily basis" → 7) — the rule, verified.
        $grooming = samRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S467')
            ->and($grooming['quote'])->toBe(samData('samoyed.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(samData('proposed_game_parameters.samoyed.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(samRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(samRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S469')
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice.
            ->and(samRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(samRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S467');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'samoyed');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SAMOYED_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.samoyed.senior_boundary_months')
                ->and($row['value'])->toBe(samData('proposed_game_parameters.samoyed.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning (PDSA S467) and the puppy's weekly "new experience"
            // with the breed's own PetMD quote (S469).
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

        expect($general('samoyed'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = samPet($arrival);
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

    it('becomes senior at 90 months, like the Chesapeake Bay Retriever and 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('samoyed', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('samoyed', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Above average tier, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = samPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'samoyed', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = samPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(samLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Samoyed)
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
        foreach (['border_collie', 'samoyed'] as $breed) {
            $pin = samPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(samLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['samoyed'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('samoyed')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = samPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Samoyed after the Chesapeake Bay Retriever with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'samoyed', 'chesapeake_bay_retriever');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'samoyed', 'slug' => 'samoyed', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.samoyed',
            'search_keywords' => seededBreedConfig('samoyed')['search_keywords'], 'sort_order' => 530,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'secure_fencing', 'may_herd_children', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — pure white, cream, white and biscuit)', function () {
    it('samples DNA in the three standard colours of the appearance block only, never beige or blue eyes', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('samoyed')
            ->and(PetDnaService::hasAppearance('samoyed'))->toBeTrue()
            ->and(config('breed_appearance.samoyed.verified'))->toBeFalse()
            ->and(config('breed_appearance.samoyed.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('samoyed', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('double coat')
                ->and($traits['tail'])->toContain('curved forward over the back')
                ->and($traits['eye_color'])->toContain('dark brown');

            $prompt = $prompts->imagePrompt('samoyed', $traits);
            expect($prompt)->toContain('Samoyed')
                ->not->toMatch('/\b(beige|blue eyes|merle|docked|cropped|Husky|Malamute|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['pure white', 'cream', 'white and biscuit'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.samoyed'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.samoyed'), 'key'))->toBe(['white', 'cream', 'white-biscuit']);
    });

    it('draws the register portrait pure white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Samoyed);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::Samoyed))->toContain('Samoyed')->toContain('pure white')
            ->and(BreedPortraitService::relativeFile(BreedType::Samoyed))->toBe('dog/samoyed.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Samoyed);
        expect($v1['prompt_anchor'])->toContain('Samoyed')->toContain('pure white')
            ->not->toMatch('/\b(beige|docked|shaved)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['pure white', 'cream', 'white and biscuit'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = samPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Samoyed')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.samoyed'),
            config('breed_suitability.breeds.samoyed'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'samoyed')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Samoyed);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; PDSA "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(samData('proposed_game_parameters.samoyed.marketing.source_id'))->toBe('S467')
            ->and(samData('proposed_game_parameters.samoyed.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic or low-shedding (PDSA "They shed very often", PetMD "not considered to be a hypoallergenic dog breed").
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 Dogs Trust, 108 PetMD / Woodgreen, 118 via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'samoyed')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108)->not->toContain(118);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family, family and children chips plus nine consider chips incl. the new heat_sensitive chip; never small_children, other_pets, low_shedding or experienced_owner', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Samoyed);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'secure_fencing', 'may_herd_children', 'sheds', 'frequent_grooming', 'heat_sensitive', 'chases_small_pets'])
            // The experienced_owner chip names a powerful dog — PDSA's advice stays in the register (open for David).
            ->and($tags['consider'])->not->toContain('experienced_owner')->not->toContain('independent_experienced_owner')->not->toContain('older_children')
            // M5-R10-57 runbook new-tag rule: thick coat — struggles in hot weather (PetMD S469).
            ->and(config('breed_suitability.vocabulary.heat_sensitive'))->toBe('consider');

        expect(samData('samoyed.exercise.adult.quote'))->toBe('We recommend a minimum of two hours exercise every day for your Samoyed.')
            ->and(samData('samoyed.exercise.heat.quote'))->toBe('Samoyed dogs require daily brushing and do not do well in hot temperatures.')
            ->and(samData('samoyed.behaviour.escaping.quote'))->toContain('accomplished escape artists')
            ->and(samData('samoyed.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(samData('samoyed.suitability.petmd_family.quote'))->toBe('Yes, Samoyeds can make excellent family companions as long as their needs are met.')
            ->and(samData('samoyed.behaviour.time_alone.quote'))->toContain('more than four hours')
            ->and(samData('proposed_game_parameters.samoyed.suitability_tags.not_proposed.experienced_owner'))->toContain('Open for David');
    });
});
