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
| M5-R10-111 — Belgian Tervuren (appended after the Schipperke: AKC 2024 No. 107 "Belgian Tervuren")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `belgian_tervuren` (S830–S832, reused S313, S35) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.belgian_tervuren` (exercise 120 min — RKC
| "More than 2 hours per day" —, senior 90 min, puppy / young 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC
| lower bound 10 y, S830 — provisional like the Belgian Malinois), arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren rank 14,
| Excellent), paid breed, Border Collie care rates, brushing 1× a week (RKC "More than once a week" → PetMD "every week",
| M5-R13), ear cleaning once a week and a bath reminder every 4 weeks (M5-R18) from PetMD S832, puppy socialisation with
| the PetMD quote (S832, M5-R17), no meal gap, no heat warning. Not a welfare breed (RKC Breed Watch Category 1) → in
| marketing; fawn portrait with a black mask.
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

/** @return array<string, mixed> the Belgian Tervuren seeder row of (stage, from, key) */
function btRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'belgian-tervuren'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("belgian-tervuren.{$stage}.{$from}.{$key->value}");

    return $row;
}

function btPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->belgianTervuren()->create(array_merge([
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
        $breed = BreedType::BelgianTervuren;
        $config = BreedConfig::where('breed_slug', 'belgian-tervuren')->sole();

        expect($breed->value)->toBe('belgian_tervuren')
            ->and($breed->slug())->toBe('belgian-tervuren')
            ->and(BreedType::fromSlug('belgian-tervuren'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1040, 'breeds.belgian_tervuren'])
            ->and($config->search_keywords)->toBe(['belgian tervuren', 'belgijski ovčar tervuren', 'belgijski ovcar tervuren', 'tervuren', 'tervueren', 'terv', 'belgian shepherd tervueren', 'belgian shepherd dog tervueren'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) btData('proposed_game_parameters.belgian_tervuren.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = btData('proposed_game_parameters.belgian_tervuren.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Belgian Tervuren pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = btPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('belgian_tervuren');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'tervueren']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'belgian_shepherd']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_01_120000_add_belgian_tervuren_breed.php');
        // down() refuses while a Belgian Tervuren exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'belgian_tervuren']))->toThrow(QueryException::class);
        // The Schipperke (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'schipperke']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_tervuren']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BelgianTervuren);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'belgian-tervuren')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-tervuren')->count());

        BreedConfig::where('breed_slug', 'belgian-tervuren')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'belgian-tervuren')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'belgian-tervuren')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'belgian-tervuren');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-tervuren');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'belgian-tervuren'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_TERVUREN)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BELGIAN_TERVUREN');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.belgian_tervuren';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(btRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(btRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(btRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = round(0.75 × 10 y × 12) = 90 (RKC lower bound, S830) → runbook rule, verified (provisional, open for David).
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData('belgian_tervuren.lifespan.senior_from.months'))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * btRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(btData('belgian_tervuren.lifespan.senior_from.derived_from'))->toBe(['S11', 'S830'])
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', btData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_TERVUREN)
            ->and(btRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90)
            // McMillan via Wikipedia (124), PetMD (108) and Dogs Trust's 7 y stay alternatives.
            ->and(btData("{$pgp}.stage_boundaries_months.alternatives"))->toBe(['senior_mcmillan_via_wikipedia' => 124, 'senior_petmd_12_y' => 108, 'senior_dogs_trust_7_y' => 84]);

        expect(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_adult.value"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData('belgian_tervuren.exercise.adult.quote'))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S830')
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_senior.value"))
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(btRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(btData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(btData("{$pgp}.learning_multiplier.value"))
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_TERVUREN) {
                $decided++;
                expect($row['breed_slug'])->toBe('belgian-tervuren')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.belgian_tervuren.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) btData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 1× a week (M5-R13), ear cleaning once a week and a bath reminder every
        // 4 weeks (M5-R18) from PetMD S832, puppy socialisation with the PetMD quote (M5-R17). No meal gap and no heat
        // warning (no quote), no tooth / nail / groomer task (no frequency).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['belgian_tervuren'];
        expect(json_encode([btData('belgian_tervuren'), btData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(btData("{$pgp}.grooming.value"))->toBe(1)
            ->and(btData("{$pgp}.grooming.decision"))->toContain('M5-R13')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('belgian_tervuren')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('belgian_tervuren')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['belgian_tervuren'])->toBe(['S832', btData("{$pgp}.socialisation.quote")])
            ->and(btData("{$pgp}.socialisation.quote"))->toBe(btData('belgian_tervuren.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['ear_cleaning', 'bath'])
            ->and([$tasks['ear_cleaning'][0], $tasks['ear_cleaning'][1]])->toBe([1, 'week'])
            ->and([$tasks['bath'][0], $tasks['bath'][1]])->toBe([1, 'month'])
            ->and($tasks['ear_cleaning'][3])->toBe(btData("{$pgp}.ear_care.quote"))
            ->and($tasks['bath'][3])->toBe(btData("{$pgp}.bathing.quote"))
            ->and(btData("{$pgp}.ear_care.value"))->toBe([1, 7])
            ->and(btData("{$pgp}.bathing.value"))->toBe([1, 28])
            ->and(btData("{$pgp}.ear_care.quote"))->toBe(btData('belgian_tervuren.suitability.petmd_ears.quote'))
            ->and(btData("{$pgp}.bathing.quote"))->toBe(btData('belgian_tervuren.suitability.petmd_bathing.quote'));
    });

    it('takes the breed-level numbers from the sourced belgian_tervuren entries', function () {
        // FCI standard No. 15 (S313): males about 25–30 kg, females about 20–25 kg → 20–30 kg.
        expect(btRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.0, 30.0])
            ->and(btData('belgian_tervuren.adult_weight.fci.value'))->toEqual(['male' => [25, 30], 'female' => [20, 25]])
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(btData('belgian_tervuren.adult_weight.fci.source_id'))
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(btData('belgian_tervuren.adult_weight.fci.quote'))
            ->and(btRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(btData('belgian_tervuren.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(btRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(14)
            ->and(btData('belgian_tervuren.trainability.coren_rank.value'))->toBe(14)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('belgian_tervuren.lifespan.rkc')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S830')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(btData('belgian_tervuren.lifespan.rkc.quote'));

        // Brushing 1× a week: RKC "More than once a week" has no number → PetMD "every week" → 1.
        $grooming = btRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S832')
            ->and($grooming['quote'])->toBe(btData('belgian_tervuren.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(btData('proposed_game_parameters.belgian_tervuren.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S832')
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-tervuren');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->count())->toBe(0)
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(0)
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S832 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        // Care-task frequency rows are breed-specific and skipped (ears, bath).
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true) || str_ends_with($r['key'], '_frequency'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('belgian-tervuren'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = btPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('belgian-tervuren', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('belgian-tervuren', 90))->toBe(LifeStage::Senior);
    });

    it('learns 1.5× as fast (Coren rank 14, Excellent) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = btPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'belgian_tervuren', 'origin' => 'bought', 'age_stage' => 'puppy'];
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
            ->and($pet->breed_type)->toBe(BreedType::BelgianTervuren)
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
        foreach (['border_collie', 'belgian_tervuren'] as $breed) {
            $pin = btPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(btLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['belgian_tervuren'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('belgian_tervuren')
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
    it('lists the Belgian Tervuren after the Schipperke with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'belgian_tervuren', 'schipperke');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'belgian_tervuren', 'slug' => 'belgian-tervuren', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.belgian_tervuren',
            'search_keywords' => seededBreedConfig('belgian_tervuren')['search_keywords'], 'sort_order' => 1040,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'may_herd_children', 'needs_mental_stimulation', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI standard No. 15, S831)', function () {
    it('samples DNA in fawn or grey with black overlay and mask only, never black, brindle or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('belgian_tervuren')
            ->and(PetDnaService::hasAppearance('belgian_tervuren'))->toBeTrue()
            ->and(config('breed_appearance.belgian_tervuren.verified'))->toBeFalse()
            ->and(config('breed_appearance.belgian_tervuren.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.belgian_tervuren.traits.coat_color'));
        $seen = [];
        foreach (range(1, 100) as $seed) {
            $traits = $dna->sample('belgian_tervuren', $seed)['traits'];
            $seen[$traits['coat_color']] = true;
            expect($standard)->toContain($traits['coat_color'])
                ->and($traits['coat_color'])->toContain('black overlay')->toContain('black mask')
                ->and($traits['ear_carriage'])->toContain('erect');

            $prompt = $prompts->imagePrompt('belgian_tervuren', $traits);
            expect($prompt)->toContain('Belgian Tervuren')->toContain('erect')
                ->not->toMatch('/\b(merle|tricolou?r|brindle|white patches|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($seen);
        $colours = config('breed_colours.belgian_tervuren');
        expect(array_keys($seen))->toBe($standard)
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['fawn', 'grey']);
    });

    it('draws the register portrait fawn with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BelgianTervuren);

        expect($traits['coat_color'])->toStartWith('fawn')
            ->and($portrait->prompt(BreedType::BelgianTervuren))->toContain('Belgian Tervuren')->toContain('fawn')->toContain('black mask')->toContain('erect')
            ->and(BreedPortraitService::relativeFile(BreedType::BelgianTervuren))->toBe('dog/belgian-tervuren.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BelgianTervuren);
        expect($v1['prompt_anchor'])->toContain('Belgian Tervuren')->toContain('fawn coat with black overlay')->toContain('black mask')->toContain('erect')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with black overlay', 'rich fawn with black overlay', 'grey with black overlay']);

        $pet = btPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Belgian Tervuren')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and keeps the breed in marketing', function () {
        $app = json_encode([
            config('breed_appearance.belgian_tervuren'),
            config('breed_suitability.breeds.belgian_tervuren'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-tervuren')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BelgianTervuren);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not flat-faced; RKC Breed Watch Category 1 → no welfare chip.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(btData('belgian_tervuren.health.breed_watch.value'))->toStartWith('RKC Breed Watch Category 1')
            ->and(btData('proposed_game_parameters.belgian_tervuren.marketing.value'))->toBe('in the app: yes; in marketing: yes')
            // The alternatives (124, 108, 84) are never seeded as the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-tervuren')->where('key', StageParamKey::StartsAtMonths->value)->where('stage', 'senior')->pluck('value')->all())
            ->toBe([90]);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family and a large home; consider long exercise, herding children, mental stimulation, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BelgianTervuren);
        expect($tags['suits'])->toBe(['active_family', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'may_herd_children', 'needs_mental_stimulation', 'sheds', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('apartment')->not->toContain('family_pet')->not->toContain('children')
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('needs_company');

        expect(btData('belgian_tervuren.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(btData('belgian_tervuren.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(btData('belgian_tervuren.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week')
            ->and(btData('belgian_tervuren.suitability.rkc_lifespan.quote'))->toBe('Lifespan: Over 10 years')
            // Herding, not a "high prey drive" → no chases_small_pets (runbook).
            ->and(btData('belgian_tervuren.behaviour.other_pets.quote'))->not->toContain('prey drive');
    });
});
