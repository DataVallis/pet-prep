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
| M5-R10-46 — Shiba Inu (appended after the Mastiff: AKC 2024 No. 42 "Shiba Inu")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `shiba_inu` (S377–S383) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.shiba_inu` (exercise 60 min — RKC "Exercise: Up
| to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 131 — 0.75 × McMillan
| 2024 median 14.6 y —, arrival 2 / 9 / 36 / 131, learning × 1.0 (not ranked by Coren), paid breed, Border
| Collie care rates, brushing once a week (RKC, M5-R13), puppy socialisation with the PetMD quote (M5-R17), no
| meal / walk gap (no source); suitability: no suits tag, consider independent_experienced_owner (new key) /
| older_children / needs_mental_stimulation / sheds; Breed Watch Category 1 → no welfare chip; red portrait
| with urajiro (red / black and tan / sesame); in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function shiData(string $path): mixed
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

/** @return array<string, mixed> the Shiba Inu seeder row of (stage, from, key) */
function shiRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'shiba-inu'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("shiba-inu.{$stage}.{$from}.{$key->value}");

    return $row;
}

function shiPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->shibaInu()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function shiPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function shiLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ShibaInu;
        $config = BreedConfig::where('breed_slug', 'shiba-inu')->sole();

        expect($breed->value)->toBe('shiba_inu')
            ->and($breed->slug())->toBe('shiba-inu')
            ->and(BreedType::fromSlug('shiba-inu'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 420, 'breeds.shiba_inu'])
            ->and($config->search_keywords)->toBe(['shiba inu', 'shiba', 'japanese shiba inu', 'šiba inu', 'šiba', 'siba inu', 'siba', 'japonski šiba inu', 'japonski siba inu'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) shiData('proposed_game_parameters.shiba_inu.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = shiData('proposed_game_parameters.shiba_inu.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Shiba Inu pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = shiPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('shiba_inu');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // The Akita and the other Japanese spitz breeds are different breeds and no breed keys.
            ->and($refused(['breed_type' => 'akita']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'shiba']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_24_120000_add_shiba_inu_breed.php');
        // down() refuses while a Shiba Inu exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'shiba_inu']))->toThrow(QueryException::class);
        // The Mastiff (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'mastiff']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shiba_inu']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ShibaInu);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'shiba-inu')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shiba-inu')->count());

        BreedConfig::where('breed_slug', 'shiba-inu')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'shiba-inu')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'shiba-inu')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'shiba-inu');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shiba-inu');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'shiba-inu'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SHIBA_INU)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Shiba Inu's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_SHIBA_INU');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.shiba_inu';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(shiRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(shiData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(shiRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(shiData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(shiRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(shiData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 14.6 y (McMillan 2024 median, S376) = 131 — sourced, not provisional.
            ->and(shiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(shiData('shiba_inu.lifespan.senior_from.months'))
            ->and(shiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * shiRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(shiData('shiba_inu.lifespan.senior_from.derived_from'))->toBe(['S11', 'S376'])
            ->and(shiRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S376')
            ->and(shiRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(shiRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(131);

        expect(shiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(shiData("{$pgp}.exercise_minutes_adult.value"))
            ->and(shiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(shiRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(shiData('shiba_inu.exercise.adult.quote'))
            ->and(shiRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(shiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(shiData("{$pgp}.exercise_minutes_senior.value"))
            ->and(shiRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(shiRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(shiData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(shiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(shiData("{$pgp}.learning_multiplier.value"))
            ->and(shiRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Shiba Inu decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SHIBA_INU) {
                $decided++;
                expect($row['breed_slug'])->toBe('shiba-inu')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.shiba_inu.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) shiData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); daily tooth brushing waits for ROADMAP M5-R15, ear care /
        // baths for M5-R20, the off-lead rule for a new task (needs-david).
        expect(json_encode([shiData('shiba_inu'), shiData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(shiData("{$pgp}.grooming.value"))->toBe(1)
            ->and(shiData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(shiData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(shiData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(shiData("{$pgp}.off_lead.decision"))->toContain('needs-david')
            // No source advises a gap between meals and exercise → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('shiba_inu')
            ->and(shiData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shiba_inu'][1])
            ->and(shiData('shiba_inu.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shiba_inu'][1]);
    });

    it('takes the breed-level numbers from the sourced shiba_inu entries', function () {
        // PetMD "17–23 pounds" (S381) = the AKC averages (S380); the FCI standard gives no weight.
        expect(shiRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([7.7, 10.4])
            ->and(shiData('shiba_inu.adult_weight.petmd.value'))->toEqual(['male' => [7.7, 10.4], 'female' => [7.7, 10.4]])
            ->and(shiData('shiba_inu.adult_weight.akc.value'))->toEqual(['male' => 10.4, 'female' => 7.7])
            ->and(shiRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(shiData('shiba_inu.adult_weight.petmd.source_id'))
            ->and(shiRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(shiData('shiba_inu.adult_weight.petmd.quote'))
            ->and(shiRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(shiData('shiba_inu.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Not ranked by Coren (S35) → learning 1.0.
            ->and(shiRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(shiData('shiba_inu.trainability.coren_rank.value'))->toBeNull()
            ->and(shiRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('shiba_inu.lifespan.median_uk')
            ->and(shiRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S376')
            ->and(shiRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.6)
            ->and(shiRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(shiData('shiba_inu.lifespan.median_uk.quote'))
            ->and(shiData('shiba_inu.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Brushing once a week (RKC "Grooming: Once a week" → 1) — the rule, verified.
        $grooming = shiRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S378')
            ->and($grooming['quote'])->toBe(shiData('shiba_inu.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(shiData('proposed_game_parameters.shiba_inu.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(shiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(shiRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S381');

        // No digestion-warning row; no individual variation row (factor 1.0), like the other pedigree breeds.
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shiba-inu');
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
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S381).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('shiba-inu'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = shiPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 130 months' => [130, LifeStage::Adult, 60, 6000],
        'senior 131 months' => [131, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 131 months, later than the Mastiff (81)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('shiba-inu', 130))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('shiba-inu', 131))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mastiff', 81))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = shiPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'shiba_inu', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = shiPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(shiLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ShibaInu)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 131 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'shiba_inu'] as $breed) {
            $pin = shiPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(shiLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['shiba_inu'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(131)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Shiba Inu appearance data.
            ->and($dog->pet_dna['breed'])->toBe('shiba_inu')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = shiPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Shiba Inu after the Mastiff with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'shiba_inu', 'mastiff');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'shiba_inu', 'slug' => 'shiba-inu', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.shiba_inu',
            'search_keywords' => seededBreedConfig('shiba-inu')['search_keywords'], 'sort_order' => 420,
            'suitability' => [
                'suits' => [],
                'consider' => ['independent_experienced_owner', 'older_children', 'needs_mental_stimulation', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — red, black and tan, sesame, always with urajiro)', function () {
    it('samples Shiba Inu DNA in the three standard colours only, always with urajiro', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('shiba_inu')
            ->and(PetDnaService::hasAppearance('shiba_inu'))->toBeTrue()
            ->and(config('breed_appearance.shiba_inu.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('shiba_inu', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toContain('urajiro')
                ->and($traits['ear_carriage'])->toContain('pricked')
                ->and($traits['tail'])->toContain('curled');

            $prompt = $prompts->imagePrompt('shiba_inu', $traits);
            expect($prompt)->toContain('Shiba Inu')
                ->not->toMatch('/\b(cream coat|white coat|pinto|merle|brindle|docked|cropped|Akita)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_colours.shiba_inu'), 'value');
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and($standard)->toHaveCount(3)
            ->and(array_column(config('breed_colours.shiba_inu'), 'key'))->toBe(['red', 'black-tan', 'sesame']);
    });

    it('draws the register portrait red with urajiro', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ShibaInu);

        expect($traits['coat_color'])->toStartWith('red with cream-white urajiro')
            ->and($portrait->prompt(BreedType::ShibaInu))->toContain('Shiba Inu')->toContain('red with cream-white urajiro')
            ->and(BreedPortraitService::relativeFile(BreedType::ShibaInu))->toBe('dog/shiba-inu.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ShibaInu);
        expect($v1['prompt_anchor'])->toContain('Shiba Inu')->toContain('red coat and cream-white urajiro')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red with cream-white urajiro', 'black and tan with cream-white urajiro', 'sesame with cream-white urajiro'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = shiPet(131, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Shiba Inu')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.shiba_inu'),
            config('breed_suitability.breeds.shiba_inu'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shiba-inu')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', 'HR 0.5'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ShibaInu);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Breed Watch Category 1 → no welfare chip; up to an hour a day → no long_daily_exercise.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'sensitive_skin', 'eyelid_problems', 'heart_and_spine', 'shorter_lifespan', 'long_daily_exercise', 'chases_small_pets', 'frequent_grooming']))->toBe([])
            ->and(shiData('shiba_inu.health.breed_watch.source_id'))->toBe('S378')
            ->and(shiData('proposed_game_parameters.shiba_inu.marketing.value'))->toContain('in marketing: yes')
            // The RKC lower bound (90) and the Dogs Trust rule of thumb (84) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shiba-inu')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(90);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Shiba Inu the new independent_experienced_owner chip, older_children, needs_mental_stimulation and sheds; no suits tag', function () {
        expect(BreedSuitability::vocabulary())->toMatchArray(['independent_experienced_owner' => BreedSuitability::CONSIDER]);

        $tags = app(BreedSuitability::class)->for(BreedType::ShibaInu);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['independent_experienced_owner', 'older_children', 'needs_mental_stimulation', 'sheds'])
            // Not the "powerful dog" chip, not the fence chip, never a children or first-time-owner chip.
            ->and($tags['consider'])->not->toContain('experienced_owner')->not->toContain('secure_fencing')->not->toContain('not_for_small_children');

        // Only the Shiba Inu carries the new key (so far).
        foreach (BreedType::cases() as $breed) {
            if ($breed !== BreedType::ShibaInu) {
                expect(app(BreedSuitability::class)->for($breed)['consider'])->not->toContain('independent_experienced_owner');
            }
        }

        expect(shiData('shiba_inu.suitability.akc_experienced_owner.quote'))->toBe('Due to their stubborn nature, a Shiba Inu is a better pick for an experienced dog owner.')
            ->and(shiData('shiba_inu.suitability.akc_children.quote'))->toBe('Overall, a Shiba would be happiest in a home with experienced, attentive dog owners and older children.')
            ->and(shiData('shiba_inu.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(shiData('shiba_inu.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house');
    });
});
