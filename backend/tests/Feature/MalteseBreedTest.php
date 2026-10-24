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
| M5-R10-42 — Maltese (appended after the Maltese: AKC 2024 No. 37 "Maltese")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `maltese` (S345–S352) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.maltese` (exercise 30 min — RKC "Up to 30
| minutes per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 — 0.75 × RKC
| lower bound 12 y, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 108, learning × 0.7
| (Coren rank 59, Fair), paid breed, Border Collie care rates, brushing every day (RKC "Every day", M5-R13),
| puppy socialisation with the PDSA quote (M5-R17), no meal / walk gap (no source); suitability: suits
| apartment / low_shedding, consider frequent_grooming / needs_company; pure white only (portrait), natural
| coat; not a welfare breed (RKC Breed Watch Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function malData(string $path): mixed
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

/** @return array<string, mixed> the Maltese seeder row of (stage, from, key) */
function malRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'maltese'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("maltese.{$stage}.{$from}.{$key->value}");

    return $row;
}

function malPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->maltese()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function malPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function malLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Maltese;
        $config = BreedConfig::where('breed_slug', 'maltese')->sole();

        expect($breed->value)->toBe('maltese')
            ->and($breed->slug())->toBe('maltese')
            ->and(BreedType::fromSlug('maltese'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 380, 'breeds.maltese'])
            ->and($config->search_keywords)->toBe(['maltese', 'maltežan', 'maltezan', 'malteški bišon', 'malteski bison', 'malteški psiček', 'malteski psicek', 'bichon maltais', 'maltese terrier'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) malData('proposed_game_parameters.maltese.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = malData('proposed_game_parameters.maltese.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Maltese pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = malPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('maltese');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "bichon_maltais" is no breed key (the FCI's French name is only a search keyword).
            ->and($refused(['breed_type' => 'bichon_maltais']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_20_120000_add_maltese_breed.php');
        // down() refuses while a Maltese exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'maltese']))->toThrow(QueryException::class);
        // The English Cocker Spaniel (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_cocker_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maltese']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Maltese);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'maltese')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')->count());

        BreedConfig::where('breed_slug', 'maltese')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'maltese')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'maltese')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'maltese');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'maltese'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MALTESE)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Maltese's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_MALTESE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.maltese';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(malRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(malData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(malRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(malData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(malRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(malData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S347) = 108 (provisional until McMillan 2024 is checked).
            ->and(malRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(malData('maltese.lifespan.senior_from.months'))
            ->and(malRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * malRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(malData('maltese.lifespan.senior_from.derived_from'))->toBe(['S11', 'S347'])
            ->and(malRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S347')
            ->and(malRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(malRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(malRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(malData("{$pgp}.exercise_minutes_adult.value"))
            ->and(malRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(malRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(malData('maltese.exercise.adult.quote'))
            ->and(malRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(malRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(malData("{$pgp}.exercise_minutes_senior.value"))
            ->and(malRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(malRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(malData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(malRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(malData("{$pgp}.learning_multiplier.value"))
            ->and(malRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every Maltese decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MALTESE) {
                $decided++;
                expect($row['breed_slug'])->toBe('maltese')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.maltese.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) malData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); tooth brushing waits for ROADMAP M5-R15, tear-stain /
        // face care for M5-R14, ear care for M5-R20. No source names a meal / walk gap → no M5-R16 row.
        expect(json_encode([malData('maltese'), malData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(malData("{$pgp}.grooming.value"))->toBe(7)
            ->and(malData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(malData("{$pgp}.tooth_brushing.value"))->toBe(7)
            ->and(malData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(malData("{$pgp}.face_care.decision"))->toContain('ROADMAP M5-R14')
            ->and(malData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(malData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['maltese'][1])
            ->and(malData('maltese.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['maltese'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('maltese')
            ->and(malData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(malData('maltese.exercise.after_meals.source_id'))->toBeNull();
    });

    it('takes the breed-level numbers from the sourced maltese entries', function () {
        // FCI "Weight: 3–4 kg." (S346), one range for both sexes (PDSA agrees; the AKC 4–6 lb is an alternative).
        expect(malRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.0, 4.0])
            ->and(malData('maltese.adult_weight.fci.value'))->toEqual(['male' => [3, 4], 'female' => [3, 4]])
            ->and(malRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(malData('maltese.adult_weight.fci.source_id'))
            ->and(malRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(malData('maltese.adult_weight.fci.quote'))
            ->and(malRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(malData('maltese.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 59 (S35), Fair tier → learning 0.7.
            ->and(malRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(59)
            ->and(malRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(malData('maltese.trainability.coren_rank.quote'))
            ->and(malData('maltese.trainability.coren_rank.value'))->toBe(59)
            ->and(malRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('maltese.lifespan.rkc')
            ->and(malRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S347')
            ->and(malRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(malRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(malData('maltese.lifespan.rkc.quote'));

        // Brushing every day (RKC "Every day"; PDSA daily grooming; PetMD daily brushing) — the rule, verified.
        $grooming = malRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S347')
            ->and($grooming['quote'])->toBe(malData('maltese.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(malData('proposed_game_parameters.maltese.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(malRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(malRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S348');
        // No meal / walk gap row (no source names one for the breed).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S348).
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

        expect($general('maltese'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = malPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, the same month as the Hungarian Vizsla', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('maltese', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('maltese', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('hungarian-vizsla', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('hungarian-vizsla', 107))->toBe(LifeStage::Adult);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = malPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'maltese', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = malPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(malLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Maltese)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 108 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'maltese'] as $breed) {
            $pin = malPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(malLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['maltese'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Maltese appearance data.
            ->and($dog->pet_dna['breed'])->toBe('maltese')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = malPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Maltese after the English Cocker Spaniel with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'maltese', 'english_cocker_spaniel');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'maltese', 'slug' => 'maltese', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.maltese',
            'search_keywords' => seededBreedConfig('maltese')['search_keywords'], 'sort_order' => 380,
            'suitability' => [
                'suits' => ['apartment', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colour only — pure white)', function () {
    it('samples Maltese DNA in pure white only with a long natural coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('maltese')
            ->and(PetDnaService::hasAppearance('maltese'))->toBeTrue()
            ->and(config('breed_appearance.maltese.verified'))->toBeFalse()
            ->and(config('breed_appearance.maltese.prompt_order'))->toContain('nose');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('maltese', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['nose'])->toContain('not short-faced')
                ->and($traits['coat_length'])->toContain('straight');

            $prompt = $prompts->imagePrompt('maltese', $traits);
            expect($prompt)->toContain('Maltese')->toContain('pure white')
                ->not->toMatch('/\b(merle|brindle|cream|apricot|black and|tan markings|patches|curly coat|bows?)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The one standard colour of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        expect(array_keys($colours))->toBe(['pure white'])
            ->and(array_column(config('breed_colours.maltese'), 'value'))->toBe(['pure white']);
    });

    it('draws the register portrait pure white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Maltese);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::Maltese))->toContain('Maltese')->toContain('pure white')
            ->and(BreedPortraitService::relativeFile(BreedType::Maltese))->toBe('dog/maltese.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Maltese);
        expect($v1['prompt_anchor'])->toContain('Maltese')->toContain('pure white')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBe('pure white')
            ->and($v1['visual_traits']['eye_color'])->toBe('very dark brown');

        $pet = malPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Maltese')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.maltese'),
            config('breed_suitability.breeds.maltese'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Maltese);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 12 years"; RKC "Up to 30 minutes"; no prey-drive statement.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'children', 'family_pet', 'first_time_owner', 'large_home', 'active_family']))->toBe([])
            ->and(malData('maltese.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(malData('proposed_game_parameters.maltese.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (senior 118) and the Dogs Trust rule of thumb (84) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'maltese')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(118)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Maltese apartment / low_shedding and no welfare chip (Breed Watch Category 1) — daily brushing, needs company; no children chip (supervision advice only)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Maltese);
        expect($tags['suits'])->toBe(['apartment', 'low_shedding'])
            // Only supervision advice about young children (AKC S350, PetMD S351); no family-pet / other-pets statement.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('children')->not->toContain('family_pet')
            ->not->toContain('other_pets')->not->toContain('active_family')->not->toContain('first_time_owner')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // Breed Watch Category 1 → no welfare chip; RKC "Sheds: No"; 30 min a day; no prey-drive statement.
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('sheds')->not->toContain('older_children')->not->toContain('fragile_older_children');

        expect(malData('maltese.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(malData('maltese.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(malData('maltese.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(malData('maltese.behaviour.time_alone.quote'))->toBe('This breed can develop separation anxiety when left alone for too long.')
            ->and(malData('maltese.suitability.rkc_breed_watch.value'))->toBe('Category 1');
    });
});
