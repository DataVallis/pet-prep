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
| M5-R10-40 — Hungarian Vizsla (appended after the Basset Hound: AKC 2024 No. 35 "Vizsla")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `hungarian_vizsla` (S326–S334) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.hungarian_vizsla`
| (exercise 120 min — RKC "More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120,
| stages 9 / 36 / 108 — 0.75 × RKC lower bound 12 y, provisional until McMillan 2024 is checked —, arrival
| 2 / 9 / 36 / 108, learning × 1.5 (Coren rank 25, Excellent), paid breed, Border Collie care rates,
| brushing 1× a week (RKC "Once a week", M5-R13), the "wait with the walk" warning after meals (PDSA, M5-R16),
| puppy socialisation with the PDSA quote (M5-R17); suitability: suits active_family / family_pet /
| large_home, consider long_daily_exercise / experienced_owner / not_for_small_children / needs_company /
| sheds / chases_small_pets; russet gold (portrait) or dark sandy gold, natural tail, self-coloured nose;
| not a welfare breed (RKC Breed Watch Category 1) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function hvData(string $path): mixed
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

/** @return array<string, mixed> the Hungarian Vizsla seeder row of (stage, from, key) */
function hvRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'hungarian-vizsla'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("hungarian_vizsla.{$stage}.{$from}.{$key->value}");

    return $row;
}

function hvPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->hungarianVizsla()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function hvPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function hvLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::HungarianVizsla;
        $config = BreedConfig::where('breed_slug', 'hungarian-vizsla')->sole();

        expect($breed->value)->toBe('hungarian_vizsla')
            ->and($breed->slug())->toBe('hungarian-vizsla')
            ->and(BreedType::fromSlug('hungarian-vizsla'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 360, 'breeds.hungarian_vizsla'])
            ->and($config->search_keywords)->toBe(['hungarian vizsla', 'vizsla', 'madžarski kratkodlaki ptičar', 'madzarski kratkodlaki pticar', 'vižla', 'vizla', 'madžarska vižla', 'madzarska vizla', 'magyar vizsla', 'hungarian pointer'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) hvData('proposed_game_parameters.hungarian_vizsla.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = hvData('proposed_game_parameters.hungarian_vizsla.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Hungarian Vizsla pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = hvPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('hungarian_vizsla');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // The Hungarian Wirehaired Vizsla (FCI 239) is another breed — not in the app.
            ->and($refused(['breed_type' => 'hungarian_wirehaired_vizsla']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_18_120000_add_hungarian_vizsla_breed.php');
        // down() refuses while a Hungarian Vizsla exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'hungarian_vizsla']))->toThrow(QueryException::class);
        // The Basset Hound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'basset_hound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'hungarian_vizsla']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::HungarianVizsla);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'hungarian-vizsla')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')->count());

        BreedConfig::where('breed_slug', 'hungarian-vizsla')->sole()->update(['daily_steps_required' => 11000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'hungarian-vizsla')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'hungarian-vizsla')->value('daily_steps_required'))->toBe(11000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'hungarian-vizsla');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'belgian-malinois', 'basset-hound'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_HUNGARIAN_VIZSLA)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Hungarian Vizsla's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_HUNGARIAN_VIZSLA');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.hungarian_vizsla';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(hvRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(hvData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(hvRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(hvData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(hvRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(hvData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S328) = 108 — provisional until McMillan 2024 is checked.
            ->and(hvRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(hvData('hungarian_vizsla.lifespan.senior_from.months'))
            ->and(hvRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * hvRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(hvData('hungarian_vizsla.lifespan.senior_from.derived_from'))->toBe(['S11', 'S328'])
            ->and(hvRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S328')
            ->and(hvRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(hvRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(hvRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(hvData("{$pgp}.exercise_minutes_adult.value"))
            ->and(hvRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(hvRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(hvData('hungarian_vizsla.exercise.adult.quote'))
            ->and(hvRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(hvRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(hvData("{$pgp}.exercise_minutes_senior.value"))
            ->and(hvRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(hvRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(hvData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(hvRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(hvData("{$pgp}.learning_multiplier.value"))
            ->and(hvRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Hungarian Vizsla decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_HUNGARIAN_VIZSLA) {
                $decided++;
                expect($row['breed_slug'])->toBe('hungarian-vizsla')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.hungarian_vizsla.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) hvData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, the meal / walk warning M5-R16, socialisation M5-R17); nail care waits for
        // ROADMAP M5-R19, ear care for M5-R20, tooth care for M5-R15.
        expect(json_encode([hvData('hungarian_vizsla'), hvData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(hvData("{$pgp}.grooming.value"))->toBe(1)
            ->and(hvData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(hvData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(hvData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(hvData("{$pgp}.dental_care.decision"))->toContain('ROADMAP M5-R15')
            ->and(hvData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['hungarian_vizsla'][1])
            ->and(hvData('hungarian_vizsla.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['hungarian_vizsla'][1])
            // PDSA "Remember to leave a gap between eating and exercising." → the digestion warning.
            ->and(hvData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['hungarian_vizsla'][1])
            ->and(hvData('hungarian_vizsla.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['hungarian_vizsla'][1]);
    });

    it('takes the breed-level numbers from the sourced hungarian_vizsla entries', function () {
        // PDSA "Average weight" 20kg-30kg (S329), one range for both sexes; the FCI and AKC standards give no weight.
        expect(hvRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.0, 30.0])
            ->and(hvData('hungarian_vizsla.adult_weight.pdsa.value'))->toEqual(['male' => [20, 30], 'female' => [20, 30]])
            ->and(hvRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(hvData('hungarian_vizsla.adult_weight.pdsa.source_id'))
            ->and(hvRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(hvData('hungarian_vizsla.adult_weight.pdsa.quote'))
            ->and(hvRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(hvData('hungarian_vizsla.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 25 (S35), Excellent tier → learning 1.5.
            ->and(hvRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(25)
            ->and(hvRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(hvData('hungarian_vizsla.trainability.coren_rank.quote'))
            ->and(hvData('hungarian_vizsla.trainability.coren_rank.value'))->toBe(25)
            ->and(hvRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('hungarian_vizsla.lifespan.rkc')
            ->and(hvRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S328')
            ->and(hvRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(hvRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(hvData('hungarian_vizsla.lifespan.rkc.quote'));

        // Brushing 1× a week (RKC "Once a week"; PDSA and PetMD weekly) — the rule, verified.
        $grooming = hvRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S328')
            ->and($grooming['quote'])->toBe(hvData('hungarian_vizsla.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(hvData('proposed_game_parameters.hungarian_vizsla.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(hvRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(hvRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S329');
        // The digestion warning (M5-R16): 60 minutes after a meal (PDSA "leave a gap between eating and exercising").
        $gap = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap)->toHaveCount(1)
            ->and($gap[0]['value'])->toBe(60)
            ->and($gap[0]['source_id'])->toBe('S329')
            ->and($gap[0]['verified'])->toBeTrue()
            ->and($gap[0]['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA S329; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S329).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('hungarian-vizsla'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = hvPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 107 months' => [107, LifeStage::Adult, 120, 12000],
        'senior 108 months' => [108, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 108 months, like the Pomeranian', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('hungarian-vizsla', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('hungarian-vizsla', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pomeranian', 108))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = hvPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'hungarian_vizsla', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = hvPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(hvLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::HungarianVizsla)
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
        foreach (['border_collie', 'hungarian_vizsla'] as $breed) {
            $pin = hvPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(hvLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['hungarian_vizsla'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Hungarian Vizsla appearance data.
            ->and($dog->pet_dna['breed'])->toBe('hungarian_vizsla')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = hvPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Hungarian Vizsla after the Basset Hound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois', 'basset_hound', 'hungarian_vizsla'])
            ->and(collect($breeds[36])->except('colours')->all())->toBe([
                'breed' => 'hungarian_vizsla', 'slug' => 'hungarian-vizsla', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.hungarian_vizsla',
                'search_keywords' => BreedConfigsSeeder::configs()[36]['search_keywords'], 'sort_order' => 360,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural tail; self-coloured nose)', function () {
    it('samples Hungarian Vizsla DNA inside the FCI colours (russet gold or dark sandy gold) with a natural tail and a self-coloured nose', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('hungarian_vizsla')
            ->and(PetDnaService::hasAppearance('hungarian_vizsla'))->toBeTrue()
            ->and(config('breed_appearance.hungarian_vizsla.verified'))->toBeFalse()
            ->and(config('breed_appearance.hungarian_vizsla.prompt_order'))->toContain('nose');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('hungarian_vizsla', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toMatch('/gold/')
                ->and($traits['tail'])->toContain('undocked')
                ->and($traits['nose'])->toContain('self-coloured');

            $prompt = $prompts->imagePrompt('hungarian_vizsla', $traits);
            expect($prompt)->toContain('Hungarian Vizsla')
                ->not->toMatch('/\b(merle|brindle|cropped|docked tail|pale yellow|dark brown coat|white feet|parti-colou?red)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['dark sandy gold', 'russet gold']);
    });

    it('draws the register portrait russet gold', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::HungarianVizsla);

        expect($traits['coat_color'])->toBe('russet gold')
            ->and($traits['tail'])->toContain('undocked')
            ->and($portrait->prompt(BreedType::HungarianVizsla))->toContain('Hungarian Vizsla')->toContain('russet gold')
            ->and(BreedPortraitService::relativeFile(BreedType::HungarianVizsla))->toBe('dog/hungarian-vizsla.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::HungarianVizsla);
        expect($v1['prompt_anchor'])->toContain('Hungarian Vizsla')->toContain('russet gold')->toContain('self-coloured')->toContain('undocked')
            ->not->toContain('black nose')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['russet gold', 'golden rust', 'dark sandy gold'])
            ->and($v1['visual_traits']['eye_color'])->toBe('brown, in harmony with the coat');

        $pet = hvPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Hungarian Vizsla')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.hungarian_vizsla'),
            config('breed_suitability.breeds.hungarian_vizsla'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::HungarianVizsla);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'needs_company', 'sheds', 'chases_small_pets'])
            // Breed Watch Category 1: no welfare chip; RKC "Over 12 years"; older children only, prey drive.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin']))->toBe([])
            ->and(array_intersect($tags['suits'], ['children', 'small_children', 'other_pets', 'first_time_owner', 'apartment']))->toBe([])
            ->and(hvData('hungarian_vizsla.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(hvData('proposed_game_parameters.hungarian_vizsla.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (13.5 y, tier C) is research only — never a seeded value (senior 122 is an alternative).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'hungarian-vizsla')->pluck('value')->all())->not->toContain(13.5)->not->toContain(122);
    });
});
