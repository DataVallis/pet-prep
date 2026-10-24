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
| M5-R10-50 — Bichon Frise (appended after the Portuguese Water Dog: AKC 2024 No. 46 "Bichon Frise")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bichon_frise` (S409–S418) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bichon_frise` (exercise 30 min — RKC "Up to 30
| minutes per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 — 0.75 × RKC lower
| bound 12 y, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 108, learning × 1.0 (Coren
| rank 45, Average), paid breed, Border Collie care rates, brushing every day (RKC "Grooming: Every day",
| M5-R13), puppy socialisation with the PetMD quote (M5-R17), no meal / walk gap (no source); suitability:
| suits apartment / family_pet / children / other_pets / low_shedding, consider frequent_grooming /
| needs_company; pure white (portrait); not a welfare breed (RKC Breed Watch Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bicData(string $path): mixed
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

/** @return array<string, mixed> the Bichon Frise seeder row of (stage, from, key) */
function bicRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bichon-frise'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bichon-frise.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bicPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bichonFrise()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bicPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bicLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BichonFrise;
        $config = BreedConfig::where('breed_slug', 'bichon-frise')->sole();

        expect($breed->value)->toBe('bichon_frise')
            ->and($breed->slug())->toBe('bichon-frise')
            ->and(BreedType::fromSlug('bichon-frise'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 460, 'breeds.bichon_frise'])
            ->and($config->search_keywords)->toBe(['bichon frise', 'bichon frisé', 'bichon', 'bichon a poil frise', 'bichon tenerife', 'kodrasti bišon', 'kodrasti bison', 'bišon'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bicData('proposed_game_parameters.bichon_frise.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bicData('proposed_game_parameters.bichon_frise.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bichon Frise pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bicPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bichon_frise');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "bichon" / "bichon_a_poil_frise" are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bichon']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bichon_a_poil_frise']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_30_120000_add_bichon_frise_breed.php');
        // down() refuses while a Bichon Frise exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bichon_frise']))->toThrow(QueryException::class);
        // The Portuguese Water Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'portuguese_water_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bichon_frise']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BichonFrise);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bichon-frise')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')->count());

        BreedConfig::where('breed_slug', 'bichon-frise')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bichon-frise')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bichon-frise')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bichon-frise');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bichon-frise'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BICHON_FRISE)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Bichon Frise's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BICHON_FRISE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bichon_frise';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bicRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bicData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bicRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bicData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bicRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bicData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S411) = 108 (provisional until McMillan 2024 is checked).
            ->and(bicRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bicData('bichon_frise.lifespan.senior_from.months'))
            ->and(bicRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bicRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bicData('bichon_frise.lifespan.senior_from.derived_from'))->toBe(['S11', 'S411'])
            ->and(bicRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S411')
            ->and(bicRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bicRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(bicRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bicData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bicRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(bicRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bicData('bichon_frise.exercise.adult.quote'))
            ->and(bicRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(bicRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bicData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bicRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bicRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bicData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(bicRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bicData("{$pgp}.learning_multiplier.value"))
            ->and(bicRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Bichon Frise decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BICHON_FRISE) {
                $decided++;
                expect($row['breed_slug'])->toBe('bichon-frise')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bichon_frise.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bicData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); the groomer waits for ROADMAP M5-R18, tooth brushing for M5-R15,
        // nail trimming for M5-R19, ear cleaning and baths for M5-R20, tear-stain cleaning for M5-R14.
        // No source names a meal / walk gap → no M5-R16 row.
        expect(json_encode([bicData('bichon_frise'), bicData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bicData("{$pgp}.grooming.value"))->toBe(7)
            ->and(bicData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(bicData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(bicData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(bicData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(bicData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(bicData("{$pgp}.face_care.decision"))->toContain('ROADMAP M5-R14')
            ->and(bicData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['bichon_frise'][1])
            ->and(bicData('bichon_frise.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['bichon_frise'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('bichon_frise')
            ->and(bicData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(bicData('bichon_frise.exercise.after_meals.source_id'))->toBeNull();
    });

    it('takes the breed-level numbers from the sourced bichon_frise entries', function () {
        // PDSA (S413) 4–7 kg; the FCI (S410) gives one approximate number, "Approximately 5 kg", inside it.
        expect(bicRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.0, 7.0])
            ->and(bicData('bichon_frise.adult_weight.pdsa.value'))->toEqual(['male' => [4, 7], 'female' => [4, 7]])
            ->and(bicData('bichon_frise.adult_weight.fci.value'))->toEqual(['male' => [5, 5], 'female' => [5, 5]])
            ->and(bicRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bicData('bichon_frise.adult_weight.pdsa.source_id'))
            ->and(bicRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bicData('bichon_frise.adult_weight.pdsa.quote'))
            ->and(bicRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(bicData('bichon_frise.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 45 (S35), Average tier → learning 1.0.
            ->and(bicRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(45)
            ->and(bicRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(bicData('bichon_frise.trainability.coren_rank.quote'))
            ->and(bicData('bichon_frise.trainability.coren_rank.value'))->toBe(45)
            ->and(bicRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bichon_frise.lifespan.rkc')
            ->and(bicRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S411')
            ->and(bicRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(bicRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bicData('bichon_frise.lifespan.rkc.quote'));

        // Brushing every day (RKC "Grooming: Every day" → 7) — the rule, verified.
        $grooming = bicRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S411')
            ->and($grooming['quote'])->toBe(bicData('bichon_frise.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bicData('proposed_game_parameters.bichon_frise.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(bicRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bicRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S416');
        // No meal / walk gap row (no source names one for the breed).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S416).
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

        expect($general('bichon-frise'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bicPet($arrival);
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

    it('becomes senior at 108 months, the same month as the Papillon', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bichon-frise', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bichon-frise', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('papillon', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('papillon', 107))->toBe(LifeStage::Adult);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bicPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bichon_frise', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bicPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bicLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BichonFrise)
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
        foreach (['border_collie', 'bichon_frise'] as $breed) {
            $pin = bicPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bicLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bichon_frise'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Bichon Frise appearance data.
            ->and($dog->pet_dna['breed'])->toBe('bichon_frise')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bicPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bichon Frise after the Portuguese Water Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bichon_frise', 'portuguese_water_dog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bichon_frise', 'slug' => 'bichon-frise', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bichon_frise',
            'search_keywords' => seededBreedConfig('bichon_frise')['search_keywords'], 'sort_order' => 460,
            'suitability' => [
                'suits' => ['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colour only — pure white on an adult)', function () {
    it('samples Bichon Frise DNA in pure white only, with drooping ears and a curly coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bichon_frise')
            ->and(PetDnaService::hasAppearance('bichon_frise'))->toBeTrue()
            ->and(config('breed_appearance.bichon_frise.verified'))->toBeFalse()
            ->and(config('breed_appearance.bichon_frise.prompt_order'))->toContain('nose')->toContain('ear_carriage');

        foreach (range(1, 100) as $seed) {
            $traits = $dna->sample('bichon_frise', $seed)['traits'];
            expect($traits['coat_color'])->toBe('pure white')
                ->and($traits['ear_carriage'])->toContain('drooping')
                ->and($traits['coat_length'])->toContain('corkscrew')
                ->and($traits['nose'])->toContain('not short-faced');

            $prompt = $prompts->imagePrompt('bichon_frise', $traits);
            expect($prompt)->toContain('Bichon Frise')->toContain('pure white')
                ->not->toMatch('/\b(merle|brindle|apricot|cream|black coat|erect|docked|cropped|corded)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The one standard colour of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        expect(array_column(config('breed_colours.bichon_frise'), 'value'))->toBe(['pure white'])
            ->and(array_column(config('breed_colours.bichon_frise'), 'key'))->toBe(['white']);
    });

    it('draws the register portrait pure white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BichonFrise);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::BichonFrise))->toContain('Bichon Frise')->toContain('pure white')->toContain('corkscrew')
            ->and(BreedPortraitService::relativeFile(BreedType::BichonFrise))->toBe('dog/bichon-frise.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BichonFrise);
        expect($v1['prompt_anchor'])->toContain('Bichon Frise')->toContain('pure white')->toContain('corkscrew')
            ->not->toContain('apricot')
            ->and($v1['visual_traits']['color_scheme'])->toBe('pure white')
            ->and($v1['visual_traits']['eye_color'])->toBe('very dark');

        $pet = bicPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bichon Frise')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bichon_frise'),
            config('breed_suitability.breeds.bichon_frise'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more likely', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BichonFrise);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 12 years"; RKC "Up to 30 minutes"; no prey-drive statement.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets', 'sheds']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'first_time_owner', 'large_home', 'active_family']))->toBe([])
            ->and(bicData('bichon_frise.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(bicData('proposed_game_parameters.bichon_frise.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (senior 113), VCA / PetMD (126) and the Dogs Trust rule of thumb (84) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bichon-frise')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(113)->not->toContain(126)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Bichon Frise apartment / family_pet / children / other_pets / low_shedding and grooming and company chips — no welfare chip (Breed Watch Category 1)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BichonFrise);
        expect($tags['suits'])->toBe(['apartment', 'family_pet', 'children', 'other_pets', 'low_shedding'])
            // No rating for small children (PetMD's "younger children" statement is open for David); no first-time-owner statement.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('active_family')->not->toContain('first_time_owner')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // Breed Watch Category 1 → no welfare chip; 30 min a day; no prey-drive statement; RKC "Sheds: No".
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('sheds')->not->toContain('older_children');

        expect(bicData('bichon_frise.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(bicData('bichon_frise.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(bicData('bichon_frise.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(bicData('bichon_frise.suitability.pdsa_children.quote'))->toBe('Bichons are very affectionate, loving dogs that are excellent with children and other pets alike.')
            ->and(bicData('bichon_frise.behaviour.time_alone.quote'))->toBe('Time Alone: Limited tolerance; prefers companionship')
            ->and(bicData('bichon_frise.suitability.rkc_breed_watch.value'))->toBe('Category 1');
    });
});
