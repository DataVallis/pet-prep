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
| M5-R10-51 — Newfoundland (appended after the Bichon Frise: AKC 2024 No. 47 "Newfoundland")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `newfoundland` (S419–S426) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.newfoundland` (exercise 60 min — PDSA "Minimum
| exercise (per day): 1 hour"; the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at
| 60, stages 9 / 36 / 99 — 0.75 × McMillan 2024 median 11.0 y via Dogs Trust S123 —, arrival 2 / 9 / 36 / 99,
| learning × 1.2 (Coren rank 34, Above average), paid breed, Border Collie care rates, brushing every day (PDSA
| "Grooming is a daily task", M5-R13), the 60-minute meal / walk warning (PetMD "an hour", M5-R16), puppy
| socialisation with the PetMD quote (M5-R17); suitability: suits family_pet / children / other_pets, consider
| shorter_lifespan / eyelid_problems (welfare, Kennel Club Category 2) / needs_company / sheds /
| frequent_grooming / drools; solid black portrait (black / Landseer / brown, never gray); in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function nflData(string $path): mixed
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

/** @return array<string, mixed> the Newfoundland seeder row of (stage, from, key) */
function nflRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'newfoundland'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("newfoundland.{$stage}.{$from}.{$key->value}");

    return $row;
}

function nflPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->newfoundland()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function nflPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function nflLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Newfoundland;
        $config = BreedConfig::where('breed_slug', 'newfoundland')->sole();

        expect($breed->value)->toBe('newfoundland')
            ->and($breed->slug())->toBe('newfoundland')
            ->and(BreedType::fromSlug('newfoundland'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 470, 'breeds.newfoundland'])
            ->and($config->search_keywords)->toBe(['newfoundland', 'newfie', 'newf', 'novofundlandec', 'novofundlandski pes', 'njufi', 'terra nova'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) nflData('proposed_game_parameters.newfoundland.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = nflData('proposed_game_parameters.newfoundland.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Newfoundland pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = nflPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('newfoundland');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Nicknames and the Landseer colour are no breed keys (only search keywords / a colour).
            ->and($refused(['breed_type' => 'newfie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'landseer']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_01_120000_add_newfoundland_breed.php');
        // down() refuses while a Newfoundland exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'newfoundland']))->toThrow(QueryException::class);
        // The Bichon Frise (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bichon_frise']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'newfoundland']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Newfoundland);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'newfoundland')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland')->count());

        BreedConfig::where('breed_slug', 'newfoundland')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'newfoundland')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'newfoundland')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'newfoundland');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'newfoundland'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NEWFOUNDLAND)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Newfoundland's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_NEWFOUNDLAND');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.newfoundland';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(nflRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(nflData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(nflRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(nflData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(nflRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(nflData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.0 y (McMillan 2024 median via Dogs Trust S123) = 99 — sourced, not provisional.
            ->and(nflRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(nflData('newfoundland.lifespan.senior_from.months'))
            ->and(nflRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * nflRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(nflData('newfoundland.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(nflRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(nflRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(nflRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(99);

        expect(nflRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nflData("{$pgp}.exercise_minutes_adult.value"))
            ->and(nflRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(nflRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(nflData('newfoundland.exercise.adult.quote'))
            ->and(nflRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(nflRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(nflData("{$pgp}.exercise_minutes_senior.value"))
            ->and(nflRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(nflRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(nflData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(nflRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(nflData("{$pgp}.learning_multiplier.value"))
            ->and(nflRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every Newfoundland decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NEWFOUNDLAND) {
                $decided++;
                expect($row['breed_slug'])->toBe('newfoundland')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.newfoundland.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) nflData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, meal gap M5-R16, socialisation M5-R17); bathing / ear cleaning wait for ROADMAP
        // M5-R20, drool wiping for a new ROADMAP task (David).
        expect(json_encode([nflData('newfoundland'), nflData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(nflData("{$pgp}.grooming.value"))->toBe(7)
            ->and(nflData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(nflData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(nflData("{$pgp}.drool_wiping.decision"))->toContain('needs-david')
            ->and(nflData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(nflData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['newfoundland'][1])
            ->and(nflData('newfoundland.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['newfoundland'][1])
            ->and(nflData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['newfoundland'][1])
            ->and(nflData('newfoundland.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['newfoundland'][1]);
    });

    it('takes the breed-level numbers from the sourced newfoundland entries', function () {
        // PDSA "Average weight: 50-69 kg" (S423); FCI averages 68 / 54 kg and AKC 130–150 / 100–120 lb are alternatives.
        expect(nflRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([50.0, 69.0])
            ->and(nflData('newfoundland.adult_weight.pdsa.value'))->toEqual(['male' => [50, 69], 'female' => [50, 69]])
            ->and(nflData('newfoundland.adult_weight.fci_average.value'))->toEqual(['male' => 68, 'female' => 54])
            ->and(nflRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(nflData('newfoundland.adult_weight.pdsa.source_id'))
            ->and(nflRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(nflData('newfoundland.adult_weight.pdsa.quote'))
            ->and(nflRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(nflData('newfoundland.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 34 (S35), Above average tier → learning 1.2.
            ->and(nflRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(34)
            ->and(nflRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(nflData('newfoundland.trainability.coren_rank.quote'))
            ->and(nflData('newfoundland.trainability.coren_rank.value'))->toBe(34)
            ->and(nflRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('newfoundland.lifespan.median_uk')
            ->and(nflRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(nflRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.0)
            ->and(nflRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(nflData('newfoundland.lifespan.median_uk.quote'))
            ->and(nflData('newfoundland.lifespan.pdsa.quote'))->toBe('Average lifespan: Under 10 years');

        // Brushing every day (PDSA "a daily task" → 7) — the rule, verified.
        $grooming = nflRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S423')
            ->and($grooming['quote'])->toBe(nflData('newfoundland.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(nflData('proposed_game_parameters.newfoundland.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The digestion warning (M5-R16): 60 min, PetMD's verbatim advice (GDV).
            ->and(nflRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(nflRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S424')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(nflRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(nflRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S424');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning (PetMD S424) and the puppy's weekly "new experience"
            // with the breed's own PetMD quote (S424).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('newfoundland'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = nflPet($arrival);
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
        'adult 98 months' => [98, LifeStage::Adult, 60, 6000],
        'senior 99 months' => [99, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 99 months, three months before the Boxer (102)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('newfoundland', 98))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('newfoundland', 99))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('boxer', 99))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('boxer', 102))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Above average tier, × 1.2) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = nflPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'newfoundland', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = nflPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(nflLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Newfoundland)
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
        foreach (['border_collie', 'newfoundland'] as $breed) {
            $pin = nflPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(nflLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['newfoundland'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(99)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Newfoundland appearance data.
            ->and($dog->pet_dna['breed'])->toBe('newfoundland')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = nflPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Newfoundland after the Bichon Frise with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'newfoundland', 'bichon_frise');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'newfoundland', 'slug' => 'newfoundland', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.newfoundland',
            'search_keywords' => seededBreedConfig('newfoundland')['search_keywords'], 'sort_order' => 470,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'other_pets'],
                'consider' => ['shorter_lifespan', 'eyelid_problems', 'needs_company', 'sheds', 'frequent_grooming', 'drools'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — black, Landseer, brown; never gray; welfare: closely fitting eyelids)', function () {
    it('samples Newfoundland DNA in the three FCI colours only, with closely fitting eyelids', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('newfoundland')
            ->and(PetDnaService::hasAppearance('newfoundland'))->toBeTrue()
            ->and(config('breed_appearance.newfoundland.verified'))->toBeFalse()
            ->and(config('breed_appearance.newfoundland.prompt_order'))->toContain('muzzle')->toContain('eye_color');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('newfoundland', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['eye_color'])->toContain('closely fitting eyelids')
                ->and($traits['muzzle'])->toContain('open nostrils');

            $prompt = $prompts->imagePrompt('newfoundland', $traits);
            expect($prompt)->toContain('Newfoundland')
                ->not->toMatch('/\b(gray|grey|merle|brindle|tan|docked|cropped|Labrador|St\.? Bernard|Leonberger)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['solid black', 'white and black (Landseer): white body with a black head and white blaze, black saddle and black rump', 'solid brown (chocolate to bronze)'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.newfoundland'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.newfoundland'), 'key'))->toBe(['black', 'landseer', 'brown']);
    });

    it('draws the register portrait solid black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Newfoundland);

        expect($traits['coat_color'])->toBe('solid black')
            ->and($portrait->prompt(BreedType::Newfoundland))->toContain('Newfoundland')->toContain('solid black')->toContain('closely fitting eyelids')
            ->and(BreedPortraitService::relativeFile(BreedType::Newfoundland))->toBe('dog/newfoundland.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Newfoundland);
        expect($v1['prompt_anchor'])->toContain('Newfoundland')->toContain('solid black coat')
            ->not->toContain('gray')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid black', 'white and black (Landseer)', 'solid brown'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = nflPet(99, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Newfoundland')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; Category 2 welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.newfoundland'),
            config('breed_suitability.breeds.newfoundland'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '62%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Newfoundland);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toContain('eyelid_problems')->toContain('shorter_lifespan')
            // One Breed Watch point (loose eyelids) → no other welfare chip; one hour a day → no long_daily_exercise.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets', 'not_for_small_children']))->toBe([])
            ->and(nflData('newfoundland.health.breed_watch.quote'))->toBe('Conformational defects of the upper and lower eyelids (loose eyelids)')
            ->and(nflData('proposed_game_parameters.newfoundland.marketing.source_id'))->toBe('S423')
            ->and(nflData('proposed_game_parameters.newfoundland.marketing.value'))->toContain('in marketing: yes')
            // The Dogs Trust rule of thumb (84) and the PetMD lower bound (81) are alternatives only — never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'newfoundland')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(81);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Newfoundland family_pet / children / other_pets and the Category 2 eyelid chip; never small_children, apartment or large_home', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Newfoundland);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'other_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('active_family')->not->toContain('first_time_owner')
            ->and($tags['consider'])->toBe(['shorter_lifespan', 'eyelid_problems', 'needs_company', 'sheds', 'frequent_grooming', 'drools'])
            ->and($tags['consider'])->not->toContain('chews_when_bored')->not->toContain('experienced_owner');

        expect(nflData('newfoundland.suitability.petmd_family.quote'))->toBe("A Newfoundland dog's personality makes the breed an outstanding family pet.")
            ->and(nflData('newfoundland.suitability.petmd_time_alone.quote'))->toBe('Because Newfoundland dogs are so loving, some may develop separation anxiety when left alone.')
            ->and(nflData('newfoundland.suitability.pdsa_grooming.quote'))->toBe('Grooming is a daily task, particularly when shedding.')
            ->and(nflData('newfoundland.behaviour.drooling.quote'))->toBe('Newfies also tend to drool and can be quite messy drinkers.');
    });
});
