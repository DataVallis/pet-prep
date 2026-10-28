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
| M5-R10-73 — Nova Scotia Duck Tolling Retriever (appended after the Chinese Crested: AKC 2024 No. 69)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `nova_scotia_duck_tolling_retriever` (S576–S580) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.nova_scotia_duck_tolling_retriever`
| (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 119
| (0.75 × McMillan 2024 median 13.2 y, Dogs Trust table S123), arrival 2 / 9 / 36 / 119, learning × 1.0 (not ranked by
| Coren), paid breed, Border Collie care rates, brushing 2× a week (RKC "More than once a week" → PetMD "once or twice a
| week", M5-R13), puppy socialisation with the AKC quote (M5-R17)). No meal gap, no care task (ear cleaning only after
| water — no frequency). Suitability: suits family_pet / active_family / children; consider needs_mental_stimulation,
| sheds, frequent_grooming, chases_small_pets. No shorter_lifespan (the RKC "Under 10 years" conflicts with McMillan /
| PetMD). FCI weight by sex; red with white markings portrait; not a welfare breed → in marketing.
*/

const TOL_KEY = 'nova_scotia_duck_tolling_retriever';
const TOL_SLUG = 'nova-scotia-duck-tolling-retriever';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function tolData(string $path): mixed
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

/** @return array<string, mixed> the Nova Scotia Duck Tolling Retriever seeder row of (stage, from, key) */
function tolRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === TOL_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(TOL_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function tolPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->novaScotiaDuckTollingRetriever()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function tolPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function tolLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::NovaScotiaDuckTollingRetriever;
        $config = BreedConfig::where('breed_slug', TOL_SLUG)->sole();

        expect($breed->value)->toBe(TOL_KEY)
            ->and($breed->slug())->toBe(TOL_SLUG)
            ->and(BreedType::fromSlug(TOL_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 680, 'breeds.nova_scotia_duck_tolling_retriever'])
            ->and($config->search_keywords)->toBe(['nova scotia duck tolling retriever', 'nova scotia retriever', 'duck tolling retriever', 'toller', 'tolling retriever', 'nsdtr', 'novoškotski prinašalec', 'novoskotski prinasalec', 'novoškotski retriver', 'prinašalec rac'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) tolData('proposed_game_parameters.nova_scotia_duck_tolling_retriever.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = tolData('proposed_game_parameters.nova_scotia_duck_tolling_retriever.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Nova Scotia Duck Tolling Retriever pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = tolPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(TOL_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'toller']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'novoskotski_prinasalec']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_05_120000_add_nova_scotia_duck_tolling_retriever_breed.php');
        // down() refuses while a Nova Scotia Duck Tolling Retriever exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => TOL_KEY]))->toThrow(QueryException::class);
        // The Chinese Crested (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chinese_crested']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => TOL_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::NovaScotiaDuckTollingRetriever);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', TOL_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', TOL_SLUG)->count());

        BreedConfig::where('breed_slug', TOL_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', TOL_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', TOL_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', TOL_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', TOL_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, TOL_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NOVA_SCOTIA_DUCK_TOLLING_RETRIEVER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_NOVA_SCOTIA_DUCK_TOLLING_RETRIEVER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.nova_scotia_duck_tolling_retriever';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(tolRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(tolData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(tolRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tolData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(tolRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tolData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.2 y (McMillan 2024 median via the Dogs Trust table, S123) = 118.8 → 119 — the runbook rule, verified.
            ->and(tolRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(tolData('nova_scotia_duck_tolling_retriever.lifespan.senior_from.months'))
            ->and(tolRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * tolRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(tolData('nova_scotia_duck_tolling_retriever.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(tolRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(tolRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(tolRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(119);

        expect(tolRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tolData("{$pgp}.exercise_minutes_adult.value"))
            ->and(tolRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tolRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tolData('nova_scotia_duck_tolling_retriever.exercise.adult.quote'))
            ->and(tolRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S578')
            ->and(tolRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(tolRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tolData("{$pgp}.exercise_minutes_senior.value"))
            ->and(tolRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(tolRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(tolData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(tolRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(tolData("{$pgp}.learning_multiplier.value"))
            ->and(tolRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NOVA_SCOTIA_DUCK_TOLLING_RETRIEVER) {
                $decided++;
                expect($row['breed_slug'])->toBe(TOL_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.nova_scotia_duck_tolling_retriever.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) tolData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13) and socialisation (M5-R17) are built.
        expect(json_encode([tolData(TOL_KEY), tolData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(tolData("{$pgp}.grooming.value"))->toBe(2)
            ->and(tolData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(tolData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[TOL_KEY][1])
            ->and(tolData('nova_scotia_duck_tolling_retriever.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[TOL_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(TOL_KEY)
            // Ear cleaning only after water (no frequency) → no care task; no heat rule.
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey(TOL_KEY)
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(TOL_KEY);
    });

    it('takes the breed-level numbers from the sourced nova_scotia_duck_tolling_retriever entries', function () {
        // FCI (S577): males 20–23 kg, females 17–20 kg → 17–23 kg.
        expect(tolRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([17, 23])
            ->and(tolRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(tolData('nova_scotia_duck_tolling_retriever.adult_weight.fci.source_id'))
            ->and(tolRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(tolData('nova_scotia_duck_tolling_retriever.adult_weight.fci.quote'))
            ->and(tolData('nova_scotia_duck_tolling_retriever.adult_weight.fci.value'))->toEqual(['male' => [20, 23], 'female' => [17, 20]])
            ->and(tolRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(tolData('nova_scotia_duck_tolling_retriever.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not ranked by Coren (S35) → learning 1.0.
            ->and(tolRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(tolData('nova_scotia_duck_tolling_retriever.trainability.coren_rank.value'))->toBeNull()
            ->and(tolRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('nova_scotia_duck_tolling_retriever.lifespan.median_uk')
            ->and(tolRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(tolRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.2)
            ->and(tolRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(tolData('nova_scotia_duck_tolling_retriever.lifespan.median_uk.quote'));

        // Brushing 2× a week (RKC "More than once a week" → PetMD "once or twice a week" → 2) — the rule, verified.
        $grooming = tolRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S579')
            ->and($grooming['quote'])->toBe(tolData('nova_scotia_duck_tolling_retriever.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(tolData('proposed_game_parameters.nova_scotia_duck_tolling_retriever.grooming.quote'))
            ->and(tolData('nova_scotia_duck_tolling_retriever.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the AKC quote.
            ->and(tolRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(tolRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S580');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', TOL_SLUG);
        // No meal / walk gap (no source).
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);
        // No tooth / fold / ear / nail / bath / groomer frequency, no heat rule → no care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->all())->toBe([]);
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposal: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (AKC S580).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(TOL_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = tolPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 118 months' => [118, LifeStage::Adult, 60, 6000],
        'senior 119 months' => [119, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 119 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(TOL_SLUG, 118))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(TOL_SLUG, 119))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = tolPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => TOL_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = tolPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(tolLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::NovaScotiaDuckTollingRetriever)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 119 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', TOL_KEY] as $breed) {
            $pin = tolPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(tolLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[TOL_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(119)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(TOL_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = tolPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Nova Scotia Duck Tolling Retriever after the Chinese Crested with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, TOL_KEY, 'chinese_crested');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => TOL_KEY, 'slug' => TOL_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.nova_scotia_duck_tolling_retriever',
            'search_keywords' => seededBreedConfig(TOL_KEY)['search_keywords'], 'sort_order' => 680,
            'suitability' => [
                'suits' => ['family_pet', 'active_family', 'children'],
                'consider' => ['needs_mental_stimulation', 'sheds', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S577, red or orange shades)', function () {
    it('samples DNA in the three colours of the appearance block only, never black, grey or silver', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(TOL_KEY)
            ->and(PetDnaService::hasAppearance(TOL_KEY))->toBeTrue()
            ->and(config('breed_appearance.nova_scotia_duck_tolling_retriever.verified'))->toBeFalse()
            ->and(config('breed_appearance.nova_scotia_duck_tolling_retriever.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(TOL_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('double coat')
                ->and($traits['tail'])->toContain('feathered')
                ->and($traits['coat_color'])->toMatch('/^(red|orange|solid red)/');

            $prompt = $prompts->imagePrompt(TOL_KEY, $traits);
            expect($prompt)->toContain('Nova Scotia Duck Tolling Retriever')
                ->not->toMatch('/\b(merle|docked|cropped|black coat|grey|gray|silver)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'red with a white blaze, white chest, white feet and a white tail tip',
            'orange with a white blaze, white chest, white feet and a white tail tip',
            'solid red with lighter feathering and no white',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.nova_scotia_duck_tolling_retriever'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.nova_scotia_duck_tolling_retriever'), 'key'))->toBe(['red-white', 'orange-white', 'red']);
    });

    it('draws the register portrait red with white markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::NovaScotiaDuckTollingRetriever);

        expect($traits['coat_color'])->toBe('red with a white blaze, white chest, white feet and a white tail tip')
            ->and($portrait->prompt(BreedType::NovaScotiaDuckTollingRetriever))->toContain('Nova Scotia Duck Tolling Retriever')->toContain('red with a white blaze')
            ->and(BreedPortraitService::relativeFile(BreedType::NovaScotiaDuckTollingRetriever))->toBe('dog/nova-scotia-duck-tolling-retriever.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::NovaScotiaDuckTollingRetriever);
        expect($v1['prompt_anchor'])->toContain('Nova Scotia Duck Tolling Retriever')->toContain('red double coat')->toContain('white tail tip')
            ->not->toMatch('/\b(cropped|merle|docked|silver)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red with a white blaze, chest, feet and tail tip', 'orange with a white blaze, chest, feet and tail tip', 'solid red with lighter feathering'])
            ->and($v1['visual_traits']['eye_color'])->toBe('amber to brown');

        $pet = tolPet(119, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Nova Scotia Duck Tolling Retriever')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.nova_scotia_duck_tolling_retriever'),
            config('breed_suitability.breeds.nova_scotia_duck_tolling_retriever'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', TOL_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::NovaScotiaDuckTollingRetriever);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Breed Watch Category 1, not flat-faced → no welfare chip; the RKC "Under 10 years" conflicts → no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(tolData('proposed_game_parameters.nova_scotia_duck_tolling_retriever.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')->not->toContain('low_shedding')
            // The alternatives (108 PetMD lower bound, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', TOL_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families and children; consider stimulation, shedding, brushing and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::NovaScotiaDuckTollingRetriever);
        expect($tags['suits'])->toBe(['family_pet', 'active_family', 'children'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'sheds', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('long_daily_exercise');

        expect(tolData('nova_scotia_duck_tolling_retriever.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(tolData('nova_scotia_duck_tolling_retriever.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house')
            ->and(tolData('nova_scotia_duck_tolling_retriever.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(tolData('nova_scotia_duck_tolling_retriever.lifespan.rkc.quote'))->toBe('Lifespan: Under 10 years');
    });
});
