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
| M5-R10-55 — Wirehaired Pointing Griffon (appended after the Whippet: AKC 2024 No. 51 "Wirehaired Pointing Griffon")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `wirehaired_pointing_griffon` (S448–S456) and the runbook's standing
| rules (confirmed by David 2026-10-10) in `proposed_game_parameters.wirehaired_pointing_griffon` (exercise 120 min —
| RKC "Exercise: More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 —
| 0.75 × RKC lower bound "Over 10 years"; the Dogs Trust McMillan 2024 table has no row for the breed —, arrival
| 2 / 9 / 36 / 90, learning × 1.0 (Coren rank 46, Average), paid breed, Border Collie care rates, brushing once a week
| (VCA "weekly brushing", M5-R13), puppy socialisation with the PetMD quote (M5-R17), no digestion warning (no quote);
| suitability: suits active_family / family_pet / children / large_home, consider long_daily_exercise /
| chases_small_pets; steel grey with liver patches portrait (never black); no welfare concern found, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function wpgData(string $path): mixed
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

/** @return array<string, mixed> the Wirehaired Pointing Griffon seeder row of (stage, from, key) */
function wpgRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'wirehaired-pointing-griffon'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("wirehaired_pointing_griffon.{$stage}.{$from}.{$key->value}");

    return $row;
}

function wpgPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->wirehairedPointingGriffon()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function wpgPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function wpgLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::WirehairedPointingGriffon;
        $config = BreedConfig::where('breed_slug', 'wirehaired-pointing-griffon')->sole();

        expect($breed->value)->toBe('wirehaired_pointing_griffon')
            ->and($breed->slug())->toBe('wirehaired-pointing-griffon')
            ->and(BreedType::fromSlug('wirehaired-pointing-griffon'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 510, 'breeds.wirehaired_pointing_griffon'])
            ->and($config->search_keywords)->toBe(['wirehaired pointing griffon', 'korthals griffon', 'korthals', 'griffon', 'griff', 'francoski ostrodlaki ptičar', 'francoski ostrodlaki pticar', 'ostrodlaki ptičar', 'ostrodlaki pticar', 'korthalsov grifon', 'grifon'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) wpgData('proposed_game_parameters.wirehaired_pointing_griffon.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = wpgData('proposed_game_parameters.wirehaired_pointing_griffon.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Wirehaired Pointing Griffon pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = wpgPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('wirehaired_pointing_griffon');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'korthals_griffon']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'francoski_ostrodlaki_pticar']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_11_120000_add_wirehaired_pointing_griffon_breed.php');
        // down() refuses while a Wirehaired Pointing Griffon exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'wirehaired_pointing_griffon']))->toThrow(QueryException::class);
        // The Whippet (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'whippet']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'wirehaired_pointing_griffon']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::WirehairedPointingGriffon);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'wirehaired-pointing-griffon')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wirehaired-pointing-griffon')->count());

        BreedConfig::where('breed_slug', 'wirehaired-pointing-griffon')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'wirehaired-pointing-griffon')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'wirehaired-pointing-griffon')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'wirehaired-pointing-griffon');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wirehaired-pointing-griffon');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'wirehaired-pointing-griffon'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WIREHAIRED_POINTING_GRIFFON)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_WIREHAIRED_POINTING_GRIFFON');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.wirehaired_pointing_griffon';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(wpgRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(wpgData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(wpgRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wpgData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(wpgRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wpgData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S450) = 90 — the runbook's third choice.
            ->and(wpgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(wpgData('wirehaired_pointing_griffon.lifespan.senior_from.months'))
            ->and(wpgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * wpgRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(wpgData('wirehaired_pointing_griffon.lifespan.senior_from.derived_from'))->toBe(['S11', 'S450'])
            ->and(wpgData('wirehaired_pointing_griffon.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(wpgRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S450')
            ->and(wpgRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(wpgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(wpgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wpgData("{$pgp}.exercise_minutes_adult.value"))
            ->and(wpgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(wpgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wpgData('wirehaired_pointing_griffon.exercise.adult.quote'))
            ->and(wpgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S450')
            ->and(wpgRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(wpgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wpgData("{$pgp}.exercise_minutes_senior.value"))
            ->and(wpgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(wpgRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(wpgData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(wpgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(wpgData("{$pgp}.learning_multiplier.value"))
            ->and(wpgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WIREHAIRED_POINTING_GRIFFON) {
                $decided++;
                expect($row['breed_slug'])->toBe('wirehaired-pointing-griffon')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.wirehaired_pointing_griffon.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) wpgData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); a slow feeder / no raised bowl and hand stripping have no
        // mechanic → open for David (a new ROADMAP task). No quoted gap after meals → no digestion warning.
        expect(json_encode([wpgData('wirehaired_pointing_griffon'), wpgData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(wpgData("{$pgp}.grooming.value"))->toBe(1)
            ->and(wpgData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(wpgData("{$pgp}.bloat_feeding.decision"))->toContain('nova naloga v ROADMAP')
            ->and(wpgData("{$pgp}.hand_stripping.decision"))->toContain('nova naloga v ROADMAP')
            ->and(wpgData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('wirehaired_pointing_griffon')
            ->and(wpgData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['wirehaired_pointing_griffon'][1])
            ->and(wpgData('wirehaired_pointing_griffon.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['wirehaired_pointing_griffon'][1]);
    });

    it('takes the breed-level numbers from the sourced wirehaired_pointing_griffon entries', function () {
        // PetMD 35–70 lb (S453) → 16–32 kg; the FCI, AKC and UKC standards give no weight.
        expect(wpgRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16.0, 32.0])
            ->and(wpgData('wirehaired_pointing_griffon.adult_weight.petmd.value'))->toEqual(['male' => [16, 32], 'female' => [16, 32]])
            ->and(wpgRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(wpgData('wirehaired_pointing_griffon.adult_weight.petmd.source_id'))
            ->and(wpgRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(wpgData('wirehaired_pointing_griffon.adult_weight.petmd.quote'))
            ->and(wpgRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(wpgData('wirehaired_pointing_griffon.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 46 (S35), Average tier → learning 1.0.
            ->and(wpgRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(46)
            ->and(wpgRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(wpgData('wirehaired_pointing_griffon.trainability.coren_rank.quote'))
            ->and(wpgData('wirehaired_pointing_griffon.trainability.coren_rank.value'))->toBe(46)
            ->and(wpgRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('wirehaired_pointing_griffon.lifespan.rkc')
            ->and(wpgRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S450')
            ->and(wpgRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(wpgRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(wpgData('wirehaired_pointing_griffon.lifespan.rkc.quote'));

        // Brushing once a week (RKC "More than once a week" without a number → VCA "weekly brushing" → 1) — the rule, verified.
        $grooming = wpgRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S454')
            ->and($grooming['quote'])->toBe(wpgData('wirehaired_pointing_griffon.suitability.vca_grooming.quote'))
            ->and($grooming['quote'])->toBe(wpgData('proposed_game_parameters.wirehaired_pointing_griffon.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(wpgRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(wpgRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S453');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wirehaired-pointing-griffon');
        // No digestion warning (no verbatim gap-after-meal quote) and, like the other pedigree breeds, no individual variation row.
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

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
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S453).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('wirehaired-pointing-griffon'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = wpgPet($arrival);
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
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('wirehaired-pointing-griffon', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('wirehaired-pointing-griffon', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = wpgPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'wirehaired_pointing_griffon', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = wpgPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(wpgLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::WirehairedPointingGriffon)
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
        foreach (['border_collie', 'wirehaired_pointing_griffon'] as $breed) {
            $pin = wpgPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(wpgLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['wirehaired_pointing_griffon'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('wirehaired_pointing_griffon')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = wpgPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Wirehaired Pointing Griffon after the Whippet with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'wirehaired_pointing_griffon', 'whippet');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'wirehaired_pointing_griffon', 'slug' => 'wirehaired-pointing-griffon', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.wirehaired_pointing_griffon',
            'search_keywords' => seededBreedConfig('wirehaired-pointing-griffon')['search_keywords'], 'sort_order' => 510,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home'],
                'consider' => ['long_daily_exercise', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — never black)', function () {
    it('samples DNA in the five standard colours of the appearance block only, never black, with a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('wirehaired_pointing_griffon')
            ->and(PetDnaService::hasAppearance('wirehaired_pointing_griffon'))->toBeTrue()
            ->and(config('breed_appearance.wirehaired_pointing_griffon.verified'))->toBeFalse()
            ->and(config('breed_appearance.wirehaired_pointing_griffon.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('wirehaired_pointing_griffon', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('wiry')
                ->and($traits['tail'])->toContain('natural tail')
                ->and($traits['head'])->toContain('brown nose');

            $prompt = $prompts->imagePrompt('wirehaired_pointing_griffon', $traits);
            expect($prompt)->toContain('Wirehaired Pointing Griffon')
                ->not->toMatch('/\b(black|merle|docked|cropped|curly|woolly|Drahthaar|German)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['steel grey with brown (liver) patches', 'liver roan', 'solid liver (brown)', 'white and brown (liver)', 'white and orange'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.wirehaired_pointing_griffon'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.wirehaired_pointing_griffon'), 'key'))->toBe(['steel-grey-liver', 'liver-roan', 'liver', 'liver-white', 'orange-white']);
    });

    it('draws the register portrait steel grey with liver patches', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::WirehairedPointingGriffon);

        expect($traits['coat_color'])->toBe('steel grey with brown (liver) patches')
            ->and($portrait->prompt(BreedType::WirehairedPointingGriffon))->toContain('Wirehaired Pointing Griffon')->toContain('steel grey')
            ->and(BreedPortraitService::relativeFile(BreedType::WirehairedPointingGriffon))->toBe('dog/wirehaired-pointing-griffon.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::WirehairedPointingGriffon);
        expect($v1['prompt_anchor'])->toContain('Wirehaired Pointing Griffon')->toContain('steel grey')
            ->not->toMatch('/\b(black|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['steel grey with brown (liver) patches', 'liver roan', 'white and orange'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark yellow or brown');

        $pet = wpgPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Wirehaired Pointing Griffon')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.wirehaired_pointing_griffon'),
            config('breed_suitability.breeds.wirehaired_pointing_griffon'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wirehaired-pointing-griffon')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::WirehairedPointingGriffon);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No Breed Watch category and no welfare statement (RKC S450) → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(wpgData('wirehaired_pointing_griffon.health'))->not->toHaveKey('breed_watch')
            ->and(wpgData('proposed_game_parameters.wirehaired_pointing_griffon.marketing.source_id'))->toBe('S452')
            ->and(wpgData('proposed_game_parameters.wirehaired_pointing_griffon.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and the PetMD lower bound (108) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wirehaired-pointing-griffon')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the active-family, family, children and large-home chips plus long exercise and prey drive; never small_children, other_pets, apartment, needs_company or shedding chips', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::WirehairedPointingGriffon);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'chases_small_pets'])
            ->and($tags['consider'])->not->toContain('needs_company')->not->toContain('sheds')->not->toContain('frequent_grooming')
            ->not->toContain('experienced_owner')->not->toContain('older_children');

        expect(wpgData('wirehaired_pointing_griffon.suitability.rkc_exercise.quote'))->toBe('Exercise: More than 2 hours per day')
            ->and(wpgData('wirehaired_pointing_griffon.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(wpgData('wirehaired_pointing_griffon.suitability.ukc_family.quote'))->toBe('which makes it suitable as a family pet and guardian')
            ->and(wpgData('wirehaired_pointing_griffon.behaviour.family.quote'))->toBe('Very gentle with children.')
            ->and(wpgData('wirehaired_pointing_griffon.behaviour.other_pets.quote'))->toContain('strong prey drive');
    });
});
