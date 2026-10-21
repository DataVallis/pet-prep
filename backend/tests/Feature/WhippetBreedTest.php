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
| M5-R10-54 — Whippet (appended after the Australian Cattle Dog: AKC 2024 No. 50 "Whippet")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `whippet` (S442–S447) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.whippet` (exercise 60 min — PDSA "Minimum exercise
| (per day): 1 hour"; the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36 / 121 — 0.75 × McMillan 2024 median 13.4 y via Dogs Trust S123 —, arrival 2 / 9 / 36 / 121, learning
| × 1.0 (Coren rank 51, Average), paid breed, Border Collie care rates, brushing once a week (PDSA "a weekly
| brush", M5-R13), the digestion warning after a meal (PDSA "Remember to leave a gap between eating and
| exercising.", M5-R16), puppy socialisation with the PDSA quote (M5-R17); suitability: suits family_pet /
| children, consider sensitive_older_children (new chip) / needs_company / chases_small_pets; fawn portrait (any colour except
| merle); no welfare concern found, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function wptData(string $path): mixed
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

/** @return array<string, mixed> the Whippet seeder row of (stage, from, key) */
function wptRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'whippet'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("whippet.{$stage}.{$from}.{$key->value}");

    return $row;
}

function wptPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->whippet()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function wptPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function wptLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Whippet;
        $config = BreedConfig::where('breed_slug', 'whippet')->sole();

        expect($breed->value)->toBe('whippet')
            ->and($breed->slug())->toBe('whippet')
            ->and(BreedType::fromSlug('whippet'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 500, 'breeds.whippet'])
            ->and($config->search_keywords)->toBe(['whippet', 'mali angleški hrt', 'mali angleski hrt', 'angleški hrt', 'angleski hrt', 'vipet', 'hrt', 'snap dog'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) wptData('proposed_game_parameters.whippet.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = wptData('proposed_game_parameters.whippet.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Whippet pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = wptPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('whippet');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names and colours are no breed keys (only search keywords / a colour).
            ->and($refused(['breed_type' => 'mali_angleski_hrt']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'greyhound']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_10_120000_add_whippet_breed.php');
        // down() refuses while a Whippet exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'whippet']))->toThrow(QueryException::class);
        // The Australian Cattle Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_cattle_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'whippet']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Whippet);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'whippet')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'whippet')->count());

        BreedConfig::where('breed_slug', 'whippet')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'whippet')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'whippet')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'whippet');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'whippet');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'whippet'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WHIPPET)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Whippet's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_WHIPPET');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.whippet';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(wptRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(wptData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(wptRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wptData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(wptRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wptData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.4 y (McMillan 2024 median via Dogs Trust S123) = 120.6 → 121 — sourced, not provisional.
            ->and(wptRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(wptData('whippet.lifespan.senior_from.months'))
            ->and(wptRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * wptRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(wptData('whippet.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(wptRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(wptRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(wptRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(121);

        expect(wptRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wptData("{$pgp}.exercise_minutes_adult.value"))
            ->and(wptRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(wptRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wptData('whippet.exercise.adult.quote'))
            ->and(wptRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S444')
            ->and(wptRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(wptRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wptData("{$pgp}.exercise_minutes_senior.value"))
            ->and(wptRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(wptRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(wptData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(wptRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(wptData("{$pgp}.learning_multiplier.value"))
            ->and(wptRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Whippet decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WHIPPET) {
                $decided++;
                expect($row['breed_slug'])->toBe('whippet')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.whippet.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) wptData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); a winter coat / soft bedding and a secure
        // off-lead run have no mechanic → open for David (a new ROADMAP task).
        expect(json_encode([wptData('whippet'), wptData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(wptData("{$pgp}.grooming.value"))->toBe(1)
            ->and(wptData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(wptData("{$pgp}.cold_weather_care.decision"))->toContain('nova naloga v ROADMAP')
            ->and(wptData("{$pgp}.off_lead_run.decision"))->toContain('nova naloga v ROADMAP')
            ->and(wptData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(wptData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['whippet'][1])
            ->and(wptData('whippet.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['whippet'][1])
            ->and(wptData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['whippet'][1])
            ->and(wptData('whippet.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['whippet'][1]);
    });

    it('takes the breed-level numbers from the sourced whippet entries', function () {
        // PDSA "Average weight: 11-18 kg" (S444); the KC standard gives no weight, PetMD 25–40 lb agrees.
        expect(wptRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([11.0, 18.0])
            ->and(wptData('whippet.adult_weight.pdsa.value'))->toEqual(['male' => [11, 18], 'female' => [11, 18]])
            ->and(wptRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(wptData('whippet.adult_weight.pdsa.source_id'))
            ->and(wptRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(wptData('whippet.adult_weight.pdsa.quote'))
            ->and(wptRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(wptData('whippet.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 51 (S35), Average tier → learning 1.0.
            ->and(wptRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(51)
            ->and(wptRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(wptData('whippet.trainability.coren_rank.quote'))
            ->and(wptData('whippet.trainability.coren_rank.value'))->toBe(51)
            ->and(wptRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('whippet.lifespan.median_uk')
            ->and(wptRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(wptRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.4)
            ->and(wptRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(wptData('whippet.lifespan.median_uk.quote'))
            ->and(wptData('whippet.lifespan.pdsa.quote'))->toBe('Average lifespan: Over 12 years');

        // Brushing once a week (PDSA "a weekly brush" → 1) — the rule, verified.
        $grooming = wptRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S444')
            ->and($grooming['quote'])->toBe(wptData('whippet.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(wptData('proposed_game_parameters.whippet.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice.
            ->and(wptRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(wptRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S444')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(wptRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(wptRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S444');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'whippet');
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
            // M5-R16 / M5-R17: the digestion warning (PDSA S444) and the puppy's weekly "new experience"
            // with the breed's own PDSA quote (S444).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('whippet'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = wptPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 120 months' => [120, LifeStage::Adult, 60, 6000],
        'senior 121 months' => [121, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 121 months, five months before the Australian Cattle Dog (126)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-cattle-dog', 125))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('australian-cattle-dog', 126))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = wptPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'whippet', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = wptPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(wptLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Whippet)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 121 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'whippet'] as $breed) {
            $pin = wptPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(wptLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['whippet'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(121)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Whippet appearance data.
            ->and($dog->pet_dna['breed'])->toBe('whippet')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = wptPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Whippet after the Australian Cattle Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'whippet', 'australian_cattle_dog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'whippet', 'slug' => 'whippet', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.whippet',
            'search_keywords' => seededBreedConfig('whippet')['search_keywords'], 'sort_order' => 500,
            'suitability' => [
                'suits' => ['family_pet', 'children'],
                'consider' => ['sensitive_older_children', 'needs_company', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — any colour or mixture except merle)', function () {
    it('samples Whippet DNA in the six standard colours of the appearance block only, never merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('whippet')
            ->and(PetDnaService::hasAppearance('whippet'))->toBeTrue()
            ->and(config('breed_appearance.whippet.verified'))->toBeFalse()
            ->and(config('breed_appearance.whippet.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('whippet', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('rose-shaped')
                ->and($traits['build'])->toContain('lean');

            $prompt = $prompts->imagePrompt('whippet', $traits);
            expect($prompt)->toContain('Whippet')
                ->not->toMatch('/\b(merle|emaciated|skinny|Greyhound|Italian|docked|cropped|feathered)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['fawn', 'brindle', 'black', 'blue (grey)', 'fawn and white parti-colour', 'white with brindle patches'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.whippet'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.whippet'), 'key'))->toBe(['fawn', 'brindle', 'black', 'blue', 'fawn-white', 'brindle-white']);
    });

    it('draws the register portrait fawn', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Whippet);

        expect($traits['coat_color'])->toBe('fawn')
            ->and($portrait->prompt(BreedType::Whippet))->toContain('Whippet')->toContain('fawn')
            ->and(BreedPortraitService::relativeFile(BreedType::Whippet))->toBe('dog/whippet.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Whippet);
        expect($v1['prompt_anchor'])->toContain('Whippet')->toContain('fawn')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn', 'brindle', 'fawn and white parti-colour'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = wptPet(121, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Whippet')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.whippet'),
            config('breed_suitability.breeds.whippet'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'whippet')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Whippet);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No welfare concern found (RKC page unreachable, PDSA names no Kennel Club category) → no welfare chip;
            // PDSA "Over 12 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(wptData('whippet.health'))->not->toHaveKey('breed_watch')
            ->and(wptData('proposed_game_parameters.whippet.marketing.source_id'))->toBe('S444')
            ->and(wptData('proposed_game_parameters.whippet.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and the PDSA lower bound (108) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'whippet')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Whippet family_pet / children and the new sensitive-older-children, company and prey-drive chips; never small_children, other_pets, apartment or large_home', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Whippet);
        expect($tags['suits'])->toBe(['family_pet', 'children'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['sensitive_older_children', 'needs_company', 'chases_small_pets'])
            // The new chip (runbook "new tag" rule): `older_children` names a small dog, the Whippet is medium-sized.
            ->and(config('breed_suitability.vocabulary.sensitive_older_children'))->toBe('consider')
            ->and($tags['consider'])->not->toContain('older_children')->not->toContain('fragile_older_children')->not->toContain('not_for_small_children')->not->toContain('sheds')->not->toContain('frequent_grooming')
            ->not->toContain('long_daily_exercise')->not->toContain('experienced_owner');

        expect(wptData('whippet.suitability.pdsa_family.quote'))->toBe('Whippets are a popular choice for families as they are so calm and gentle around children.')
            ->and(wptData('whippet.suitability.pdsa_children.quote'))->toBe("we'd recommend a home with slightly older children")
            ->and(wptData('whippet.behaviour.other_pets.quote'))->toContain('really high prey drive')
            ->and(wptData('whippet.behaviour.time_alone.quote'))->toBe('Like most dogs, Whippets dislike being left alone and can suffer with separation anxiety.');
    });
});
