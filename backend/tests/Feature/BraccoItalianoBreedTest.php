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
| M5-R10-136 — Bracco Italiano (appended after the Belgian Sheepdog: AKC 2024 No. 132)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bracco_italiano` (S1022–S1028) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bracco_italiano` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36, arrival 2 / 9 / 36, learning × 1.0 (not
| ranked by Coren), paid breed, Border Collie care rates, brushing 1× a week (RKC, M5-R13), puppy socialisation with the
| PetMD quote (M5-R17), 60-min meal gap with the PetMD GDV quote (M5-R16); no ear / nail / tooth / bath / groomer task, no
| heat warning). The senior boundary / senior arrival 99 (0.75 × the Bracco Italiano Club of America median 11 y) is
| Claude's suggestion, verified=false, open for David. Suitability: suits active_family, children, large_home; consider
| long_daily_exercise, needs_company, sheds, shorter_lifespan; white with orange markings portrait; in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function biData(string $path): mixed
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

/** @return array<string, mixed> the Bracco Italiano seeder row of (stage, from, key) */
function biRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bracco-italiano'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bracco-italiano.{$stage}.{$from}.{$key->value}");

    return $row;
}

function biPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->braccoItaliano()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function biPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function biLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BraccoItaliano;
        $config = BreedConfig::where('breed_slug', 'bracco-italiano')->sole();

        expect($breed->value)->toBe('bracco_italiano')
            ->and($breed->slug())->toBe('bracco-italiano')
            ->and(BreedType::fromSlug('bracco-italiano'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1270, 'breeds.bracco_italiano'])
            ->and($config->search_keywords)->toBe(['bracco italiano', 'italijanski ptičar', 'italijanski pticar', 'italian pointer', 'italian pointing dog', 'bracco', 'braco'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) biData('proposed_game_parameters.bracco_italiano.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = biData('proposed_game_parameters.bracco_italiano.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bracco Italiano pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = biPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bracco_italiano');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'italian_pointer']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bracco']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_05_120000_add_bracco_italiano_breed.php');
        // down() refuses while a Bracco Italiano exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bracco_italiano']))->toThrow(QueryException::class);
        // The Belgian Sheepdog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_sheepdog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bracco_italiano']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BraccoItaliano);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bracco-italiano')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bracco-italiano')->count());

        BreedConfig::where('breed_slug', 'bracco-italiano')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bracco-italiano')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bracco-italiano')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bracco-italiano');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bracco-italiano');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bracco-italiano'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BRACCO_ITALIANO)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_BRACCO_ITALIANO_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_BRACCO_ITALIANO', 'PROPOSED_R10_BRACCO_ITALIANO_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bracco_italiano';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(biRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(biData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(biRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(biData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(biRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(biData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11 y (Bracco Italiano Club of America median, S1028) = 99 — the closest rule row, Claude's
            // suggestion (RKC "Under 10 years" has no lower bound, no McMillan 2024 / VetCompass median) → verified=false.
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(biData('bracco_italiano.lifespan.senior_from.months'))
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(biData("{$pgp}.senior_boundary_months.value"))
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * biRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(biData('bracco_italiano.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1028'])
            ->and(biData('bracco_italiano.lifespan.mcmillan_2024.source_id'))->toBeNull()
            // PetMD 10 y → 90 is David's alternative, never seeded.
            ->and(biData("{$pgp}.stage_boundaries_months.alternatives.senior_petmd_10_y"))->toBe(90)
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S1028')
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(biRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_BRACCO_ITALIANO_SENIOR)
            ->and(biData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_BRACCO_ITALIANO_SENIOR)
            ->and(biRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(99);

        expect(biRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(biData("{$pgp}.exercise_minutes_adult.value"))
            ->and(biRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(biRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(biData('bracco_italiano.exercise.adult.quote'))
            ->and(biRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(biData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(biRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1024')
            ->and(biRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(biRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(biData("{$pgp}.exercise_minutes_senior.value"))
            ->and(biRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(biRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(biData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(biRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(biData("{$pgp}.learning_multiplier.value"))
            ->and(biRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BRACCO_ITALIANO) {
                $decided++;
                expect($row['breed_slug'])->toBe('bracco-italiano')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bracco_italiano.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) biData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: weekly brushing (RKC, M5-R13), puppy socialisation with the PetMD
        // quote (M5-R17), the 60-min meal gap with the PetMD GDV quote (M5-R16). No ear / nail / tooth / bath / groomer task
        // (no frequency in an A/B source), no heat warning (not flat-faced).
        expect(json_encode([biData('bracco_italiano'), biData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(BreedStageParamsSeeder::DOG_GROOMING['bracco_italiano'])->toBe([1, 'S1024', biData("{$pgp}.grooming.quote"), 'high', false])
            ->and(biData("{$pgp}.grooming.quote"))->toBe(biData('bracco_italiano.suitability.rkc_grooming.quote'))
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['bracco_italiano'])->toBe(['S1027', biData("{$pgp}.meal_exercise_gap.quote")])
            ->and(biData("{$pgp}.meal_exercise_gap.quote"))->toBe(biData('bracco_italiano.exercise.after_meals.quote'))
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['bracco_italiano'])->toBe(['S1027', biData("{$pgp}.socialisation.quote")])
            ->and(biData("{$pgp}.socialisation.quote"))->toBe(biData('bracco_italiano.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('bracco_italiano')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('bracco_italiano');
    });

    it('takes the breed-level numbers from the sourced bracco_italiano entries', function () {
        // FCI-Standard N° 202 (S1023): "Weight: Between 25 and 40 kg depending on size." (both sexes).
        expect(biRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([25, 40])
            ->and(biData('bracco_italiano.adult_weight.fci.value'))->toEqual(['male' => [25, 40], 'female' => [25, 40]])
            ->and(biRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(biData('bracco_italiano.adult_weight.fci.source_id'))
            ->and(biRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(biData('bracco_italiano.adult_weight.fci.quote'))
            ->and(biRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(biData('bracco_italiano.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(biRow('all', 0, StageParamKey::GrowthEndMonths)['quote'])->toBe(biData('bracco_italiano.growth.adult_weight_reached.quote'))
            // Not ranked by Coren → no rank, learning 1.0.
            ->and(biRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(biData('bracco_italiano.trainability.coren_rank.value'))->toBeNull()
            ->and(biRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bracco_italiano.lifespan.bica_health_statement')
            ->and(biRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S1028')
            ->and(biRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.0)
            ->and(biRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(biData('bracco_italiano.lifespan.bica_health_statement.quote'))
            ->and(biRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(biRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1027')
            ->and(biRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bracco-italiano');
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S1027')
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP)
            ->and($mine->where('key', StageParamKey::GroomingSessionsPerWeek->value)->pluck('value')->all())->toBe([1])
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
            // M5-R13: weekly brushing (RKC S1024). M5-R16: the meal gap (PetMD S1027). M5-R17: the socialisation quote (PetMD S1027).
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bracco-italiano'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = biPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age, below the cap)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 98 months' => [98, LifeStage::Adult, 120, 12000],
        'senior 99 months' => [99, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 99 months, later than the Belgian Sheepdog', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bracco-italiano', 98))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bracco-italiano', 99))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('belgian-sheepdog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bracco-italiano', 90))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (unranked, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = biPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bracco_italiano', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = biPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(biLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BraccoItaliano)
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
        foreach (['border_collie', 'bracco_italiano'] as $breed) {
            $pin = biPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(biLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bracco_italiano'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(99)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('bracco_italiano')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = biPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bracco Italiano after the Belgian Sheepdog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bracco_italiano', 'belgian_sheepdog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bracco_italiano', 'slug' => 'bracco-italiano', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bracco_italiano',
            'search_keywords' => seededBreedConfig('bracco_italiano')['search_keywords'], 'sort_order' => 1270,
            'suitability' => [
                'suits' => ['active_family', 'children', 'large_home'],
                'consider' => ['long_daily_exercise', 'needs_company', 'sheds', 'shorter_lifespan'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 202, S1023)', function () {
    it('samples DNA only in the five FCI colours — never black, tricolour, fawn or tan, never a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bracco_italiano')
            ->and(PetDnaService::hasAppearance('bracco_italiano'))->toBeTrue()
            ->and(config('breed_appearance.bracco_italiano.verified'))->toBeFalse()
            ->and(config('breed_appearance.bracco_italiano.prompt_order'))->toContain('coat_color')->toContain('nose')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.bracco_italiano.traits.coat_color'), 'value');
        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('bracco_italiano', $seed)['traits'];
            $colours[$traits['coat_color']] = true;

            $prompt = $prompts->imagePrompt('bracco_italiano', $traits);
            expect($prompt)->toContain('Bracco Italiano')
                ->not->toMatch('/\b(black|tricolou?r|tan|fawn|merle|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect($standard)->toBe(['white with orange markings', 'white with brown markings', 'orange roan', 'brown roan', 'white'])
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.bracco_italiano'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.bracco_italiano'), 'key'))->toBe(['orange-white', 'brown-white', 'orange-roan', 'brown-roan', 'white'])
            ->and(biData('bracco_italiano.appearance.colours.value'))->toBe($standard)
            ->and(biData('bracco_italiano.appearance.colours.quote'))->toContain('orange or more or less dark amber');
    });

    it('draws the register portrait white with orange markings (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BraccoItaliano);

        expect($traits['coat_color'])->toBe('white with orange markings')
            ->and($portrait->prompt(BreedType::BraccoItaliano))->toContain('Bracco Italiano')->toContain('white with orange markings')
            ->and(BreedPortraitService::relativeFile(BreedType::BraccoItaliano))->toBe('dog/bracco-italiano.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BraccoItaliano);
        expect($v1['prompt_anchor'])->toContain('Bracco Italiano')->toContain('orange markings')
            ->not->toMatch('/\b(black|tricolou?r|merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('ochre')
            ->and($v1['visual_traits']['color_scheme'])->toBe('white with orange markings');

        $pet = biPet(99, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bracco Italiano')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bracco_italiano'),
            config('breed_suitability.breeds.bracco_italiano'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bracco-italiano')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%', '26.6'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BraccoItaliano);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Breed Watch Category 1 → no welfare chip; not flat-faced → no breathing chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'eyelid_problems', 'hips_hind_legs']))->toBe([])
            ->and(biData('bracco_italiano.suitability.rkc_breed_watch.quote'))->toBe('Category 1')
            ->and(biData('proposed_game_parameters.bracco_italiano.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (90 PetMD, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bracco-italiano')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family, children and a large home; consider long exercise, company, shedding and lifespan', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BraccoItaliano);
        expect($tags['suits'])->toBe(['active_family', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_company', 'sheds', 'shorter_lifespan'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('family_pet')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('frequent_grooming');

        expect(biData('bracco_italiano.behaviour.children.quote'))->toStartWith('Bracchi Italiani are generally good with other dogs and with children')
            ->and(biData('bracco_italiano.behaviour.other_pets.quote'))->not->toContain('high prey drive')
            ->and(biData('bracco_italiano.suitability.rkc_lifespan.quote'))->toBe('Under 10 years')
            ->and(biData('bracco_italiano.suitability.rkc_sheds.quote'))->toBe('Yes')
            ->and(biData('bracco_italiano.suitability.rkc_size_of_home.quote'))->toBe('Large house');
    });
});
