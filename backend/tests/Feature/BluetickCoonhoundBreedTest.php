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
| M5-R10-141 — Bluetick Coonhound (appended after the Irish Terrier: AKC 2024 No. 137)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bluetick_coonhound` (S1060–S1061) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bluetick_coonhound` (exercise 40 min — Dogs NSW
| "Exercise: 40 min/day"; no RKC / PDSA page exists —, senior 30 min, puppy 10 min × age capped at 40, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing once a week (PetMD S1060,
| M5-R13), David's general puppy socialisation row (no breed quote, M5-R17); no tooth / ear / nail / bath / groomer task, no
| meal gap, no heat warning). The senior boundary / senior arrival 99 (0.75 × PetMD lower bound "11–12 years") is Claude's
| suggestion, verified=false, open for David. Suitability: no suits chip; consider experienced_owner / needs_mental_stimulation /
| secure_fencing; blue ticked with tan portrait; no welfare chip, in marketing. Not FCI-recognised.
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

/** @return array<string, mixed> the Bluetick Coonhound seeder row of (stage, from, key) */
function btRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bluetick-coonhound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bluetick-coonhound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function btPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bluetickCoonhound()->create(array_merge([
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
        $breed = BreedType::BluetickCoonhound;
        $config = BreedConfig::where('breed_slug', 'bluetick-coonhound')->sole();

        expect($breed->value)->toBe('bluetick_coonhound')
            ->and($breed->slug())->toBe('bluetick-coonhound')
            ->and(BreedType::fromSlug('bluetick-coonhound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1320, 'breeds.bluetick_coonhound'])
            ->and($config->search_keywords)->toBe(['bluetick coonhound', 'bluetick', 'blue tick coonhound', 'coonhound'])
            // Legacy fallback = the adult step goal (40 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) btData('proposed_game_parameters.bluetick_coonhound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(4000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = btData('proposed_game_parameters.bluetick_coonhound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bluetick Coonhound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = btPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bluetick_coonhound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bluetick']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'coonhound']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_12_120000_add_bluetick_coonhound_breed.php');
        // down() refuses while a Bluetick Coonhound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bluetick_coonhound']))->toThrow(QueryException::class);
        // The Irish Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'irish_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bluetick_coonhound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BluetickCoonhound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bluetick-coonhound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bluetick-coonhound')->count());

        BreedConfig::where('breed_slug', 'bluetick-coonhound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bluetick-coonhound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bluetick-coonhound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bluetick-coonhound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bluetick-coonhound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bluetick-coonhound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BLUETICK_COONHOUND)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BLUETICK_COONHOUND_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_BLUETICK_COONHOUND', 'PROPOSED_R10_BLUETICK_COONHOUND_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bluetick_coonhound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(btRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(btRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(btRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(btData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11 y (PetMD lower bound "11–12 years", S1060) = 99 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value exists) → verified=false, open for David.
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData('bluetick_coonhound.lifespan.senior_from.months'))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(btData("{$pgp}.senior_boundary_months.value"))
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * btRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(btData('bluetick_coonhound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1060'])
            ->and(btData('bluetick_coonhound.lifespan.median_uk.source_id'))->toBeNull()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S1060')
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(btRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BLUETICK_COONHOUND_SENIOR)
            ->and(btData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BLUETICK_COONHOUND_SENIOR)
            ->and(btRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(99);

        expect(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_adult.value"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(40)
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData('bluetick_coonhound.exercise.adult.quote'))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(btData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(btRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1061')
            ->and(btRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(40)
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(btData("{$pgp}.exercise_minutes_senior.value"))
            ->and(btRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(btRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(btData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(40)
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(btData("{$pgp}.learning_multiplier.value"))
            ->and(btRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BLUETICK_COONHOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('bluetick-coonhound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bluetick_coonhound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) btData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing once a week (PetMD, M5-R13). Socialisation = David's
        // general puppy row (no breed quote). No tooth / ear / nail / bath / groomer task (no frequency), no meal gap (PetMD
        // names exercise after eating only as a bloat risk factor), no heat warning (not flat-faced).
        expect(json_encode([btData('bluetick_coonhound'), btData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(BreedStageParamsSeeder::DOG_GROOMING['bluetick_coonhound'])->toBe([1, 'S1060', btData("{$pgp}.grooming.quote"), 'high', false])
            ->and(btData("{$pgp}.grooming.quote"))->toBe(btData('bluetick_coonhound.suitability.petmd_grooming.quote'))
            ->and(btData("{$pgp}.grooming.value"))->toBe(1)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('bluetick_coonhound')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('bluetick_coonhound')
            ->and(btData('bluetick_coonhound.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION)->not->toHaveKey('bluetick_coonhound')
            ->and(btData($pgp))->not->toHaveKey('socialisation')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('bluetick_coonhound');
    });

    it('takes the breed-level numbers from the sourced bluetick_coonhound entries', function () {
        // Standard (S1061): males 55–80 lb, females 45–65 lb → 20.4–36.3 kg.
        expect(btRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.4, 36.3])
            ->and(btData('bluetick_coonhound.adult_weight.standard.value'))->toEqual(['male' => [24.9, 36.3], 'female' => [20.4, 29.5]])
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(btData('bluetick_coonhound.adult_weight.standard.source_id'))
            ->and(btRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(btData('bluetick_coonhound.adult_weight.standard.quote'))
            ->and(btRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(btData('bluetick_coonhound.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(btRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(btData('bluetick_coonhound.growth.adult_weight_reached.quote'))
            // Not ranked by Coren → no rank, learning 1.0.
            ->and(btRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(btData('bluetick_coonhound.trainability.coren_rank.value'))->toBeNull()
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bluetick_coonhound.lifespan.petmd')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S1060')
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.0)
            ->and(btRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(btData('bluetick_coonhound.lifespan.petmd.quote'))
            ->and(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(1)
            ->and(btRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S1060')
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(btRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_ALL_PUPPIES);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bluetick-coonhound');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the senior boundary / senior arrival (Claude's suggestion) and the general teething chewing chance.
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing([
                'senior.'.StageParamKey::StartsAtMonths->value,
                'senior.'.StageParamKey::ArrivalAgeMonths->value,
                'puppy.'.StageParamKey::ChewingChancePerDay->value,
            ]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: weekly brushing (PetMD S1060).
            StageParamKey::GroomingSessionsPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bluetick-coonhound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 40 minutes = 4,000 steps and 30 minutes = 3,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = btPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 4 months (capped at adult)' => [4, LifeStage::Puppy, 40, 4000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 40, 4000],
        'adult 36 months' => [36, LifeStage::Adult, 40, 4000],
        'adult 98 months' => [98, LifeStage::Adult, 40, 4000],
        'senior 99 months' => [99, LifeStage::Senior, 30, 3000],
    ]);

    it('becomes senior at 99 months, earlier than the Irish Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bluetick-coonhound', 98))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bluetick-coonhound', 99))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('irish-terrier', 99))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('irish-terrier', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = btPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bluetick_coonhound', 'origin' => 'bought', 'age_stage' => 'puppy'];
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
            ->and($pet->breed_type)->toBe(BreedType::BluetickCoonhound)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 99 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bluetick_coonhound'] as $breed) {
            $pin = btPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(btLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bluetick_coonhound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(99)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('bluetick_coonhound')
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
    it('lists the Bluetick Coonhound after the Irish Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bluetick_coonhound', 'irish_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bluetick_coonhound', 'slug' => 'bluetick-coonhound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bluetick_coonhound',
            'search_keywords' => seededBreedConfig('bluetick_coonhound')['search_keywords'], 'sort_order' => 1320,
            'suitability' => [
                'suits' => [],
                'consider' => ['experienced_owner', 'needs_mental_stimulation', 'secure_fencing'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — breed standard S1061)', function () {
    it('samples DNA in the two standard colours of the appearance block only, with a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bluetick_coonhound')
            ->and(PetDnaService::hasAppearance('bluetick_coonhound'))->toBeTrue()
            ->and(config('breed_appearance.bluetick_coonhound.verified'))->toBeFalse()
            ->and(config('breed_appearance.bluetick_coonhound.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('bluetick_coonhound', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toContain('dark blue ticked')
                ->and($traits['tail'])->toContain('natural');

            $prompt = $prompts->imagePrompt('bluetick_coonhound', $traits);
            expect($prompt)->toContain('Bluetick Coonhound')
                ->not->toMatch('/\b(merle|albino|brindle|cropped|docked tail|long coat|fluffy|sable|bobtail|red coat)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.bluetick_coonhound.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(2)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.bluetick_coonhound'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.bluetick_coonhound'), 'key'))->toBe(['blue-ticked-tan', 'blue-ticked'])
            ->and(btData('bluetick_coonhound.appearance.colours.value'))->toBe($standard)
            ->and(btData('bluetick_coonhound.appearance.colours.quote'))->toContain('thickly mottled');
    });

    it('draws the register portrait blue ticked with tan markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BluetickCoonhound);

        expect($traits['coat_color'])->toBe('dark blue ticked (thickly mottled) with black spots, a black head and tan markings')
            ->and($portrait->prompt(BreedType::BluetickCoonhound))->toContain('Bluetick Coonhound')->toContain('tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::BluetickCoonhound))->toBe('dog/bluetick-coonhound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BluetickCoonhound);
        expect($v1['prompt_anchor'])->toContain('Bluetick Coonhound')->toContain('mottled')->toContain('half-moon')
            ->not->toMatch('/\b(merle|albino|brindle|docked tail|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('dark blue ticked with black spots and tan markings');

        $pet = btPet(99, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bluetick Coonhound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bluetick_coonhound'),
            config('breed_suitability.breeds.bluetick_coonhound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bluetick-coonhound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BluetickCoonhound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; 11–12 years → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(btData('proposed_game_parameters.bluetick_coonhound.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 Dogs Trust, 108 VCA summary) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bluetick-coonhound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('considers an experienced owner, mental challenges and a secure fence; no child, company or prey-drive chip', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BluetickCoonhound);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['experienced_owner', 'needs_mental_stimulation', 'secure_fencing'])
            // Dogs NSW "gets along well with children" vs PetMD older children → no child chip either way.
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('active_family')->not->toContain('often_alone')
            ->and($tags['consider'])->not->toContain('older_children')->not->toContain('needs_company')
            ->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('sheds');

        expect(btData('bluetick_coonhound.behaviour.experienced_owner.quote'))->toContain('experienced pet parents')
            ->and(btData('bluetick_coonhound.exercise.daily_text.quote'))->toContain('mental stimulation')
            ->and(btData('bluetick_coonhound.exercise.scent_drive.quote'))->toContain('fenced yards are a must')
            ->and(btData('bluetick_coonhound.behaviour.children.notes'))->toContain('conflicting')
            ->and(btData('bluetick_coonhound.behaviour.other_pets.quote'))->not->toMatch('/(high|strong) prey drive/');
    });
});
