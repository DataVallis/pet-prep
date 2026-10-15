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
| M5-R10-41 — English Cocker Spaniel (appended after the Hungarian Vizsla: AKC 2024 No. 36 "English Cocker Spaniel")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `english_cocker_spaniel` (S335–S344) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.english_cocker_spaniel`
| (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36 / 103 — 0.75 × VetCompass median 11.44 y (Engdahl 2023) —, arrival 2 / 9 / 36 / 103,
| learning × 1.5 (Coren rank 18, Excellent), paid breed, Border Collie care rates, brushing every day
| (RKC "Every day", M5-R13), the "wait with the walk" warning after meals (PDSA, M5-R16), puppy
| socialisation with the PDSA quote (M5-R17); suitability: suits family_pet / children / other_pets,
| consider frequent_grooming / food_motivated_weight / chews_when_bored / needs_company / sheds; golden
| (portrait), black, liver, black and tan, roans, parti-colours, tricolour, natural tail; not a welfare
| breed (RKC Breed Watch Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ecsData(string $path): mixed
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

/** @return array<string, mixed> the English Cocker Spaniel seeder row of (stage, from, key) */
function ecsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'english-cocker-spaniel'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("english_cocker_spaniel.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ecsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->englishCockerSpaniel()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ecsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ecsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::EnglishCockerSpaniel;
        $config = BreedConfig::where('breed_slug', 'english-cocker-spaniel')->sole();

        expect($breed->value)->toBe('english_cocker_spaniel')
            ->and($breed->slug())->toBe('english-cocker-spaniel')
            ->and(BreedType::fromSlug('english-cocker-spaniel'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 370, 'breeds.english_cocker_spaniel'])
            ->and($config->search_keywords)->toBe(['english cocker spaniel', 'angleški koker španjel', 'angleski koker spanjel', 'english cocker', 'angleški koker', 'angleski koker', 'cocker spaniel', 'koker španjel', 'koker spanjel', 'merry cocker'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ecsData('proposed_game_parameters.english_cocker_spaniel.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ecsData('proposed_game_parameters.english_cocker_spaniel.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an English Cocker Spaniel pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ecsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('english_cocker_spaniel');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // "cocker_spaniel" is no breed key (English and American Cocker are separate breeds).
            ->and($refused(['breed_type' => 'cocker_spaniel']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_19_120000_add_english_cocker_spaniel_breed.php');
        // down() refuses while an English Cocker Spaniel exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'english_cocker_spaniel']))->toThrow(QueryException::class);
        // The Hungarian Vizsla (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'hungarian_vizsla']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_cocker_spaniel']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::EnglishCockerSpaniel);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'english-cocker-spaniel')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')->count());

        BreedConfig::where('breed_slug', 'english-cocker-spaniel')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'english-cocker-spaniel')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'english-cocker-spaniel')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'english-cocker-spaniel');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'english-cocker-spaniel'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_COCKER_SPANIEL)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the English Cocker Spaniel's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_ENGLISH_COCKER_SPANIEL');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.english_cocker_spaniel';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ecsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ecsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ecsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ecsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ecsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ecsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.44 y (VetCompass median, Engdahl 2023, S342) = 102.96 → 103.
            ->and(ecsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ecsData('english_cocker_spaniel.lifespan.senior_from.months'))
            ->and(ecsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ecsRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ecsData('english_cocker_spaniel.lifespan.senior_from.derived_from'))->toBe(['S11', 'S342'])
            ->and(ecsRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S342')
            ->and(ecsRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ecsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(103);

        expect(ecsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ecsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ecsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ecsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ecsData('english_cocker_spaniel.exercise.adult.quote'))
            ->and(ecsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(ecsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ecsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ecsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ecsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ecsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(ecsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ecsData("{$pgp}.learning_multiplier.value"))
            ->and(ecsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every English Cocker Spaniel decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_COCKER_SPANIEL) {
                $decided++;
                expect($row['breed_slug'])->toBe('english-cocker-spaniel')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.english_cocker_spaniel.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ecsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, the meal / walk warning M5-R16, socialisation M5-R17); the groomer waits for
        // ROADMAP M5-R18, nail care for M5-R19, ear care for M5-R20.
        expect(json_encode([ecsData('english_cocker_spaniel'), ecsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ecsData("{$pgp}.grooming.value"))->toBe(7)
            ->and(ecsData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(ecsData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(ecsData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(ecsData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(ecsData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['english_cocker_spaniel'][1])
            ->and(ecsData('english_cocker_spaniel.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['english_cocker_spaniel'][1])
            // PDSA "Remember to leave at a gap after eating and before exercising." → the digestion warning.
            ->and(ecsData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['english_cocker_spaniel'][1])
            ->and(ecsData('english_cocker_spaniel.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['english_cocker_spaniel'][1]);
    });

    it('takes the breed-level numbers from the sourced english_cocker_spaniel entries', function () {
        // PDSA "Average weight" 13-14.5 kg (S339), one range for both sexes (the AKC weights are an alternative).
        expect(ecsRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([13.0, 14.5])
            ->and(ecsData('english_cocker_spaniel.adult_weight.pdsa.value'))->toEqual(['male' => [13, 14.5], 'female' => [13, 14.5]])
            ->and(ecsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ecsData('english_cocker_spaniel.adult_weight.pdsa.source_id'))
            ->and(ecsRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ecsData('english_cocker_spaniel.adult_weight.pdsa.quote'))
            ->and(ecsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(ecsData('english_cocker_spaniel.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 18 (S35), Excellent tier → learning 1.5.
            ->and(ecsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(18)
            ->and(ecsRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(ecsData('english_cocker_spaniel.trainability.coren_rank.quote'))
            ->and(ecsData('english_cocker_spaniel.trainability.coren_rank.value'))->toBe(18)
            ->and(ecsRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('english_cocker_spaniel.lifespan.median_uk')
            ->and(ecsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S342')
            ->and(ecsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.44)
            ->and(ecsRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ecsData('english_cocker_spaniel.lifespan.median_uk.quote'));

        // Brushing every day (RKC "Every day"; PDSA "a daily brush") — the rule, verified.
        $grooming = ecsRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S337')
            ->and($grooming['quote'])->toBe(ecsData('english_cocker_spaniel.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(ecsData('proposed_game_parameters.english_cocker_spaniel.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(ecsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ecsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S339');
        // The digestion warning (M5-R16): 60 minutes after a meal (PDSA "leave at a gap after eating and before exercising").
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S339')
            ->and($gap[0]['verified'])->toBeTrue()
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA S339; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S339).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('english-cocker-spaniel'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ecsPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 102 months' => [102, LifeStage::Adult, 60, 6000],
        'senior 103 months' => [103, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 103 months, a month after the Beagle', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('english-cocker-spaniel', 102))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('english-cocker-spaniel', 103))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('beagle', 102))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ecsPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'english_cocker_spaniel', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ecsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ecsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::EnglishCockerSpaniel)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 103 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'english_cocker_spaniel'] as $breed) {
            $pin = ecsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ecsLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['english_cocker_spaniel'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(103)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the English Cocker Spaniel appearance data.
            ->and($dog->pet_dna['breed'])->toBe('english_cocker_spaniel')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ecsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the English Cocker Spaniel after the Hungarian Vizsla with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'english_cocker_spaniel', 'hungarian_vizsla');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'english_cocker_spaniel', 'slug' => 'english-cocker-spaniel', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.english_cocker_spaniel',
            'search_keywords' => seededBreedConfig('english_cocker_spaniel')['search_keywords'], 'sort_order' => 370,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'other_pets'],
                'consider' => ['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural tail)', function () {
    it('samples English Cocker Spaniel DNA inside the AKC / FCI colours with a natural tail and no merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('english_cocker_spaniel')
            ->and(PetDnaService::hasAppearance('english_cocker_spaniel'))->toBeTrue()
            ->and(config('breed_appearance.english_cocker_spaniel.verified'))->toBeFalse()
            ->and(config('breed_appearance.english_cocker_spaniel.prompt_order'))->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('english_cocker_spaniel', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['muzzle'])->toContain('not short-faced');

            $prompt = $prompts->imagePrompt('english_cocker_spaniel', $traits);
            expect($prompt)->toContain('English Cocker Spaniel')
                ->not->toMatch('/\b(merle|brindle|cropped|docked tail|American Cocker)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // Every standard colour of the appearance block (= the colour picker, BreedColourSharedMediaTest) is drawn.
        expect(count($colours))->toBe(8)
            ->and(array_keys($colours))->each->toBeIn(array_column(config('breed_colours.english_cocker_spaniel'), 'value'));
    });

    it('draws the register portrait golden', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::EnglishCockerSpaniel);

        expect($traits['coat_color'])->toBe('golden (solid golden red)')
            ->and($traits['tail'])->toContain('undocked')
            ->and($portrait->prompt(BreedType::EnglishCockerSpaniel))->toContain('English Cocker Spaniel')->toContain('golden')
            ->and(BreedPortraitService::relativeFile(BreedType::EnglishCockerSpaniel))->toBe('dog/english-cocker-spaniel.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::EnglishCockerSpaniel);
        expect($v1['prompt_anchor'])->toContain('English Cocker Spaniel')->toContain('golden')->toContain('undocked')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['golden', 'blue roan', 'black'])
            ->and($v1['visual_traits']['eye_color'])->toBe('brown');

        $pet = ecsPet(103, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('English Cocker Spaniel')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.english_cocker_spaniel'),
            config('breed_suitability.breeds.english_cocker_spaniel'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::EnglishCockerSpaniel);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 10 years"; RKC "Up to 1 hour"; no high prey drive.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'first_time_owner', 'apartment', 'large_home', 'active_family']))->toBe([])
            ->and(ecsData('english_cocker_spaniel.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(ecsData('proposed_game_parameters.english_cocker_spaniel.marketing.value'))->toContain('in marketing: yes')
            // The RKC / VCA lower bounds (senior 90 / 108) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-cocker-spaniel')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(90)->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the English Cocker Spaniel family_pet / children / other_pets and no welfare chip (Breed Watch Category 1) — daily brushing, food-motivated, chews when bored (runbook 2026-10-15)', function () {
        $ecs = app(BreedSuitability::class)->for(BreedType::EnglishCockerSpaniel);
        expect($ecs['suits'])->toBe(['family_pet', 'children', 'other_pets'])
            // RKC "Small house" (not "Flat/ Apartment"), RKC "Up to 1 hour per day"; supervision caveat → no small_children.
            ->and($ecs['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('active_family')->not->toContain('first_time_owner')->not->toContain('low_shedding')
            ->and($ecs['consider'])->toBe(['frequent_grooming', 'food_motivated_weight', 'chews_when_bored', 'needs_company', 'sheds'])
            // Breed Watch Category 1 → no welfare chip; PDSA "don't have a high prey drive"; 60 min a day.
            ->and($ecs['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('sensitive_skin')->not->toContain('shorter_lifespan')->not->toContain('hips_hind_legs');

        expect(ecsData('english_cocker_spaniel.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(ecsData('english_cocker_spaniel.behaviour.chewing.quote'))->toBe('Without the right amount of stimulation and company, your Cocker Spaniel will likely try to entertain themselves –')
            ->and(ecsData('english_cocker_spaniel.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house')
            ->and(ecsData('english_cocker_spaniel.suitability.rkc_breed_watch.value'))->toBe('Category 1');
    });
});
