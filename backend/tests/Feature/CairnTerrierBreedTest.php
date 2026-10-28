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
| M5-R10-75 — Cairn Terrier (appended after the Biewer Terrier, blocked: AKC 2024 No. 71)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `cairn_terrier` (S583–S588) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.cairn_terrier` (exercise 60 min — PDSA "Minimum
| exercise (per day): 1 hour", the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36 / 126 (0.75 × McMillan 2024 median 14.0 y, Dogs Trust table S123), arrival 2 / 9 / 36 / 126,
| learning × 1.2 (Coren rank 35, Above average), paid breed, Border Collie care rates, brushing 2× a week (PDSA
| "grooming 1-2 times per week", M5-R13), puppy socialisation with the PDSA quote (M5-R17)). No meal gap, no care task
| (hand-stripping twice a year is longer than every care period; ears / baths without a frequency). Suitability:
| suits family_pet / children / low_shedding; consider needs_mental_stimulation. No chases_small_pets (no source
| says "prey drive"). KC weight 6–7.5 kg; wheaten portrait; not a welfare breed → in marketing.
*/

const CAI_KEY = 'cairn_terrier';
const CAI_SLUG = 'cairn-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function caiData(string $path): mixed
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

/** @return array<string, mixed> the Cairn Terrier seeder row of (stage, from, key) */
function caiRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === CAI_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(CAI_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function caiPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->cairnTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function caiPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function caiLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::CairnTerrier;
        $config = BreedConfig::where('breed_slug', CAI_SLUG)->sole();

        expect($breed->value)->toBe(CAI_KEY)
            ->and($breed->slug())->toBe(CAI_SLUG)
            ->and(BreedType::fromSlug(CAI_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 690, 'breeds.cairn_terrier'])
            ->and($config->search_keywords)->toBe(['cairn terrier', 'cairn', 'cairnie', 'cairn terier', 'cairnski terier', 'kern terier', 'kernski terier', 'škotski terier cairn'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) caiData('proposed_game_parameters.cairn_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = caiData('proposed_game_parameters.cairn_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Cairn Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = caiPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(CAI_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'cairn']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'cairn_terier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_06_120000_add_cairn_terrier_breed.php');
        // down() refuses while a Cairn Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => CAI_KEY]))->toThrow(QueryException::class);
        // The Nova Scotia Duck Tolling Retriever (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'nova_scotia_duck_tolling_retriever']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => CAI_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::CairnTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', CAI_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CAI_SLUG)->count());

        BreedConfig::where('breed_slug', CAI_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', CAI_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', CAI_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', CAI_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CAI_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, CAI_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAIRN_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_CAIRN_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.cairn_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(caiRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(caiData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(caiRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(caiData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(caiRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(caiData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 14.0 y (McMillan 2024 median via the Dogs Trust table, S123) = 126 — the runbook rule, verified.
            ->and(caiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(caiData('cairn_terrier.lifespan.senior_from.months'))
            ->and(caiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * caiRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(caiData('cairn_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(caiRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(caiRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(caiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(126);

        expect(caiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(caiData("{$pgp}.exercise_minutes_adult.value"))
            ->and(caiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(caiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(caiData('cairn_terrier.exercise.adult.quote'))
            ->and(caiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S585')
            ->and(caiRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(caiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(caiData("{$pgp}.exercise_minutes_senior.value"))
            ->and(caiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(caiRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(caiData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(caiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(caiData("{$pgp}.learning_multiplier.value"))
            ->and(caiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CAIRN_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(CAI_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.cairn_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) caiData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13) and socialisation (M5-R17) are built.
        expect(json_encode([caiData(CAI_KEY), caiData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(caiData("{$pgp}.grooming.value"))->toBe(2)
            ->and(caiData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(caiData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[CAI_KEY][1])
            ->and(caiData('cairn_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[CAI_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(CAI_KEY)
            // Hand-stripping twice a year is longer than every care period; ears / baths have no frequency → no care task.
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey(CAI_KEY)
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(CAI_KEY);
    });

    it('takes the breed-level numbers from the sourced cairn_terrier entries', function () {
        // KC standard (S584): "Ideally 6kg – 7.5kg" (both sexes).
        expect(caiRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6, 7.5])
            ->and(caiRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(caiData('cairn_terrier.adult_weight.kc_standard.source_id'))
            ->and(caiRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(caiData('cairn_terrier.adult_weight.kc_standard.quote'))
            ->and(caiData('cairn_terrier.adult_weight.kc_standard.value'))->toEqual(['male' => [6, 7.5], 'female' => [6, 7.5]])
            ->and(caiRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(caiData('cairn_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 35 (S35) → Above average → 1.2.
            ->and(caiRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(35)
            ->and(caiData('cairn_terrier.trainability.coren_rank.value'))->toBe(35)
            ->and(caiRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('cairn_terrier.lifespan.median_uk')
            ->and(caiRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(caiRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.0)
            ->and(caiRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(caiData('cairn_terrier.lifespan.median_uk.quote'));

        // Brushing 2× a week (PDSA "grooming 1-2 times per week" → upper bound 2) — the rule, verified.
        $grooming = caiRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S585')
            ->and($grooming['quote'])->toBe(caiData('cairn_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(caiData('proposed_game_parameters.cairn_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PDSA quote.
            ->and(caiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(caiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S585');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CAI_SLUG);
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
            // M5-R17: the puppy's weekly "new experience" (PDSA S585).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(CAI_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = caiPet($arrival);
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
        'adult 125 months' => [125, LifeStage::Adult, 60, 6000],
        'senior 126 months' => [126, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 126 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(CAI_SLUG, 125))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(CAI_SLUG, 126))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren rank 35, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = caiPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => CAI_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = caiPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(caiLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::CairnTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 126 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', CAI_KEY] as $breed) {
            $pin = caiPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(caiLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[CAI_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(126)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(CAI_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = caiPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Cairn Terrier after the Nova Scotia Duck Tolling Retriever with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, CAI_KEY, 'nova_scotia_duck_tolling_retriever');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => CAI_KEY, 'slug' => CAI_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.cairn_terrier',
            'search_keywords' => seededBreedConfig(CAI_KEY)['search_keywords'], 'sort_order' => 690,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'low_shedding'],
                'consider' => ['needs_mental_stimulation'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — KC S584 cream, wheaten, red, grey or nearly black)', function () {
    it('samples DNA in the six colours of the appearance block only, never solid black, white or black and tan', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(CAI_KEY)
            ->and(PetDnaService::hasAppearance(CAI_KEY))->toBeTrue()
            ->and(config('breed_appearance.cairn_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.cairn_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample(CAI_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('double coat')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['coat_color'])->toMatch('/^(wheaten|red|cream|grey|grey brindle|nearly black)\b/');

            $prompt = $prompts->imagePrompt(CAI_KEY, $traits);
            expect($prompt)->toContain('Cairn Terrier')
                ->not->toMatch('/\b(merle|docked|cropped|white coat|black and tan|solid black)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'wheaten with darker ears and muzzle',
            'red with darker ears and muzzle',
            'cream with darker ears and muzzle',
            'grey with darker ears and muzzle',
            'grey brindle with darker ears and muzzle',
            'nearly black (dark grey brindle)',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.cairn_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.cairn_terrier'), 'key'))->toBe(['wheaten', 'red', 'cream', 'grey', 'brindle', 'nearly-black']);
    });

    it('draws the register portrait wheaten', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::CairnTerrier);

        expect($traits['coat_color'])->toBe('wheaten with darker ears and muzzle')
            ->and($portrait->prompt(BreedType::CairnTerrier))->toContain('Cairn Terrier')->toContain('wheaten with darker ears and muzzle')
            ->and(BreedPortraitService::relativeFile(BreedType::CairnTerrier))->toBe('dog/cairn-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::CairnTerrier);
        expect($v1['prompt_anchor'])->toContain('Cairn Terrier')->toContain('wheaten double coat')->toContain('darker ears and muzzle')
            ->not->toMatch('/\b(cropped|merle|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['wheaten with darker ears and muzzle', 'red with darker ears and muzzle', 'grey brindle with darker ears and muzzle'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark hazel');

        $pet = caiPet(126, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Cairn Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.cairn_terrier'),
            config('breed_suitability.breeds.cairn_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', CAI_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::CairnTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed; median 14.0 y → no welfare chip, no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(caiData('proposed_game_parameters.cairn_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (108 Dogs Trust breed page, 90 PDSA, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CAI_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families, children and low shedding; consider mental stimulation — no prey-drive chip without the words', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::CairnTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'low_shedding'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('frequent_grooming')->not->toContain('sheds');

        expect(caiData('cairn_terrier.suitability.dogs_trust_shedding.quote'))->toBe('Medium length with low shedding')
            ->and(caiData('cairn_terrier.behaviour.other_pets.quote'))->not->toContain('prey drive')
            ->and(caiData('cairn_terrier.behaviour.children.quote'))->toContain('supervised');
    });
});
