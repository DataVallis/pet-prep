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
| M5-R10-102 — Border Terrier (appended after the Border Terrier: AKC 2024 No. 98 "Border Terrier")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `border_terrier` (S767–S779) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.border_terrier` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy / young 10 min × age capped at 60, stages 9 / 36 / 128 (0.75 × McMillan 2024
| median 14.2 y, S771 — not provisional), arrival 2 / 9 / 36 / 128, learning × 1.2 (Coren rank 30, Above average),
| paid breed, Border Collie care rates, brushing 2× a week (PDSA "a couple of times a week", M5-R13), meal / walk gap
| (PDSA S770, M5-R16), puppy socialisation with the PDSA quote (S770, M5-R17). No care task (no program-period
| frequency in any source). Not a welfare breed (RKC Breed Watch Category 1) → in marketing; grizzle and tan portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function btData(string $path): mixed
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

/** @return array<string, mixed> the Border Terrier seeder row of (stage, from, key) */
function btRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'border-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("border-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function btPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->borderTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function btPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function btLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BorderTerrier;
        $config = BreedConfig::where('breed_slug', 'border-terrier')->sole();

        expect($breed->value)->toBe('border_terrier')
            ->and($breed->slug())->toBe('border-terrier')
            ->and(BreedType::fromSlug('border-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 950, 'breeds.border_terrier'])
            ->and($config->search_keywords)->toBe(['border terrier', 'borderski terier', 'border terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) btData('proposed_game_parameters.border_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = btData('proposed_game_parameters.border_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Border Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = btPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('border_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'border']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'borderski_terier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_22_120000_add_border_terrier_breed.php');
        // down() refuses while a Border Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'border_terrier']))->toThrow(QueryException::class);
        // The Flat-Coated Retriever (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'flat_coated_retriever']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BorderTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'border-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'border-terrier')->count());

        BreedConfig::where('breed_slug', 'border-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'border-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'border-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'border-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'border-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'border-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BORDER_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BORDER_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.border_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(btRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(btRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(btRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 14.2 y × 12) = round(127.8) = 128 (McMillan 2024, S771) → not provisional.
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData('border_terrier.lifespan.senior_from.months'))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * btRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(btData('border_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S771'])
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', btData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BORDER_TERRIER)
            ->and(btRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(128)
            // The Dogs Trust rule of thumb (84) stays an alternative.
            ->and(btData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_dogs_trust_7_y' => 84]);

        expect(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_adult.value"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData('border_terrier.exercise.adult.quote'))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S769')
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_senior.value"))
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(btRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(btData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(btData("{$pgp}.learning_multiplier.value"))
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BORDER_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('border-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.border_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) btData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 2× a week (M5-R13, PDSA), the meal / walk gap (M5-R16, PDSA), puppy
        // socialisation with the PDSA quote (M5-R17). No care task: no source gives a program-period frequency.
        expect(json_encode([btData('border_terrier'), btData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(btData("{$pgp}.grooming.value"))->toBe(2)
            ->and(btData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['border_terrier'])->toBe(['S770', btData("{$pgp}.meal_exercise_gap.quote")])
            ->and(btData("{$pgp}.meal_exercise_gap.quote"))->toBe(btData('border_terrier.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['border_terrier'])->toBe(['S770', btData("{$pgp}.socialisation.quote")])
            ->and(btData("{$pgp}.socialisation.quote"))->toBe(btData('border_terrier.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('border_terrier')
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('border_terrier');
    });

    it('takes the breed-level numbers from the sourced border_terrier entries', function () {
        // FCI weight: males 5.9–7.1 kg, females 5.1–6.4 kg → 5.1–7.1 kg.
        expect(btRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.1, 7.1])
            ->and(btData('border_terrier.adult_weight.fci.value'))->toEqual(['male' => [5.9, 7.1], 'female' => [5.1, 6.4]])
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(btData('border_terrier.adult_weight.fci.source_id'))
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(btData('border_terrier.adult_weight.fci.quote'))
            ->and(btRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(btData('border_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(btRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(30)
            ->and(btData('border_terrier.trainability.coren_rank.value'))->toBe(30)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('border_terrier.lifespan.median_uk')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S771')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.2)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(btData('border_terrier.lifespan.median_uk.quote'));

        // Brushing 2× a week: RKC "More than once a week" has no number → PDSA "a couple of times a week" → 2.
        $grooming = btRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S770')
            ->and($grooming['quote'])->toBe(btData('border_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(btData('proposed_game_parameters.border_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S770')
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'border-terrier');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap->count())->toBe(1)
            ->and($gap[0]['value'])->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP_MINUTES)
            ->and($gap[0]['source_id'])->toBe('S770')
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: socialisation (PDSA S770 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the meal / walk gap (PDSA S770).
            StageParamKey::MealExerciseGapMinutes,
        ];
        // No care-task frequency rows for this breed (none would be skipped).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('border-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = btPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 127 months' => [127, LifeStage::Adult, 60, 6000],
        'senior 128 months' => [128, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 128 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('border-terrier', 127))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('border-terrier', 128))->toBe(LifeStage::Senior);
    });

    it('learns at 1.2 × the mixed-breed speed (Coren Above average tier, rank 30) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = btPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'border_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = btPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(btLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BorderTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 128 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'border_terrier'] as $breed) {
            $pin = btPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(btLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['border_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(128)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('border_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = btPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Border Terrier after the Flat-Coated Retriever with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'border_terrier', 'flat_coated_retriever');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'border_terrier', 'slug' => 'border-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.border_terrier',
            'search_keywords' => seededBreedConfig('border_terrier')['search_keywords'], 'sort_order' => 950,
            'suitability' => [
                'suits' => ['children', 'apartment'],
                'consider' => ['lively_older_children', 'needs_company', 'secure_fencing', 'sheds', 'frequent_grooming', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 10 S768)', function () {
    it('samples DNA in the four standard colours only, never black or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('border_terrier')
            ->and(PetDnaService::hasAppearance('border_terrier'))->toBeTrue()
            ->and(config('breed_appearance.border_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.border_terrier.prompt_order'))->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.border_terrier.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('border_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('border_terrier', $traits);
            expect($prompt)->toContain('Border Terrier')
                ->not->toMatch('/\b(merle|brindle|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.border_terrier');
        expect($standard)->toBe(['grizzle and tan', 'red', 'wheaten', 'blue and tan'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['grizzle-tan', 'red', 'wheaten', 'blue-tan'])
            // Grizzle and tan carries the highest weight → the portrait default.
            ->and(config('breed_appearance.border_terrier.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.border_terrier.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in grizzle and tan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BorderTerrier);

        expect($traits['coat_color'])->toBe('grizzle and tan')
            ->and($portrait->prompt(BreedType::BorderTerrier))->toContain('Border Terrier')->toContain('grizzle and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::BorderTerrier))->toBe('dog/border-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BorderTerrier);
        expect($v1['prompt_anchor'])->toContain('Border Terrier')->toContain('grizzle and tan')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('grizzle and tan');

        $pet = btPet(128, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Border Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and stays in marketing (not a welfare breed)', function () {
        $app = json_encode([
            config('breed_appearance.border_terrier'),
            config('breed_suitability.breeds.border_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'border-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '50%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BorderTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S769) → no welfare chip, in marketing.
            ->and($tags['consider'])->not->toContain('hips_hind_legs')->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(btData('border_terrier.health.breed_watch.quote'))->toBe('Category 1')
            ->and(btData('proposed_game_parameters.border_terrier.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'border-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children and a flat; consider older children, company, a secure garden, shedding, grooming and small pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BorderTerrier);
        expect($tags['suits'])->toBe(['children', 'apartment'])
            ->and($tags['consider'])->toBe(['lively_older_children', 'needs_company', 'secure_fencing', 'sheds', 'frequent_grooming', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('often_alone')->not->toContain('family_pet')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('shorter_lifespan')->not->toContain('experienced_owner');

        expect(btData('border_terrier.suitability.rkc_size_of_home.quote'))->toBe('Flat/ Apartment')
            ->and(btData('border_terrier.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(btData('border_terrier.lifespan.rkc.quote'))->toBe('Over 12 years')
            ->and(btData('border_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(btData('border_terrier.behaviour.children.quote'))->toContain('children of all ages');
    });
});
