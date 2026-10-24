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
| M5-R10-56 — Chesapeake Bay Retriever (appended after the Wirehaired Pointing Griffon: AKC 2024 No. 52)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `chesapeake_bay_retriever` (S457–S462) and the runbook's standing
| rules (confirmed by David 2026-10-10) in `proposed_game_parameters.chesapeake_bay_retriever` (exercise 120 min —
| RKC "Exercise: More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 —
| 0.75 × RKC lower bound "Over 10 years"; the Dogs Trust McMillan 2024 table could not be fetched —, arrival
| 2 / 9 / 36 / 90, learning × 1.2 (Coren rank 27, Above average), paid breed, Border Collie care rates, brushing once
| a week (RKC "Grooming: Once a week", M5-R13), puppy socialisation with the PetMD quote (M5-R17), the digestion
| warning with the PetMD quote (M5-R16); suitability: suits active_family / family_pet / children / large_home,
| consider long_daily_exercise / needs_company / sheds; brown portrait (never black); RKC Breed Watch Category 1,
| in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function cbrData(string $path): mixed
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

/** @return array<string, mixed> the Chesapeake Bay Retriever seeder row of (stage, from, key) */
function cbrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'chesapeake-bay-retriever'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("chesapeake_bay_retriever.{$stage}.{$from}.{$key->value}");

    return $row;
}

function cbrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->chesapeakeBayRetriever()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function cbrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function cbrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ChesapeakeBayRetriever;
        $config = BreedConfig::where('breed_slug', 'chesapeake-bay-retriever')->sole();

        expect($breed->value)->toBe('chesapeake_bay_retriever')
            ->and($breed->slug())->toBe('chesapeake-bay-retriever')
            ->and(BreedType::fromSlug('chesapeake-bay-retriever'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 520, 'breeds.chesapeake_bay_retriever'])
            ->and($config->search_keywords)->toBe(['chesapeake bay retriever', 'chesapeake', 'chessie', 'chesapeake retriever', 'chesapeake bay retriver', 'chesapeake retriver'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) cbrData('proposed_game_parameters.chesapeake_bay_retriever.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = cbrData('proposed_game_parameters.chesapeake_bay_retriever.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Chesapeake Bay Retriever pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = cbrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('chesapeake_bay_retriever');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'chessie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'chesapeake']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_12_120000_add_chesapeake_bay_retriever_breed.php');
        // down() refuses while a Chesapeake Bay Retriever exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'chesapeake_bay_retriever']))->toThrow(QueryException::class);
        // The Wirehaired Pointing Griffon (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'wirehaired_pointing_griffon']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chesapeake_bay_retriever']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ChesapeakeBayRetriever);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'chesapeake-bay-retriever')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chesapeake-bay-retriever')->count());

        BreedConfig::where('breed_slug', 'chesapeake-bay-retriever')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'chesapeake-bay-retriever')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'chesapeake-bay-retriever')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'chesapeake-bay-retriever');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chesapeake-bay-retriever');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'chesapeake-bay-retriever'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CHESAPEAKE_BAY_RETRIEVER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_CHESAPEAKE_BAY_RETRIEVER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.chesapeake_bay_retriever';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(cbrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(cbrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(cbrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(cbrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(cbrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(cbrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S459) = 90 — the runbook's third choice.
            ->and(cbrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(cbrData('chesapeake_bay_retriever.lifespan.senior_from.months'))
            ->and(cbrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * cbrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(cbrData('chesapeake_bay_retriever.lifespan.senior_from.derived_from'))->toBe(['S11', 'S459'])
            ->and(cbrData('chesapeake_bay_retriever.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(cbrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S459')
            ->and(cbrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(cbrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(cbrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(cbrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(cbrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(cbrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(cbrData('chesapeake_bay_retriever.exercise.adult.quote'))
            ->and(cbrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S459')
            ->and(cbrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(cbrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(cbrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(cbrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(cbrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(cbrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(cbrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(cbrData("{$pgp}.learning_multiplier.value"))
            ->and(cbrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CHESAPEAKE_BAY_RETRIEVER) {
                $decided++;
                expect($row['breed_slug'])->toBe('chesapeake-bay-retriever')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.chesapeake_bay_retriever.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) cbrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); a slow feeder / no raised bowl has no
        // mechanic → open for David (a new ROADMAP task).
        expect(json_encode([cbrData('chesapeake_bay_retriever'), cbrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(cbrData("{$pgp}.grooming.value"))->toBe(1)
            ->and(cbrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(cbrData("{$pgp}.bloat_feeding.decision"))->toContain('nova naloga v ROADMAP')
            ->and(cbrData($pgp))->not->toHaveKey('hand_stripping')
            ->and(cbrData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(cbrData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['chesapeake_bay_retriever'][1])
            ->and(cbrData('chesapeake_bay_retriever.health.gdv.notes'))->toContain(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['chesapeake_bay_retriever'][1])
            ->and(cbrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['chesapeake_bay_retriever'][1])
            ->and(cbrData('chesapeake_bay_retriever.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['chesapeake_bay_retriever'][1]);
    });

    it('takes the breed-level numbers from the sourced chesapeake_bay_retriever entries', function () {
        // FCI standard No. 263 (S458): males 29.5–36.5 kg, females 25–32 kg → 25–36.5 kg.
        expect(cbrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([25.0, 36.5])
            ->and(cbrData('chesapeake_bay_retriever.adult_weight.fci.value'))->toEqual(['male' => [29.5, 36.5], 'female' => [25, 32]])
            ->and(cbrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(cbrData('chesapeake_bay_retriever.adult_weight.fci.source_id'))
            ->and(cbrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(cbrData('chesapeake_bay_retriever.adult_weight.fci.quote'))
            ->and(cbrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(cbrData('chesapeake_bay_retriever.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 27 (S35), Above average tier → learning 1.2.
            ->and(cbrRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(27)
            ->and(cbrRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(cbrData('chesapeake_bay_retriever.trainability.coren_rank.quote'))
            ->and(cbrData('chesapeake_bay_retriever.trainability.coren_rank.value'))->toBe(27)
            ->and(cbrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('chesapeake_bay_retriever.lifespan.rkc')
            ->and(cbrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S459')
            ->and(cbrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(cbrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(cbrData('chesapeake_bay_retriever.lifespan.rkc.quote'));

        // Brushing once a week (RKC "Grooming: Once a week" → 1) — the rule, verified.
        $grooming = cbrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S459')
            ->and($grooming['quote'])->toBe(cbrData('chesapeake_bay_retriever.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(cbrData('proposed_game_parameters.chesapeake_bay_retriever.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(cbrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(cbrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S461')
            // The digestion warning (M5-R16): 60 min, PetMD's verbatim advice.
            ->and(cbrRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(cbrRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S461');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chesapeake-bay-retriever');
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
            // M5-R16 / M5-R17: the digestion warning (PetMD S461) and the puppy's weekly "new experience"
            // with the breed's own PetMD quote (S461).
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

        expect($general('chesapeake-bay-retriever'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = cbrPet($arrival);
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

    it('becomes senior at 90 months, like the Wirehaired Pointing Griffon and 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('chesapeake-bay-retriever', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('chesapeake-bay-retriever', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Above average tier, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = cbrPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'chesapeake_bay_retriever', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = cbrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(cbrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ChesapeakeBayRetriever)
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
        foreach (['border_collie', 'chesapeake_bay_retriever'] as $breed) {
            $pin = cbrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(cbrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['chesapeake_bay_retriever'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('chesapeake_bay_retriever')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = cbrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Chesapeake Bay Retriever after the Wirehaired Pointing Griffon with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'chesapeake_bay_retriever', 'wirehaired_pointing_griffon');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'chesapeake_bay_retriever', 'slug' => 'chesapeake-bay-retriever', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.chesapeake_bay_retriever',
            'search_keywords' => seededBreedConfig('chesapeake-bay-retriever')['search_keywords'], 'sort_order' => 520,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home'],
                'consider' => ['long_daily_exercise', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — never black)', function () {
    it('samples DNA in the three standard colours of the appearance block only, never black', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('chesapeake_bay_retriever')
            ->and(PetDnaService::hasAppearance('chesapeake_bay_retriever'))->toBeTrue()
            ->and(config('breed_appearance.chesapeake_bay_retriever.verified'))->toBeFalse()
            ->and(config('breed_appearance.chesapeake_bay_retriever.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('chesapeake_bay_retriever', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('woolly undercoat')
                ->and($traits['tail'])->toContain('natural tail')
                ->and($traits['eye_color'])->toContain('amber');

            $prompt = $prompts->imagePrompt('chesapeake_bay_retriever', $traits);
            expect($prompt)->toContain('Chesapeake Bay Retriever')
                ->not->toMatch('/\b(black|merle|docked|cropped|curly|Labrador|Golden)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['solid brown', 'solid sedge (reddish gold)', 'solid deadgrass (straw to dull tan)'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.chesapeake_bay_retriever'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.chesapeake_bay_retriever'), 'key'))->toBe(['brown', 'sedge', 'deadgrass']);
    });

    it('draws the register portrait brown', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ChesapeakeBayRetriever);

        expect($traits['coat_color'])->toBe('solid brown')
            ->and($portrait->prompt(BreedType::ChesapeakeBayRetriever))->toContain('Chesapeake Bay Retriever')->toContain('brown')
            ->and(BreedPortraitService::relativeFile(BreedType::ChesapeakeBayRetriever))->toBe('dog/chesapeake-bay-retriever.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ChesapeakeBayRetriever);
        expect($v1['prompt_anchor'])->toContain('Chesapeake Bay Retriever')->toContain('solid brown')
            ->not->toMatch('/\b(black|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid brown', 'solid sedge (reddish gold)', 'solid deadgrass (straw to dull tan)'])
            ->and($v1['visual_traits']['eye_color'])->toBe('clear yellowish or amber');

        $pet = cbrPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Chesapeake Bay Retriever')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.chesapeake_bay_retriever'),
            config('breed_suitability.breeds.chesapeake_bay_retriever'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chesapeake-bay-retriever')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ChesapeakeBayRetriever);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S459) → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(cbrData('chesapeake_bay_retriever.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(cbrData('proposed_game_parameters.chesapeake_bay_retriever.marketing.source_id'))->toBe('S459')
            ->and(cbrData('proposed_game_parameters.chesapeake_bay_retriever.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and the KC survey via Wikipedia (97) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'chesapeake-bay-retriever')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(97);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family, family, children and large-home chips plus long exercise, company and shedding; never small_children, other_pets, apartment or a prey-drive chip', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ChesapeakeBayRetriever);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company', 'sheds'])
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('frequent_grooming')
            ->not->toContain('experienced_owner')->not->toContain('older_children');

        expect(cbrData('chesapeake_bay_retriever.suitability.rkc_exercise.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(cbrData('chesapeake_bay_retriever.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(cbrData('chesapeake_bay_retriever.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(cbrData('chesapeake_bay_retriever.suitability.petmd_family.quote'))->toBe('With proper introductions and socialization, Chessies would be a great addition to a family.')
            ->and(cbrData('chesapeake_bay_retriever.behaviour.family.quote'))->toBe('Playful and generally good with children.')
            ->and(cbrData('chesapeake_bay_retriever.behaviour.time_alone.quote'))->toContain('left alone for too long');
    });
});
