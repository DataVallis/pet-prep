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
| M5-R10-62 — Bloodhound (appended after the Giant Schnauzer: AKC 2024 No. 58)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bloodhound` (S500–S505) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bloodhound` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36, arrival 2 / 9 / 36, learning × 0.5
| (Coren rank 74, Lowest), paid breed, Border Collie care rates, brushing once a week (RKC, M5-R13), puppy
| socialisation with the PDSA quote (M5-R17), the digestion warning with the PDSA gap quote (M5-R16)). The senior
| boundary / senior arrival 61 (0.75 × Kennel Club survey median 6 y 9 m — RKC "Under 10 years" has no lower bound) is
| Claude's suggestion, verified=false, open for David. Suitability: suits active_family / large_home; consider
| long_daily_exercise, experienced_owner, not_for_small_children, sheds, drools, shorter_lifespan, eyelid_problems
| (welfare rule, RKC Breed Watch Category 2), chases_small_pets (PDSA "very high prey drive"); black and tan portrait;
| in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bhData(string $path): mixed
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

/** @return array<string, mixed> the Bloodhound seeder row of (stage, from, key) */
function bhRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bloodhound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bloodhound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bhPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bloodhound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bhPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bhLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Bloodhound;
        $config = BreedConfig::where('breed_slug', 'bloodhound')->sole();

        expect($breed->value)->toBe('bloodhound')
            ->and($breed->slug())->toBe('bloodhound')
            ->and(BreedType::fromSlug('bloodhound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 580, 'breeds.bloodhound'])
            ->and($config->search_keywords)->toBe(['bloodhound', 'pes svetega huberta', 'pes sv. huberta', 'chien de saint-hubert', 'st. hubert hound', 'sleuth hound'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bhData('proposed_game_parameters.bloodhound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bhData('proposed_game_parameters.bloodhound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bloodhound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bhPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bloodhound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'st_hubert_hound']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'blood_hound']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_23_120000_add_bloodhound_breed.php');
        // down() refuses while a Bloodhound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bloodhound']))->toThrow(QueryException::class);
        // The Giant Schnauzer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'giant_schnauzer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bloodhound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Bloodhound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bloodhound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bloodhound')->count());

        BreedConfig::where('breed_slug', 'bloodhound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bloodhound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bloodhound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bloodhound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bloodhound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bloodhound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BLOODHOUND)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BLOODHOUND_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_BLOODHOUND', 'PROPOSED_R10_BLOODHOUND_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bloodhound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bhRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bhData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bhRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bhData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bhRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bhData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 6.75 y (Kennel Club survey median, S505) = 61 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass value reachable, RKC "Under 10 years" has no lower bound)
            // → verified=false, open for David.
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bhData('bloodhound.lifespan.senior_from.months'))
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bhData("{$pgp}.senior_boundary_months.value"))
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bhRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bhData('bloodhound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S505'])
            ->and(bhData('bloodhound.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S505')
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BLOODHOUND_SENIOR)
            ->and(bhData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BLOODHOUND_SENIOR)
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(61);

        expect(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bhData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bhData('bloodhound.exercise.adult.quote'))
            ->and(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S502')
            ->and(bhRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(bhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bhData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bhRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bhData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(bhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bhData("{$pgp}.learning_multiplier.value"))
            ->and(bhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BLOODHOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('bloodhound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bloodhound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bhData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17, digestion warning M5-R16); skin-fold care and ear checks have no
        // mechanic → open for David (M5-R14, M5-R20).
        expect(json_encode([bhData('bloodhound'), bhData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bhData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bhData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(bhData("{$pgp}.skin_fold_care.decision"))->toContain('M5-R14')
            ->and(bhData("{$pgp}.ear_checks.decision"))->toContain('M5-R20')
            ->and(bhData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(bhData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['bloodhound'][1])
            ->and(bhData('bloodhound.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['bloodhound'][1])
            ->and(bhData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['bloodhound'][1])
            ->and(bhData('bloodhound.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['bloodhound'][1]);
    });

    it('takes the breed-level numbers from the sourced bloodhound entries', function () {
        // FCI standard No. 84 (S501): males about 46–54 kg, females about 40–48 kg.
        expect(bhRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([40, 54])
            ->and(bhData('bloodhound.adult_weight.fci.value'))->toEqual(['male' => [46, 54], 'female' => [40, 48]])
            ->and(bhRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bhData('bloodhound.adult_weight.fci.source_id'))
            ->and(bhRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bhData('bloodhound.adult_weight.fci.quote'))
            ->and(bhRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(bhData('bloodhound.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 74 (S35), Lowest tier → learning 0.5.
            ->and(bhRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(74)
            ->and(bhRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(bhData('bloodhound.trainability.coren_rank.quote'))
            ->and(bhData('bloodhound.trainability.coren_rank.value'))->toBe(74)
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bloodhound.lifespan.median_kc_survey')
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S505')
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(6.75)
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bhData('bloodhound.lifespan.median_kc_survey.quote'));

        // Brushing once a week (RKC "Grooming: Once a week" → 1) — the rule, verified.
        $grooming = bhRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S502')
            ->and($grooming['quote'])->toBe(bhData('bloodhound.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bhData('proposed_game_parameters.bloodhound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(bhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S503')
            // The digestion warning (M5-R16): 60 minutes after a meal, PDSA gap quote.
            ->and(bhRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(bhRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S503');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bloodhound');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (61 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BLOODHOUND_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.bloodhound.senior_boundary_months')
                ->and($row['value'])->toBe(bhData('proposed_game_parameters.bloodhound.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" (PDSA S503).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bloodhound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bhPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 61-month boundary (and arrival age).
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
        'adult 60 months' => [60, LifeStage::Adult, 120, 12000],
        'senior 61 months' => [61, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 61 months, 15 months before the Bernese Mountain Dog (76)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bloodhound', 60))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bloodhound', 61))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bernese-mountain-dog', 75))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bernese-mountain-dog', 76))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bhPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bloodhound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bhPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bhLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Bloodhound)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 61 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bloodhound'] as $breed) {
            $pin = bhPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bhLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bloodhound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(61)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('bloodhound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bhPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bloodhound after the Giant Schnauzer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bloodhound', 'giant_schnauzer');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bloodhound', 'slug' => 'bloodhound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bloodhound',
            'search_keywords' => seededBreedConfig('bloodhound')['search_keywords'], 'sort_order' => 580,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'sheds', 'drools', 'shorter_lifespan', 'eyelid_problems', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — black and tan, liver and tan, red; moderate skin)', function () {
    it('samples DNA in the three standard colours of the appearance block only, never white patches or exaggerated skin', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bloodhound')
            ->and(PetDnaService::hasAppearance('bloodhound'))->toBeTrue()
            ->and(config('breed_appearance.bloodhound.verified'))->toBeFalse()
            ->and(config('breed_appearance.bloodhound.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('bloodhound', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('short')
                ->and($traits['eye_color'])->toContain('normal eyelids')
                ->and($traits['head'])->toContain('never exaggerated wrinkles');

            $prompt = $prompts->imagePrompt('bloodhound', $traits);
            expect($prompt)->toContain('Bloodhound')
                ->not->toMatch('/\b(white patch|white markings|merle|brindle|docked|cropped|droopy eyes|drooping eyelids|heavy wrinkles)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['black and tan', 'liver and tan', 'red'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.bloodhound'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.bloodhound'), 'key'))->toBe(['black-tan', 'liver-tan', 'red']);
    });

    it('draws the register portrait black and tan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Bloodhound);

        expect($traits['coat_color'])->toBe('black and tan')
            ->and($portrait->prompt(BreedType::Bloodhound))->toContain('Bloodhound')->toContain('black and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::Bloodhound))->toBe('dog/bloodhound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Bloodhound);
        expect($v1['prompt_anchor'])->toContain('Bloodhound')->toContain('black and tan coat')->toContain('normal eyelids')
            ->not->toMatch('/\b(white patch|docked|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black and tan', 'liver and tan', 'red'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = bhPet(61, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bloodhound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the eyelid welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bloodhound'),
            config('breed_suitability.breeds.bloodhound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bloodhound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '34%', '34.1'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Bloodhound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 2 / PDSA eye problems → eyelid_problems; not flat-faced.
            ->and($tags['consider'])->toContain('eyelid_problems')->toContain('shorter_lifespan')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine']))->toBe([])
            ->and(bhData('proposed_game_parameters.bloodhound.marketing.source_id'))->toBe('S502')
            ->and(bhData('proposed_game_parameters.bloodhound.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 via Wikipedia / Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bloodhound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives two suits chips and eight consider chips, chases_small_pets last; never family_pet, children, other_pets or apartment', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Bloodhound);
        expect($tags['suits'])->toBe(['active_family', 'large_home'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('family_pet')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'sheds', 'drools', 'shorter_lifespan', 'eyelid_problems', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('frequent_grooming')->not->toContain('needs_company')->not->toContain('sensitive_skin');

        expect(bhData('bloodhound.exercise.adult.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(bhData('bloodhound.behaviour.other_pets.quote'))->toContain('very high prey drive')
            ->and(bhData('bloodhound.behaviour.small_children.quote'))->toContain('smaller children')
            ->and(bhData('bloodhound.health.eyes.quote'))->toContain('ectropion, entropion');
    });
});
