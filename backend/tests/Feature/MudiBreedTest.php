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
| M5-R10-126 — Mudi (appended after the Pointer: AKC 2024 No. 122)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `mudi` (S948–S953) and the runbook's standing rules (confirmed by David
| 2026-10-10) in `proposed_game_parameters.mudi` (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"), arrival 2 / 9 / 36 / 90,
| learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing 1× a week (RKC "Once a week",
| M5-R13), puppy socialisation with the PetMD quote (S952, M5-R17); no tooth / nail / ear / bath / groomer task (no
| frequency), no meal gap, no heat warning. No welfare chip, in marketing; suits family_pet; consider
| independent_experienced_owner, needs_mental_stimulation, sheds; black portrait (one of seven FCI colours), natural tail.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function mudiData(string $path): mixed
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

/** @return array<string, mixed> the Mudi seeder row of (stage, from, key) */
function mudiRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'mudi'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("mudi.{$stage}.{$from}.{$key->value}");

    return $row;
}

function mudiPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->mudi()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function mudiPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function mudiLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Mudi;
        $config = BreedConfig::where('breed_slug', 'mudi')->sole();

        expect($breed->value)->toBe('mudi')
            ->and($breed->slug())->toBe('mudi')
            ->and(BreedType::fromSlug('mudi'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1170, 'breeds.mudi'])
            ->and($config->search_keywords)->toBe(['mudi', 'hungarian mudi', 'madžarski mudi', 'madzarski mudi'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) mudiData('proposed_game_parameters.mudi.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = mudiData('proposed_game_parameters.mudi.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Mudi pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = mudiPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('mudi');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'hungarian_mudi']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'mudik']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_20_120000_add_mudi_breed.php');
        // down() refuses while a Mudi exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'mudi']))->toThrow(QueryException::class);
        // The Pointer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pointer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'mudi']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Mudi);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'mudi')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mudi')->count());

        BreedConfig::where('breed_slug', 'mudi')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'mudi')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'mudi')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'mudi');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mudi');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'mudi'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MUDI)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_MUDI');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.mudi';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(mudiRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(mudiData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(mudiRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mudiData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(mudiRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(mudiData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S950 — no McMillan 2024 / VetCompass value) → confirmed rule.
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(mudiData('mudi.lifespan.senior_from.months'))
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * mudiRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(mudiData('mudi.lifespan.senior_from.derived_from'))->toBe(['S11', 'S950'])
            // The Dogs Trust rule of thumb and the PetMD lower bound are background only — never the seeded boundary.
            ->and(mudiData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(mudiData("{$pgp}.stage_boundaries_months.alternatives.senior_petmd_lower_bound_12_y"))->toBe(108)
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', mudiData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_MUDI)
            ->and(mudiRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(mudiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(mudiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mudiData("{$pgp}.exercise_minutes_adult.value"))
            ->and(mudiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(mudiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(mudiData('mudi.exercise.adult.quote'))
            ->and(mudiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(mudiData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(mudiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S950')
            ->and(mudiRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(mudiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(mudiData("{$pgp}.exercise_minutes_senior.value"))
            ->and(mudiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(mudiRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(mudiData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(mudiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(mudiData("{$pgp}.learning_multiplier.value"))
            ->and(mudiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MUDI) {
                $decided++;
                expect($row['breed_slug'])->toBe('mudi')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.mudi.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) mudiData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 1× a week (M5-R13, RKC) and puppy socialisation with the PetMD
        // quote (M5-R17); mental stimulation = the existing school / play. No tooth / nail / ear / bath / groomer task (no
        // frequency in an A/B source), no meal gap (no quote), no heat warning.
        expect(json_encode([mudiData('mudi'), mudiData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(mudiData("{$pgp}.grooming.value"))->toBe(1)
            ->and(mudiData("{$pgp}.grooming.quote"))->toBe(mudiData('mudi.suitability.rkc_grooming.quote'))
            ->and(mudiData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('mudi')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['mudi'])->toBe(['S952', mudiData("{$pgp}.socialisation.quote")])
            ->and(mudiData("{$pgp}.socialisation.quote"))->toBe(mudiData('mudi.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('mudi')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('mudi');
    });

    it('takes the breed-level numbers from the sourced mudi entries', function () {
        // PetMD (S952): 18–29 lb → 8.2–13.2 kg for both sexes (the FCI standard has no weight).
        expect(mudiRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([8.2, 13.2])
            ->and(mudiData('mudi.adult_weight.petmd.value'))->toEqual(['male' => [8.2, 13.2], 'female' => [8.2, 13.2]])
            ->and(mudiRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(mudiData('mudi.adult_weight.petmd.source_id'))
            ->and(mudiRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(mudiData('mudi.adult_weight.petmd.quote'))
            ->and(mudiRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(mudiData('mudi.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not ranked by Coren → 1.0.
            ->and(mudiRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(mudiData('mudi.trainability.coren_rank.value'))->toBeNull()
            ->and(mudiRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('mudi.lifespan.rkc')
            ->and(mudiRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S950')
            ->and(mudiRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(mudiRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(mudiData('mudi.lifespan.rkc.quote'));

        // Brushing 1× a week: RKC "Once a week" (PetMD "a few times a week" has no number).
        $grooming = mudiRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S950')
            ->and($grooming['quote'])->toBe(mudiData('mudi.suitability.rkc_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(mudiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(mudiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S952')
            ->and(mudiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mudi');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
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
            // M5-R17: the socialisation quote (PetMD S952) for this breed.
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('mudi'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = mudiPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months like the Spinone Italiano, earlier than the Pointer', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('mudi', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('mudi', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('spinone-italiano', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pointer', 90))->toBe(LifeStage::Adult);
    });

    it('learns like the mixed breed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = mudiPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'mudi', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = mudiPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(mudiLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Mudi)
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
        foreach (['border_collie', 'mudi'] as $breed) {
            $pin = mudiPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(mudiLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['mudi'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('mudi')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = mudiPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Mudi after the Pointer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'mudi', 'pointer');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'mudi', 'slug' => 'mudi', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.mudi',
            'search_keywords' => seededBreedConfig('mudi')['search_keywords'], 'sort_order' => 1170,
            'suitability' => [
                'suits' => ['family_pet'],
                'consider' => ['independent_experienced_owner', 'needs_mental_stimulation', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 238 S949)', function () {
    it('samples DNA only in the seven FCI colours — never sable or tan points, never a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('mudi')
            ->and(PetDnaService::hasAppearance('mudi'))->toBeTrue()
            ->and(config('breed_appearance.mudi.verified'))->toBeFalse()
            ->and(config('breed_appearance.mudi.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.mudi.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('mudi', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('mudi', $traits);
            expect($prompt)->toContain('Mudi')->toContain('prick ears')
                ->not->toMatch('/\b(sable|tan|tricolou?r|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.mudi');
        expect($standard)->toBe(['black', 'white', 'fawn', 'ash (blue)', 'liver (brown)', 'ash-brown (isabella)', 'blue merle (cifra)'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black', 'white', 'fawn', 'blue', 'liver', 'isabella', 'blue-merle'])
            ->and(mudiData('mudi.appearance.colours.value'))->toHaveCount(7)
            ->and(mudiData('mudi.appearance.colours.source_id'))->toBe('S949');
    });

    it('draws the register portrait black (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Mudi);

        expect($traits['coat_color'])->toBe('black')
            ->and($portrait->prompt(BreedType::Mudi))->toContain('Mudi')->toContain('black')
            ->and(BreedPortraitService::relativeFile(BreedType::Mudi))->toBe('dog/mudi.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Mudi);
        expect($v1['prompt_anchor'])->toContain('Mudi')->toContain('prick ears')
            ->not->toMatch('/\b(sable|tan|tricolou?r|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black');

        $pet = mudiPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Mudi')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.mudi'),
            config('breed_suitability.breeds.mudi'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mudi')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Mudi);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed for conformation problems → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(mudiData('proposed_game_parameters.mudi.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 108 PetMD lower bound) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'mudi')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family; consider an experienced owner, mental stimulation and shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Mudi);
        expect($tags['suits'])->toBe(['family_pet'])
            ->and($tags['consider'])->toBe(['independent_experienced_owner', 'needs_mental_stimulation', 'sheds'])
            // No explicit children statement → no children chip; RKC "Small house" → neither apartment nor large_home;
            // up to 1 hour → no long_daily_exercise; medium-sized → not experienced_owner (that chip names a powerful dog).
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->not->toContain('active_family')
            ->and($tags['consider'])->not->toContain('experienced_owner')->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('shorter_lifespan')->not->toContain('secure_fencing')->not->toContain('frequent_grooming');

        expect(mudiData('mudi.behaviour.family.quote'))->toBe('Loyal and affectionate with their families')
            ->and(mudiData('mudi.behaviour.experienced_owner.quote'))->toBe('The Mudi is best suited to an experienced owner who can provide the stimulation, structure and purpose this spirited breed needs')
            ->and(mudiData('mudi.exercise.daily_text.quote'))->toBe('they do require an outlet for their energy and intelligence.')
            ->and(mudiData('mudi.suitability.rkc_sheds.quote'))->toBe('Yes')
            ->and(mudiData('mudi.lifespan.rkc.quote'))->toBe('Over 10 years');
    });
});
