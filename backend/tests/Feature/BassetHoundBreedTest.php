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
| M5-R10-39 — Basset Hound (appended after the Belgian Malinois: AKC 2024 No. 34)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `basset_hound` (S319–S325) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.basset_hound`
| (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until McMillan 2024 is checked —, arrival
| 2 / 9 / 36 / 90, learning × 0.5 (Coren rank 71, Lowest), paid breed, Border Collie care rates,
| brushing 1× a week (RKC "Once a week", M5-R13), the "wait with the walk" warning after meals (PDSA, M5-R16),
| puppy socialisation with the PDSA quote (M5-R17); suitability: suits family_pet / children, consider
| eyelid_problems / sensitive_skin / food_motivated_weight / needs_company / sheds / drools; tricolour
| (portrait) or lemon and white, never merle; welfare breed (RKC Breed Watch Category 3) → moderate ears,
| tight eyelids and little loose skin in the AI appearance; not flat-faced → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bhData(string $path): mixed
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

/** @return array<string, mixed> the Basset Hound seeder row of (stage, from, key) */
function bhRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'basset-hound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("basset_hound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bhPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bassetHound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bhPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bhLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BassetHound;
        $config = BreedConfig::where('breed_slug', 'basset-hound')->sole();

        expect($breed->value)->toBe('basset_hound')
            ->and($breed->slug())->toBe('basset-hound')
            ->and(BreedType::fromSlug('basset-hound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 350, 'breeds.basset_hound'])
            ->and($config->search_keywords)->toBe(['basset hound', 'baset', 'basset', 'baset hound', 'baset haund', 'bassett'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bhData('proposed_game_parameters.basset_hound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bhData('proposed_game_parameters.basset_hound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Basset Hound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bhPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('basset_hound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'basset_fauve_de_bretagne']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_17_120000_add_basset_hound_breed.php');
        // down() refuses while a Basset Hound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'basset_hound']))->toThrow(QueryException::class);
        // The Belgian Malinois (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_malinois']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'basset_hound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BassetHound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'basset-hound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')->count());

        BreedConfig::where('breed_slug', 'basset-hound')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'basset-hound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'basset-hound')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'basset-hound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'belgian-malinois', 'hungarian-vizsla'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BASSET_HOUND)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Basset Hound's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BASSET_HOUND');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.basset_hound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bhRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bhData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bhRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bhData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bhRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bhData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S321) = 90 — provisional until McMillan 2024 is checked.
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bhData('basset_hound.lifespan.senior_from.months'))
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bhRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bhData('basset_hound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S321'])
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S321')
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bhRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bhData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bhRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bhData('basset_hound.exercise.adult.quote'))
            ->and(bhRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(bhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bhData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bhRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bhRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bhData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bhData("{$pgp}.learning_multiplier.value"))
            ->and(bhRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every Basset Hound decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BASSET_HOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('basset-hound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.basset_hound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bhData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, the meal / walk warning M5-R16, socialisation M5-R17); skin-fold care waits for
        // ROADMAP M5-R14, nail care for M5-R19, ear care and baths for M5-R20.
        expect(json_encode([bhData('basset_hound'), bhData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bhData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bhData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(bhData("{$pgp}.skin_fold_care.decision"))->toContain('ROADMAP M5-R14')
            ->and(bhData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(bhData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(bhData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['basset_hound'][1])
            ->and(bhData('basset_hound.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['basset_hound'][1])
            // PDSA "Remember to leave a gap after eating and before exercising." → the digestion warning.
            ->and(bhData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['basset_hound'][1])
            ->and(bhData('basset_hound.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['basset_hound'][1]);
    });

    it('takes the breed-level numbers from the sourced basset_hound entries', function () {
        // PDSA "Average weight" 18kg-27kg (S323), one range for both sexes; the FCI standard gives no weight.
        expect(bhRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([18.0, 27.0])
            ->and(bhData('basset_hound.adult_weight.pdsa.value'))->toEqual(['male' => [18, 27], 'female' => [18, 27]])
            ->and(bhRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bhData('basset_hound.adult_weight.pdsa.source_id'))
            ->and(bhRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bhData('basset_hound.adult_weight.pdsa.quote'))
            ->and(bhRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(bhData('basset_hound.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 71 (S35), Lowest tier → learning 0.5.
            ->and(bhRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(71)
            ->and(bhRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(bhData('basset_hound.trainability.coren_rank.quote'))
            ->and(bhData('basset_hound.trainability.coren_rank.value'))->toBe(71)
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('basset_hound.lifespan.rkc')
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S321')
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(bhRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bhData('basset_hound.lifespan.rkc.quote'));

        // Brushing 1× a week (RKC "Once a week"; PDSA and AKC weekly) — the rule, verified.
        $grooming = bhRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S321')
            ->and($grooming['quote'])->toBe(bhData('basset_hound.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bhData('proposed_game_parameters.basset_hound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(bhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bhRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S323');
        // The digestion warning (M5-R16): 60 minutes after a meal (PDSA "leave a gap after eating").
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S323')
            ->and($gap[0]['verified'])->toBeTrue()
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA S323; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S323).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('basset-hound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bhPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Belgian Malinois', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('basset-hound', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('basset-hound', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('belgian-malinois', 90))->toBe(LifeStage::Senior);
    });

    it('learns more slowly than a mixed breed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bhPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'basset_hound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bhPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bhLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BassetHound)
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
        foreach (['border_collie', 'basset_hound'] as $breed) {
            $pin = bhPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bhLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['basset_hound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Basset Hound appearance data.
            ->and($dog->pet_dna['breed'])->toBe('basset_hound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bhPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Basset Hound after the Belgian Malinois with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois', 'basset_hound', 'hungarian_vizsla'])
            ->and(collect($breeds[35])->except('colours')->all())->toBe([
                'breed' => 'basset_hound', 'slug' => 'basset-hound', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.basset_hound',
                'search_keywords' => BreedConfigsSeeder::configs()[35]['search_keywords'], 'sort_order' => 350,
                'suitability' => [
                    'suits' => ['family_pet', 'children', 'large_home'],
                    'consider' => ['eyelid_problems', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds', 'drools'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; welfare breed → moderate features)', function () {
    it('samples Basset Hound DNA inside the FCI colours (tricolour or lemon and white; never merle) with moderate ears, eyelids and skin', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('basset_hound')
            ->and(PetDnaService::hasAppearance('basset_hound'))->toBeTrue()
            ->and(config('breed_appearance.basset_hound.verified'))->toBeFalse()
            ->and(config('breed_appearance.basset_hound.prompt_order'))->toContain('skin');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('basset_hound', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toMatch('/tricolour|bicolour/')
                ->and($traits['ear_carriage'])->toContain('moderate length')
                ->and($traits['eye_color'])->toContain('tight eyelids')
                ->and($traits['skin'])->toContain('no heavy folds')
                ->and($traits['build'])->toMatch('/ground clearance|sound and balanced/');

            $prompt = $prompts->imagePrompt('basset_hound', $traits);
            expect($prompt)->toContain('Basset Hound')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|droopy|drooping|blue eyes)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black, white and tan tricolour', 'lemon and white bicolour']);
    });

    it('draws the register portrait tricolour', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BassetHound);

        expect($traits['coat_color'])->toBe('black, white and tan tricolour')
            ->and($traits['eye_color'])->toContain('tight eyelids')
            ->and($portrait->prompt(BreedType::BassetHound))->toContain('Basset Hound')->toContain('tricolour')
            ->and(BreedPortraitService::relativeFile(BreedType::BassetHound))->toBe('dog/basset-hound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BassetHound);
        expect($v1['prompt_anchor'])->toContain('Basset Hound')->toContain('tricolour')->toContain('tight eyelids')->toContain('moderate length')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black, white and tan tricolour', 'black, white and tan tri-colour', 'lemon and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = bhPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Basset Hound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chips (Breed Watch Category 3), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the welfare chips and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.basset_hound'),
            config('breed_suitability.breeds.basset_hound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5.44'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::BassetHound)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['eyelid_problems', 'sensitive_skin', 'food_motivated_weight', 'needs_company', 'sheds', 'drools'])
            // Not flat-faced, IVDD not a Breed Watch point (open for David), RKC "Over 10 years".
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'shorter_lifespan', 'secure_fencing']))->toBe([])
            ->and(bhData('basset_hound.suitability.rkc_breed_watch.value'))->toBe('Category 3')
            ->and(bhData('proposed_game_parameters.basset_hound.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (12.5 y, tier C) is research only — never a seeded value (senior 113 is an alternative).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'basset-hound')->pluck('value')->all())->not->toContain(12.5)->not->toContain(113);
    });
});
