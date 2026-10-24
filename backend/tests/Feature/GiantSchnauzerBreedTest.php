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
| M5-R10-61 — Giant Schnauzer (appended after the Giant Schnauzer: AKC 2024 No. 57)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `giant_schnauzer` (S491–S499) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.giant_schnauzer` (exercise 120 min — RKC "Exercise: More
| than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 108 — 0.75 × RKC lower
| bound "Over 12 years"; the Dogs Trust McMillan 2024 table could not be fetched —, arrival 2 / 9 / 36 / 108, learning
| × 1.2 (Coren rank 28, Above average), paid breed, Border Collie care rates, brushing every day (RKC "Grooming: Every
| day", M5-R13), puppy socialisation with the PDSA quote (M5-R17), no digestion warning (no gap quote); suitability:
| suits active_family / family_pet / large_home / low_shedding, consider long_daily_exercise / experienced_owner /
| not_for_small_children / needs_company / frequent_grooming; black portrait (pepper and salt, black and silver; natural
| ears and tail); RKC Breed Watch Category 1, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function gizData(string $path): mixed
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

/** @return array<string, mixed> the Giant Schnauzer seeder row of (stage, from, key) */
function gizRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'giant-schnauzer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("giant_schnauzer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function gizPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->giantSchnauzer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function gizPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function gizLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::GiantSchnauzer;
        $config = BreedConfig::where('breed_slug', 'giant-schnauzer')->sole();

        expect($breed->value)->toBe('giant_schnauzer')
            ->and($breed->slug())->toBe('giant-schnauzer')
            ->and(BreedType::fromSlug('giant-schnauzer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 570, 'breeds.giant_schnauzer'])
            ->and($config->search_keywords)->toBe(['giant schnauzer', 'veliki šnavcer', 'veliki snavcer', 'riesenschnauzer', 'riesen', 'munich schnauzer'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) gizData('proposed_game_parameters.giant_schnauzer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = gizData('proposed_game_parameters.giant_schnauzer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Giant Schnauzer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = gizPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('giant_schnauzer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'riesenschnauzer']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'miniature_schnauzer_giant']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_22_120000_add_giant_schnauzer_breed.php');
        // down() refuses while an Giant Schnauzer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'giant_schnauzer']))->toThrow(QueryException::class);
        // The Italian Greyhound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'italian_greyhound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'giant_schnauzer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::GiantSchnauzer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'giant-schnauzer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'giant-schnauzer')->count());

        BreedConfig::where('breed_slug', 'giant-schnauzer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'giant-schnauzer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'giant-schnauzer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'giant-schnauzer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'giant-schnauzer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'giant-schnauzer'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_GIANT_SCHNAUZER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_GIANT_SCHNAUZER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.giant_schnauzer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(gizRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(gizData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(gizRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gizData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(gizRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(gizData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S495) = 108 — the runbook's third choice.
            ->and(gizRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(gizData('giant_schnauzer.lifespan.senior_from.months'))
            ->and(gizRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * gizRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(gizData('giant_schnauzer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S495'])
            ->and(gizData('giant_schnauzer.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(gizRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S495')
            ->and(gizRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(gizRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(gizRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gizData("{$pgp}.exercise_minutes_adult.value"))
            ->and(gizRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(gizRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(gizData('giant_schnauzer.exercise.adult.quote'))
            ->and(gizRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S495')
            ->and(gizRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(gizRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(gizData("{$pgp}.exercise_minutes_senior.value"))
            ->and(gizRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(gizRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(gizData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(gizRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(gizData("{$pgp}.learning_multiplier.value"))
            ->and(gizRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_GIANT_SCHNAUZER) {
                $decided++;
                expect($row['breed_slug'])->toBe('giant-schnauzer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.giant_schnauzer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) gizData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built (brushing
        // M5-R13, socialisation M5-R17); professional grooming / stripping → M5-R18, weekly ear checks → M5-R20 (David).
        // No meal / walk gap quote → no digestion warning.
        expect(json_encode([gizData('giant_schnauzer'), gizData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(gizData("{$pgp}.grooming.value"))->toBe(7)
            ->and(gizData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(gizData("{$pgp}.professional_grooming.decision"))->toContain('M5-R18')
            ->and(gizData("{$pgp}.ear_checks.decision"))->toContain('M5-R20')
            ->and(gizData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('giant_schnauzer')
            ->and(gizData('giant_schnauzer.exercise.after_meals.source_id'))->toBeNull()
            ->and(gizData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['giant_schnauzer'][1])
            ->and(gizData('giant_schnauzer.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['giant_schnauzer'][1]);
    });

    it('takes the breed-level numbers from the sourced giant_schnauzer entries', function () {
        // FCI standard (S492): 35–47 kg.
        expect(gizRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([35, 47])
            ->and(gizData('giant_schnauzer.adult_weight.fci.value'))->toEqual(['male' => [35, 47], 'female' => [35, 47]])
            ->and(gizRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(gizData('giant_schnauzer.adult_weight.fci.source_id'))
            ->and(gizRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(gizData('giant_schnauzer.adult_weight.fci.quote'))
            ->and(gizRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(gizData('giant_schnauzer.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 28 (S35), Above average tier → learning 1.2.
            ->and(gizRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(28)
            ->and(gizRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(gizData('giant_schnauzer.trainability.coren_rank.quote'))
            ->and(gizData('giant_schnauzer.trainability.coren_rank.value'))->toBe(28)
            ->and(gizRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('giant_schnauzer.lifespan.rkc')
            ->and(gizRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S495')
            ->and(gizRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(gizRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(gizData('giant_schnauzer.lifespan.rkc.quote'));

        // Brushing every day (RKC "Grooming: Every day" → 7) — the rule, verified.
        $grooming = gizRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S495')
            ->and($grooming['quote'])->toBe(gizData('giant_schnauzer.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(gizData('proposed_game_parameters.giant_schnauzer.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(gizRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(gizRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S496');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'giant-schnauzer');
        // Like the other pedigree breeds: no individual variation row; no digestion warning (no source).
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

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
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S496).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('giant-schnauzer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = gizPet($arrival);
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
        'adult 107 months' => [107, LifeStage::Adult, 120, 12000],
        'senior 108 months' => [108, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 108 months, like the Italian Greyhound and 18 months after the Weimaraner (90)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('giant-schnauzer', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('giant-schnauzer', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('italian-greyhound', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('weimaraner', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('weimaraner', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Above average tier, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = gizPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'giant_schnauzer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = gizPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(gizLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::GiantSchnauzer)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 108 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'giant_schnauzer'] as $breed) {
            $pin = gizPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(gizLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['giant_schnauzer'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('giant_schnauzer')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = gizPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Giant Schnauzer after the Italian Greyhound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'giant_schnauzer', 'italian_greyhound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'giant_schnauzer', 'slug' => 'giant-schnauzer', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.giant_schnauzer',
            'search_keywords' => seededBreedConfig('giant_schnauzer')['search_keywords'], 'sort_order' => 570,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'large_home', 'low_shedding'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — black, pepper and salt, black and silver; natural ears and tail)', function () {
    it('samples DNA in the three standard colours of the appearance block only', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('giant_schnauzer')
            ->and(PetDnaService::hasAppearance('giant_schnauzer'))->toBeTrue()
            ->and(config('breed_appearance.giant_schnauzer.verified'))->toBeFalse()
            ->and(config('breed_appearance.giant_schnauzer.prompt_order'))->toContain('head')->toContain('ear_carriage')->toContain('tail');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample('giant_schnauzer', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('wiry')
                ->and($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('uncropped');

            $prompt = $prompts->imagePrompt('giant_schnauzer', $traits);
            expect($prompt)->toContain('Giant Schnauzer')
                ->not->toMatch('/\b(merle|brindle|white|brown|tan|Miniature|Standard Schnauzer)\b/i')
                ->not->toMatch('/\b(docked|cropped)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['solid black', 'pepper and salt (banded grey) with a dark facial mask', 'black and silver'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.giant_schnauzer'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.giant_schnauzer'), 'key'))->toBe(['black', 'pepper-salt', 'black-silver']);
    });

    it('draws the register portrait black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::GiantSchnauzer);

        expect($traits['coat_color'])->toBe('solid black')
            ->and($portrait->prompt(BreedType::GiantSchnauzer))->toContain('Giant Schnauzer')->toContain('solid black')
            ->and(BreedPortraitService::relativeFile(BreedType::GiantSchnauzer))->toBe('dog/giant-schnauzer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::GiantSchnauzer);
        expect($v1['prompt_anchor'])->toContain('Giant Schnauzer')->toContain('solid black')
            ->not->toMatch('/\b(merle|brindle|white|brown)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid black', 'pepper and salt with a dark facial mask', 'black and silver'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = gizPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Giant Schnauzer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.giant_schnauzer'),
            config('breed_suitability.breeds.giant_schnauzer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'giant-schnauzer')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::GiantSchnauzer);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S495) → no welfare chip; RKC "Over 12 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(gizData('giant_schnauzer.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(gizData('proposed_game_parameters.giant_schnauzer.marketing.source_id'))->toBe('S495')
            ->and(gizData('proposed_game_parameters.giant_schnauzer.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and McMillan 2024 via Wikipedia (109) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'giant-schnauzer')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(109);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active, family, large-home and low-shedding chips plus exercise, experienced owner, small children, company and grooming; never children, small_children, apartment, other_pets or chases_small_pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::GiantSchnauzer);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'large_home', 'low_shedding'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'frequent_grooming'])
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('chases_small_pets')->not->toContain('older_children')
            ->not->toContain('shorter_lifespan');

        expect(gizData('giant_schnauzer.suitability.rkc_exercise.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(gizData('giant_schnauzer.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(gizData('giant_schnauzer.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(gizData('giant_schnauzer.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(gizData('giant_schnauzer.behaviour.experienced_owner.quote'))->toContain('experienced dog owners')
            ->and(gizData('giant_schnauzer.suitability.petmd_children.quote'))->toContain('small children')
            ->and(gizData('giant_schnauzer.behaviour.time_alone.quote'))->toContain("aren't home much")
            // No high-prey-drive statement → no chases_small_pets.
            ->and(gizData('giant_schnauzer.behaviour.other_pets.quote'))->not->toContain('prey drive');
    });
});
