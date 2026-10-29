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
| M5-R10-76 — Bull Terrier (appended after the Cairn Terrier: AKC 2024 No. 72)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bull_terrier` (S589–S593) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bull_terrier` (exercise 60 min — PDSA "Minimum
| exercise (per day): 1 hour", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36, arrival 2 / 9 / 36, learning × 0.7 (Coren rank 66, Fair), paid breed, Border Collie care rates,
| brushing 1× a week (PDSA "a brush once a week", M5-R13), puppy socialisation with the PDSA quote (M5-R17), the
| 60-minute digestion warning (PDSA "Remember to leave a gap after eating and before exercising.", M5-R16)). No care
| task (ears / baths without a frequency). The senior boundary / senior arrival 90 (0.75 × PDSA lower bound "Over 10
| years") is Claude's suggestion, verified=false, open for David. Suitability: suits children; consider
| lively_older_children (new key), needs_company, chases_small_pets. PDSA weight 22–31 kg (the FCI standard sets no
| limits); white portrait; not a welfare breed → in marketing.
*/

const BTR_KEY = 'bull_terrier';
const BTR_SLUG = 'bull-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function btrData(string $path): mixed
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

/** @return array<string, mixed> the Bull Terrier seeder row of (stage, from, key) */
function btrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === BTR_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(BTR_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function btrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bullTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function btrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function btrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BullTerrier;
        $config = BreedConfig::where('breed_slug', BTR_SLUG)->sole();

        expect($breed->value)->toBe(BTR_KEY)
            ->and($breed->slug())->toBe(BTR_SLUG)
            ->and(BreedType::fromSlug(BTR_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 700, 'breeds.bull_terrier'])
            ->and($config->search_keywords)->toBe(['bull terrier', 'english bull terrier', 'bully', 'bulterier', 'bul terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) btrData('proposed_game_parameters.bull_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = btrData('proposed_game_parameters.bull_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bull Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = btrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(BTR_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'english_bull_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'miniature_bull_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_10_120000_add_bull_terrier_breed.php');
        // down() refuses while a Bull Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => BTR_KEY]))->toThrow(QueryException::class);
        // The Cairn Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cairn_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => BTR_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BullTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', BTR_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', BTR_SLUG)->count());

        BreedConfig::where('breed_slug', BTR_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', BTR_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', BTR_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', BTR_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', BTR_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, BTR_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BULL_TERRIER)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BULL_TERRIER_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_BULL_TERRIER', 'PROPOSED_R10_BULL_TERRIER_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bull_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(btrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(btrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(btrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(btrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PDSA lower bound "Over 10 years", S591) = 90 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btrData('bull_terrier.lifespan.senior_from.months'))
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btrData("{$pgp}.senior_boundary_months.value"))
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * btrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(btrData('bull_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S591'])
            ->and(btrData('bull_terrier.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S591')
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BULL_TERRIER_SENIOR)
            ->and(btrData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BULL_TERRIER_SENIOR)
            ->and(btrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(btrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(btrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(btrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btrData('bull_terrier.exercise.adult.quote'))
            ->and(btrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S591')
            ->and(btrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(btrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(btrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(btrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(btrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(btrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(btrData("{$pgp}.learning_multiplier.value"))
            ->and(btrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BULL_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(BTR_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bull_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) btrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13), socialisation (M5-R17) and the digestion
        // warning (M5-R16) are built.
        expect(json_encode([btrData(BTR_KEY), btrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(btrData("{$pgp}.grooming.value"))->toBe(1)
            ->and(btrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(btrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[BTR_KEY][1])
            ->and(btrData('bull_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[BTR_KEY][1])
            ->and(btrData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(btrData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[BTR_KEY][1])
            ->and(btrData('bull_terrier.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[BTR_KEY][1])
            // Ears / baths have no frequency → no care task; not flat-faced → no heat rule.
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey(BTR_KEY)
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(BTR_KEY);
    });

    it('takes the breed-level numbers from the sourced bull_terrier entries', function () {
        // PDSA (S591) "Average weight: 22-31 kg" (both sexes) — the FCI standard sets no limits.
        expect(btrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([22, 31])
            ->and(btrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(btrData('bull_terrier.adult_weight.pdsa.source_id'))
            ->and(btrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(btrData('bull_terrier.adult_weight.pdsa.quote'))
            ->and(btrData('bull_terrier.adult_weight.pdsa.value'))->toEqual(['male' => [22, 31], 'female' => [22, 31]])
            ->and(btrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(btrData('bull_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 66 (S35) → Fair → 0.7.
            ->and(btrRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(66)
            ->and(btrRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(btrData('bull_terrier.trainability.coren_rank.quote'))
            ->and(btrData('bull_terrier.trainability.coren_rank.value'))->toBe(66)
            ->and(btrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bull_terrier.lifespan.pdsa')
            ->and(btrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S591')
            ->and(btrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(btrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(btrData('bull_terrier.lifespan.pdsa.quote'));

        // Brushing once a week (PDSA "a brush once a week" → 1) — the rule, verified.
        $grooming = btrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S591')
            ->and($grooming['quote'])->toBe(btrData('bull_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(btrData('proposed_game_parameters.bull_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PDSA quote.
            ->and(btrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(btrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S591');

        // The 60-minute digestion warning (M5-R16) with the PDSA quote — David's rule, verified.
        $gap = btrRow('all', 0, StageParamKey::MealExerciseGapMinutes);
        expect($gap['value'])->toBe(60)
            ->and($gap['source_id'])->toBe('S591')
            ->and($gap['quote'])->toBe('Remember to leave a gap after eating and before exercising.')
            ->and($gap['verified'])->toBeTrue()
            ->and($gap['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', BTR_SLUG);
        // No tooth / fold / ear / nail / bath / groomer frequency, no heat rule → no care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->all())->toBe([]);
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BULL_TERRIER_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.bull_terrier.senior_boundary_months')
                ->and($row['value'])->toBe(btrData('proposed_game_parameters.bull_terrier.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (PDSA S591).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the digestion warning (PDSA S591).
            StageParamKey::MealExerciseGapMinutes,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(BTR_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = btrPet($arrival);
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
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(BTR_SLUG, 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(BTR_SLUG, 90))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren rank 66, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = btrPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => BTR_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = btrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(btrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BullTerrier)
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
        foreach (['border_collie', BTR_KEY] as $breed) {
            $pin = btrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(btrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[BTR_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(BTR_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = btrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bull Terrier after the Cairn Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, BTR_KEY, 'cairn_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => BTR_KEY, 'slug' => BTR_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bull_terrier',
            'search_keywords' => seededBreedConfig(BTR_KEY)['search_keywords'], 'sort_order' => 700,
            'suitability' => [
                'suits' => ['children'],
                'consider' => ['lively_older_children', 'needs_company', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S590 white, brindle, black brindle, red, fawn, tricolour)', function () {
    it('samples DNA in the six colours of the appearance block only, never blue, liver or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(BTR_KEY)
            ->and(PetDnaService::hasAppearance(BTR_KEY))->toBeTrue()
            ->and(config('breed_appearance.bull_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.bull_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample(BTR_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('short')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['muzzle'])->toContain('egg-shaped')
                ->and($traits['coat_color'])->toMatch('/^(pure white|brindle|black brindle|red|fawn|tricolour)\b/');

            $prompt = $prompts->imagePrompt(BTR_KEY, $traits);
            expect($prompt)->toContain('Bull Terrier')
                ->not->toMatch('/\b(merle|liver|blue|docked|cropped)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'pure white',
            'brindle, the colour predominating over any white markings',
            'black brindle, the colour predominating over any white markings',
            'red, the colour predominating over any white markings',
            'fawn, the colour predominating over any white markings',
            'tricolour (black, tan and white), the colour predominating',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.bull_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.bull_terrier'), 'key'))->toBe(['white', 'brindle', 'black-brindle', 'red', 'fawn', 'tricolour']);
    });

    it('draws the register portrait pure white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BullTerrier);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::BullTerrier))->toContain('Bull Terrier')->toContain('pure white')
            ->and(BreedPortraitService::relativeFile(BreedType::BullTerrier))->toBe('dog/bull-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BullTerrier);
        expect($v1['prompt_anchor'])->toContain('Bull Terrier')->toContain('pure white coat')->toContain('egg-shaped head')
            ->not->toMatch('/\b(cropped|merle|docked|liver)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['pure white', 'brindle with white markings', 'red with white markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark, almost black');

        $pet = btrPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bull Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bull_terrier'),
            config('breed_suitability.breeds.bull_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', BTR_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BullTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed; PDSA "Over 10 years" → no welfare chip, no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(btrData('proposed_game_parameters.bull_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (108 McMillan via Wikipedia / PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', BTR_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children; consider a lively dog for older children, company and the prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BullTerrier);
        expect($tags['suits'])->toBe(['children'])
            ->and($tags['consider'])->toBe(['lively_older_children', 'needs_company', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('sensitive_skin')->not->toContain('chews_when_bored')->not->toContain('sheds');

        // The new key exists once, as a consider tag.
        expect(config('breed_suitability.vocabulary.lively_older_children'))->toBe('consider')
            ->and(btrData('bull_terrier.behaviour.children.quote'))->toContain('better with older children')
            ->and(btrData('bull_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(btrData('bull_terrier.behaviour.time_alone.quote'))->toContain('company');
    });
});
