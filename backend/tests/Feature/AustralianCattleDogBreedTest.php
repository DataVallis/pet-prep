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
| M5-R10-53 — Australian Cattle Dog (appended after the Dalmatian: AKC 2024 No. 49 "Australian Cattle Dog")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `australian_cattle_dog` (S435–S441) and the runbook's standing
| rules (confirmed by David 2026-10-10) in `proposed_game_parameters.australian_cattle_dog` (exercise 120 min —
| RKC "Exercise: More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 /
| 126 — 0.75 × McMillan 2024 median 14.0 y via Dogs Trust S123 —, arrival 2 / 9 / 36 / 126, learning × 1.8
| (Coren rank 10, Brightest), paid breed, Border Collie care rates, brushing once a week (RKC "Once a week",
| M5-R13), no meal / walk gap (no quote), puppy socialisation with the PDSA quote (M5-R17); suitability: suits
| active_family / family_pet, consider long_daily_exercise / needs_mental_stimulation / may_herd_children / sheds;
| blue speckled portrait (blue or red speckle, never cream, white or merle); Breed Watch Category 1, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function acdData(string $path): mixed
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

/** @return array<string, mixed> the Australian Cattle Dog seeder row of (stage, from, key) */
function acdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'australian-cattle-dog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("australian-cattle-dog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function acdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->australianCattleDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function acdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function acdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AustralianCattleDog;
        $config = BreedConfig::where('breed_slug', 'australian-cattle-dog')->sole();

        expect($breed->value)->toBe('australian_cattle_dog')
            ->and($breed->slug())->toBe('australian-cattle-dog')
            ->and(BreedType::fromSlug('australian-cattle-dog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 490, 'breeds.australian_cattle_dog'])
            ->and($config->search_keywords)->toBe(['australian cattle dog', 'avstralski govedar', 'avstralski govedarski pes', 'cattle dog', 'blue heeler', 'red heeler', 'queensland heeler', 'heeler', 'acd'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) acdData('proposed_game_parameters.australian_cattle_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = acdData('proposed_game_parameters.australian_cattle_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Australian Cattle Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = acdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('australian_cattle_dog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Nicknames and colours are no breed keys (only search keywords / a colour).
            ->and($refused(['breed_type' => 'blue_heeler']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'red_speckle']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_03_120000_add_australian_cattle_dog_breed.php');
        // down() refuses while an Australian Cattle Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'australian_cattle_dog']))->toThrow(QueryException::class);
        // The Dalmatian (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dalmatian']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_cattle_dog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AustralianCattleDog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'australian-cattle-dog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-cattle-dog')->count());

        BreedConfig::where('breed_slug', 'australian-cattle-dog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'australian-cattle-dog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'australian-cattle-dog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'australian-cattle-dog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-cattle-dog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'australian-cattle-dog'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_CATTLE_DOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Australian Cattle Dog's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_AUSTRALIAN_CATTLE_DOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.australian_cattle_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(acdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(acdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(acdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(acdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(acdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(acdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 14.0 y (McMillan 2024 median via Dogs Trust S123) = 126 — sourced, not provisional.
            ->and(acdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(acdData('australian_cattle_dog.lifespan.senior_from.months'))
            ->and(acdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * acdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(acdData('australian_cattle_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(acdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(acdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(acdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(126);

        expect(acdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(acdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(acdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(acdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(acdData('australian_cattle_dog.exercise.adult.quote'))
            ->and(acdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(acdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(acdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(acdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(acdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(acdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(acdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(acdData("{$pgp}.learning_multiplier.value"))
            ->and(acdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.8);

        // Every Australian Cattle Dog decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_CATTLE_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('australian-cattle-dog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.australian_cattle_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) acdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); ear cleaning / baths and nails wait for ROADMAP M5-R20 / R19.
        // No meal / walk gap quote.
        expect(json_encode([acdData('australian_cattle_dog'), acdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(acdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(acdData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(acdData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(acdData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('australian_cattle_dog')
            ->and(acdData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(acdData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['australian_cattle_dog'][1])
            ->and(acdData('australian_cattle_dog.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['australian_cattle_dog'][1]);
    });

    it('takes the breed-level numbers from the sourced australian_cattle_dog entries', function () {
        // PDSA "16kg-20kg" (S438); the KC standard gives no weight, PetMD 35–50 lb is an alternative.
        expect(acdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16.0, 20.0])
            ->and(acdData('australian_cattle_dog.adult_weight.pdsa.value'))->toEqual(['male' => [16, 20], 'female' => [16, 20]])
            ->and(acdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(acdData('australian_cattle_dog.adult_weight.pdsa.source_id'))
            ->and(acdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(acdData('australian_cattle_dog.adult_weight.pdsa.quote'))
            ->and(acdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(acdData('australian_cattle_dog.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 10 (S35), Brightest tier → learning 1.8.
            ->and(acdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(10)
            ->and(acdRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(acdData('australian_cattle_dog.trainability.coren_rank.quote'))
            ->and(acdData('australian_cattle_dog.trainability.coren_rank.value'))->toBe(10)
            ->and(acdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('australian_cattle_dog.lifespan.median_uk')
            ->and(acdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(acdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(14.0)
            ->and(acdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(acdData('australian_cattle_dog.lifespan.median_uk.quote'))
            ->and(acdData('australian_cattle_dog.lifespan.rkc.quote'))->toBe('Over 10 years');

        // Brushing once a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = acdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S437')
            ->and($grooming['quote'])->toBe(acdData('australian_cattle_dog.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(acdData('proposed_game_parameters.australian_cattle_dog.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(acdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(acdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S438');

        // No digestion warning (no source quotes a gap after meals); no individual variation row (factor 1.0).
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-cattle-dog');
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
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S438).
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

        expect($general('australian-cattle-dog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = acdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 125 months' => [125, LifeStage::Adult, 120, 12000],
        'senior 126 months' => [126, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 126 months, seven months after the Dalmatian (119)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('australian-cattle-dog', 125))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('australian-cattle-dog', 126))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('dalmatian', 119))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-cattle-dog', 119))->toBe(LifeStage::Adult);
    });

    it('learns much faster than a mixed breed (Coren Brightest tier, × 1.8) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = acdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.8);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'australian_cattle_dog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = acdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(acdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AustralianCattleDog)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 126 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'australian_cattle_dog'] as $breed) {
            $pin = acdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(acdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['australian_cattle_dog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(126)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Australian Cattle Dog appearance data.
            ->and($dog->pet_dna['breed'])->toBe('australian_cattle_dog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = acdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Australian Cattle Dog after the Dalmatian with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'australian_cattle_dog', 'dalmatian');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'australian_cattle_dog', 'slug' => 'australian-cattle-dog', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.australian_cattle_dog',
            'search_keywords' => seededBreedConfig('australian-cattle-dog')['search_keywords'], 'sort_order' => 490,
            'suitability' => [
                'suits' => ['active_family', 'family_pet'],
                'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — blue or red speckle; never cream, white or merle)', function () {
    it('samples Australian Cattle Dog DNA in the two Kennel Club standard colours only', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('australian_cattle_dog')
            ->and(PetDnaService::hasAppearance('australian_cattle_dog'))->toBeTrue()
            ->and(config('breed_appearance.australian_cattle_dog.verified'))->toBeFalse()
            ->and(config('breed_appearance.australian_cattle_dog.prompt_order'))->toContain('ear_carriage')->toContain('tail');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('australian_cattle_dog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('pricked');

            $prompt = $prompts->imagePrompt('australian_cattle_dog', $traits);
            expect($prompt)->toContain('Australian Cattle Dog')
                ->not->toMatch('/\b(merle|cream|white|brindle|docked|stumpy|cropped|Kelpie|Dingo)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['blue speckled with black and tan markings on the head', 'even red speckle with darker red markings on the head'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.australian_cattle_dog'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.australian_cattle_dog'), 'key'))->toBe(['blue-speckled', 'red-speckle']);
    });

    it('draws the register portrait blue speckled', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AustralianCattleDog);

        expect($traits['coat_color'])->toBe('blue speckled with black and tan markings on the head')
            ->and($portrait->prompt(BreedType::AustralianCattleDog))->toContain('Australian Cattle Dog')->toContain('blue speckled')
            ->and(BreedPortraitService::relativeFile(BreedType::AustralianCattleDog))->toBe('dog/australian-cattle-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AustralianCattleDog);
        expect($v1['prompt_anchor'])->toContain('Australian Cattle Dog')->toContain('blue speckled')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['blue speckled with black and tan head markings', 'even red speckle with darker red head markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = acdPet(126, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Australian Cattle Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; Breed Watch Category 1, no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.australian_cattle_dog'),
            config('breed_suitability.breeds.australian_cattle_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-cattle-dog')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AustralianCattleDog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Category 1 → no welfare chip; RKC "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan', 'chases_small_pets']))->toBe([])
            ->and(acdData('australian_cattle_dog.health.breed_watch.quote'))->toBe('Currently no points of concern specific to this breed have been identified for special attention by judges')
            ->and(acdData('proposed_game_parameters.australian_cattle_dog.marketing.source_id'))->toBe('S437')
            ->and(acdData('proposed_game_parameters.australian_cattle_dog.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and the RKC lower bound (90) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-cattle-dog')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(90);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Australian Cattle Dog active_family / family_pet and the herding / energy chips; never children, small_children, apartment or large_home', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AustralianCattleDog);
        expect($tags['suits'])->toBe(['active_family', 'family_pet'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')
            ->not->toContain('large_home')->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'sheds'])
            ->and($tags['consider'])->not->toContain('not_for_small_children')->not->toContain('frequent_grooming')->not->toContain('experienced_owner')->not->toContain('needs_company');

        expect(acdData('australian_cattle_dog.suitability.pdsa_children.quote'))->toBe('Due to their strong herding instinct, they may nip at the heels of running children')
            ->and(acdData('australian_cattle_dog.suitability.petmd_family.quote'))->toBe('Australian Cattle Dogs can be good family dogs, and can be raised with children.')
            ->and(acdData('australian_cattle_dog.suitability.rkc_size_of_home.quote'))->toBe('Small house')
            ->and(acdData('australian_cattle_dog.suitability.rkc_shedding.quote'))->toBe('Yes');
    });
});
