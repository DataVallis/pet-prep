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
| M5-R10-32 — Miniature American Shepherd (appended after the Boston Terrier: AKC 2024 No. 24)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `miniature_american_shepherd` (S264–S271)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.miniature_american_shepherd` (exercise 60 min — PetMD "at least one
| hour", the RKC page could not be fetched —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36 / 108 — senior = Claude's suggestion from the PetMD lower bound 12 y, verified=false
| until David decides —, arrival 2 / 9 / 36 / 108, learning × 1.0 (not ranked by Coren), paid breed,
| Border Collie care rates, brushing 2× a week (PetMD "a couple times a week", M5-R13), puppy
| socialisation with the AKC quote (M5-R17), no meal / walk warning (no source), suitability: suits
| family_pet / children / other_pets, consider needs_mental_stimulation / may_herd_children /
| needs_company / sheds (no frequent_grooming — AKC weekly vs PetMD a couple of times); blue merle (portrait), black, red merle or red — merle is
| standard for this breed —, natural undocked tail; not a welfare breed → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function masData(string $path): mixed
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

/** @return array<string, mixed> the Miniature American Shepherd seeder row of (stage, from, key) */
function masRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'miniature-american-shepherd'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("miniature-american-shepherd.{$stage}.{$from}.{$key->value}");

    return $row;
}

function masPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->miniatureAmericanShepherd()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function masPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function masLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::MiniatureAmericanShepherd;
        $config = BreedConfig::where('breed_slug', 'miniature-american-shepherd')->sole();

        expect($breed->value)->toBe('miniature_american_shepherd')
            ->and($breed->slug())->toBe('miniature-american-shepherd')
            ->and(BreedType::fromSlug('miniature-american-shepherd'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 280, 'breeds.miniature_american_shepherd'])
            ->and($config->search_keywords)->toBe(['miniature american shepherd', 'mali ameriški ovčar', 'mali ameriski ovcar', 'mini american', 'mini aussie', 'miniature australian shepherd'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) masData('proposed_game_parameters.miniature_american_shepherd.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = masData('proposed_game_parameters.miniature_american_shepherd.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Miniature American Shepherd pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = masPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('miniature_american_shepherd');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'american_bulldog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_09_120000_add_miniature_american_shepherd_breed.php');
        // down() refuses while a Miniature American Shepherd exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'miniature_american_shepherd']))->toThrow(QueryException::class);
        // The Boston Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'boston_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'miniature_american_shepherd']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::MiniatureAmericanShepherd);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'miniature-american-shepherd')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd')->count());

        BreedConfig::where('breed_slug', 'miniature-american-shepherd')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'miniature-american-shepherd')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'miniature-american-shepherd')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'miniature-american-shepherd');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MINIATURE_AMERICAN_SHEPHERD)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Miniature American Shepherd's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_MINIATURE_AMERICAN_SHEPHERD', 'PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.miniature_american_shepherd';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(masRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(masData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(masRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(masData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(masRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(masData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PetMD lower bound "12–13 years", S269) = 108 (Claude's suggestion, verified=false).
            ->and(masRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(masData('miniature_american_shepherd.lifespan.senior_from.months'))
            ->and(masRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * masRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(masData('miniature_american_shepherd.lifespan.senior_from.derived_from'))->toBe(['S11', 'S269'])
            ->and(masRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S269')
            ->and(masRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(masRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108)
            ->and(masData("{$pgp}.senior_boundary_months.value"))->toBe(108)
            ->and(masData("{$pgp}.senior_boundary_months.decision"))->toStartWith('predlog Claude 2026-10-13');

        expect(masRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(masData("{$pgp}.exercise_minutes_adult.value"))
            ->and(masRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(masRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(masData('miniature_american_shepherd.exercise.adult.quote'))
            ->and(masRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(masRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(masData("{$pgp}.exercise_minutes_senior.value"))
            ->and(masRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(masRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(masData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(masRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(masData("{$pgp}.learning_multiplier.value"))
            ->and(masRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Miniature American Shepherd decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MINIATURE_AMERICAN_SHEPHERD) {
                $decided++;
                expect($row['breed_slug'])->toBe('miniature-american-shepherd')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.miniature_american_shepherd.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) masData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); nail trimming waits for ROADMAP M5-R19. No source quotes
        // a meal / walk gap.
        expect(json_encode([masData('miniature_american_shepherd'), masData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(masData("{$pgp}.grooming.value"))->toBe(2)
            ->and(masData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(masData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(masData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('miniature_american_shepherd')
            ->and(masData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['miniature_american_shepherd'][1])
            ->and(masData('miniature_american_shepherd.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['miniature_american_shepherd'][1]);
    });

    it('takes the breed-level numbers from the sourced miniature_american_shepherd entries', function () {
        // AKC breed weight chart 20–40 lb (S268) = 9.1–18.1 kg; neither standard gives a weight.
        expect(masRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.1, 18.1])
            ->and(masData('miniature_american_shepherd.adult_weight.akc.value'))->toEqual(['male' => [9.1, 18.1], 'female' => [9.1, 18.1]])
            ->and(masRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(masData('miniature_american_shepherd.adult_weight.akc.source_id'))
            ->and(masRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(masData('miniature_american_shepherd.adult_weight.akc.quote'))
            ->and(masRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(masData('miniature_american_shepherd.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not ranked by Coren (S35) → no rank, learning 1.0.
            ->and(masRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(masData('miniature_american_shepherd.trainability.coren_rank.value'))->toBeNull()
            ->and(masRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('miniature_american_shepherd.lifespan.petmd')
            ->and(masRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S269')
            ->and(masRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(masRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(masData('miniature_american_shepherd.lifespan.petmd.quote'));

        // Brushing 2× a week (PetMD "a couple times a week"; AKC weekly out of season = alternative).
        expect(masRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(2)
            ->and(masRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S269')
            ->and(masRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(masData('miniature_american_shepherd.suitability.petmd_grooming.quote'))
            ->and(masRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(masData('proposed_game_parameters.miniature_american_shepherd.grooming.quote'))
            ->and(masRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own AKC quote.
            ->and(masRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(masRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S267');
        // No digestion warning (no source quotes a gap between eating and exercise).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior
        // boundary / senior arrival age (108 — Claude's suggestion, no runbook rule produced a value).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_MINIATURE_AMERICAN_SHEPHERD_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.miniature_american_shepherd.senior_boundary_months')
                ->and($row['value'])->toBe(masData('proposed_game_parameters.miniature_american_shepherd.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own AKC quote (S267).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('miniature-american-shepherd'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = masPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 108-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, later than the Australian Shepherd (90)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('miniature-american-shepherd', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('miniature-american-shepherd', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('australian-shepherd', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = masPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'miniature_american_shepherd', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = masPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(masLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::MiniatureAmericanShepherd)
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
        foreach (['border_collie', 'miniature_american_shepherd'] as $breed) {
            $pin = masPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(masLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['miniature_american_shepherd'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Miniature American Shepherd appearance data.
            ->and($dog->pet_dna['breed'])->toBe('miniature_american_shepherd')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = masPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Miniature American Shepherd after the Boston Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd'])
            ->and($breeds[28])->toBe([
                'breed' => 'miniature_american_shepherd', 'slug' => 'miniature-american-shepherd', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.miniature_american_shepherd',
                'search_keywords' => BreedConfigsSeeder::configs()[28]['search_keywords'], 'sort_order' => 280,
                'suitability' => [
                    'suits' => ['family_pet', 'children', 'other_pets'],
                    'consider' => ['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — merle is standard for this breed)', function () {
    it('samples Miniature American Shepherd DNA inside the standards (black, blue merle, red, red merle; natural tail; folding ears)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('miniature_american_shepherd')
            ->and(PetDnaService::hasAppearance('miniature_american_shepherd'))->toBeTrue()
            ->and(config('breed_appearance.miniature_american_shepherd.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('miniature_american_shepherd', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_pattern'])->toContain('eyes fully surrounded by colour')
                ->and($traits['ear_carriage'])->toContain('folding')
                ->and($traits['tail'])->toContain('undocked');

            $prompt = $prompts->imagePrompt('miniature_american_shepherd', $traits);
            expect($prompt)->toContain('Miniature American Shepherd')
                ->not->toMatch('/\b(prick ears|docked tail|bobtail|white body|predominantly white)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black', 'blue merle', 'red', 'red merle']);
    });

    it('draws the register portrait blue merle with a natural tail', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::MiniatureAmericanShepherd);

        expect($traits['coat_color'])->toBe('blue merle')
            ->and($traits['tail'])->toContain('undocked')
            ->and($portrait->prompt(BreedType::MiniatureAmericanShepherd))->toContain('Miniature American Shepherd')->toContain('blue merle')
            ->and(BreedPortraitService::relativeFile(BreedType::MiniatureAmericanShepherd))->toBe('dog/miniature-american-shepherd.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::MiniatureAmericanShepherd);
        expect($v1['prompt_anchor'])->toContain('Miniature American Shepherd')->toContain('blue merle coat')
            ->toContain('natural undocked tail')->not->toMatch('/(?<!un)docked tail/')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['blue merle', 'black', 'red merle'])
            ->and($v1['visual_traits']['eye_color'])->toBeIn(['blue', 'brown']);

        $pet = masPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Miniature American Shepherd')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.miniature_american_shepherd'),
            config('breed_suitability.breeds.miniature_american_shepherd'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-american-shepherd')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::MiniatureAmericanShepherd)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'sheds'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems']))->toBe([])
            ->and(masData('proposed_game_parameters.miniature_american_shepherd.marketing.value'))->toContain('in marketing: yes')
            ->and(masData('miniature_american_shepherd.health.mdr1.source_id'))->toBe('S269');
    });
});
