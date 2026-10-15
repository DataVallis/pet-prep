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
| M5-R10-38 — Belgian Malinois (appended after the Belgian Malinois: AKC 2024 No. 33)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `belgian_malinois` (S312–S318) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.belgian_malinois`
| (exercise 120 min — RKC "More than 2 hours per day" —, senior 90 min, puppy 10 min × age capped at 120,
| stages 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until McMillan 2024 is checked —, arrival
| 2 / 9 / 36 / 90, learning × 1.5 (Coren rank 22, Excellent), paid breed, Border Collie care rates,
| brushing 2× a week (RKC "More than once a week" without a number, AKC / PetMD "occasional" → Claude's
| proposal, verified=false, open for David, M5-R13), puppy socialisation with the AKC quote (M5-R17), no
| meal / walk warning (no source); suitability: suits active_family / large_home, consider
| long_daily_exercise / experienced_owner / not_for_small_children / may_herd_children / needs_mental_stimulation /
| needs_company / sheds / chases_small_pets; fawn with black overlay and mask (portrait), never black /
| brindle / merle, natural erect ears and full tail; Breed Watch Category 1 → no welfare chip, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bmData(string $path): mixed
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

/** @return array<string, mixed> the Belgian Malinois seeder row of (stage, from, key) */
function bmRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'belgian-malinois'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("belgian_malinois.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bmPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->belgianMalinois()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bmPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bmLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BelgianMalinois;
        $config = BreedConfig::where('breed_slug', 'belgian-malinois')->sole();

        expect($breed->value)->toBe('belgian_malinois')
            ->and($breed->slug())->toBe('belgian-malinois')
            ->and(BreedType::fromSlug('belgian-malinois'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 340, 'breeds.belgian_malinois'])
            ->and($config->search_keywords)->toBe(['belgian malinois', 'belgijski ovčar malinois', 'belgijski ovcar malinois', 'malinois', 'belgian shepherd malinois', 'belgian shepherd dog malinois'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bmData('proposed_game_parameters.belgian_malinois.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bmData('proposed_game_parameters.belgian_malinois.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Belgian Malinois pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bmPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('belgian_malinois');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'belgian_tervuren']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_16_120000_add_belgian_malinois_breed.php');
        // down() refuses while a Belgian Malinois exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'belgian_malinois']))->toThrow(QueryException::class);
        // The American Cocker Spaniel (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'american_cocker_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_malinois']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BelgianMalinois);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'belgian-malinois')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')->count());

        BreedConfig::where('breed_slug', 'belgian-malinois')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'belgian-malinois')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'belgian-malinois')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'belgian-malinois');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'basset-hound'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_MALINOIS)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Belgian Malinois's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BELGIAN_MALINOIS');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.belgian_malinois';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bmRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bmRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bmRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S314) = 90 — provisional until McMillan 2024 is checked.
            ->and(bmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmData('belgian_malinois.lifespan.senior_from.months'))
            ->and(bmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bmRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bmData('belgian_malinois.lifespan.senior_from.derived_from'))->toBe(['S11', 'S314'])
            ->and(bmRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S314')
            ->and(bmRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(bmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(bmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bmData('belgian_malinois.exercise.adult.quote'))
            ->and(bmRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(bmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bmRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bmData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(bmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bmData("{$pgp}.learning_multiplier.value"))
            ->and(bmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Belgian Malinois decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BELGIAN_MALINOIS) {
                $decided++;
                expect($row['breed_slug'])->toBe('belgian-malinois')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.belgian_malinois.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bmData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13 — 2× a week, Claude's proposal for David —, socialisation M5-R17, daily training =
        // the school M5-R03); nail care waits for ROADMAP M5-R19, ear care for M5-R20, tooth care for M5-R15.
        expect(json_encode([bmData('belgian_malinois'), bmData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bmData("{$pgp}.grooming.value"))->toBe(2)
            ->and(bmData("{$pgp}.grooming.decision"))->toContain('M5-R13')->toContain('čaka Davida')
            ->and(bmData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(bmData("{$pgp}.ear_care.decision"))->toContain('ROADMAP M5-R20')
            ->and(bmData("{$pgp}.dental_care.decision"))->toContain('ROADMAP M5-R15')
            ->and(bmData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['belgian_malinois'][1])
            ->and(bmData('belgian_malinois.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['belgian_malinois'][1])
            // No source quotes a gap after meals → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('belgian_malinois');
    });

    it('takes the breed-level numbers from the sourced belgian_malinois entries', function () {
        // FCI standard: males about 25–30 kg, females about 20–25 kg (S313) → 20–30 kg.
        expect(bmRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.0, 30.0])
            ->and(bmData('belgian_malinois.adult_weight.fci.value'))->toEqual(['male' => [25, 30], 'female' => [20, 25]])
            ->and(bmRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bmData('belgian_malinois.adult_weight.fci.source_id'))
            ->and(bmRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bmData('belgian_malinois.adult_weight.fci.quote'))
            ->and(bmRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(bmData('belgian_malinois.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 22 (S35), Excellent tier → learning 1.5.
            ->and(bmRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(22)
            ->and(bmRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(bmData('belgian_malinois.trainability.coren_rank.quote'))
            ->and(bmData('belgian_malinois.trainability.coren_rank.value'))->toBe(22)
            ->and(bmRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('belgian_malinois.lifespan.rkc')
            ->and(bmRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S314')
            ->and(bmRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(bmRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bmData('belgian_malinois.lifespan.rkc.quote'));

        // Brushing 2× a week: RKC "More than once a week" without a number, AKC / PetMD "occasional" →
        // Claude's proposal, verified=false (a new breed still asks David, runbook §3 "Dog grooming").
        $grooming = bmRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S314')
            ->and($grooming['quote'])->toBe(bmData('belgian_malinois.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bmData('proposed_game_parameters.belgian_malinois.grooming.quote'))
            ->and($grooming['verified'])->toBeFalse()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R13_BELGIAN_MALINOIS)
            ->and($grooming['decision'])->toContain('čaka Davida')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own AKC quote.
            ->and(bmRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bmRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S316');
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the brushing number.
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value, 'all.'.StageParamKey::GroomingSessionsPerWeek->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own AKC quote (S316).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('belgian-malinois'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bmPet($arrival);
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
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the American Cocker Spaniel', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('belgian-malinois', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('belgian-malinois', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('american-cocker-spaniel', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bmPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'belgian_malinois', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bmPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bmLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BelgianMalinois)
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
        foreach (['border_collie', 'belgian_malinois'] as $breed) {
            $pin = bmPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bmLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['belgian_malinois'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Belgian Malinois appearance data.
            ->and($dog->pet_dna['breed'])->toBe('belgian_malinois')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bmPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Belgian Malinois after the American Cocker Spaniel with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois', 'basset_hound'])
            ->and(collect($breeds[34])->except('colours')->all())->toBe([
                'breed' => 'belgian_malinois', 'slug' => 'belgian-malinois', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.belgian_malinois',
                'search_keywords' => BreedConfigsSeeder::configs()[34]['search_keywords'], 'sort_order' => 340,
                'suitability' => [
                    'suits' => ['active_family', 'large_home'],
                    'consider' => ['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only)', function () {
    it('samples Belgian Malinois DNA inside the FCI / AKC colours (fawn or mahogany with black overlay and mask; never black, brindle or merle)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('belgian_malinois')
            ->and(PetDnaService::hasAppearance('belgian_malinois'))->toBeTrue()
            ->and(config('breed_appearance.belgian_malinois.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('belgian_malinois', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_color'])->toContain('black overlay')->toContain('black mask')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['eye_color'])->toContain('dark brown')
                ->and($traits['tail'])->toContain('natural full tail')
                ->and($traits['muzzle'])->toContain('black mask');

            $prompt = $prompts->imagePrompt('belgian_malinois', $traits);
            expect($prompt)->toContain('Belgian Malinois')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|white patches|blue eyes)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['fawn with black overlay (black-tipped hairs) and a pronounced black mask', 'mahogany with black overlay (black-tipped hairs) and a pronounced black mask']);
    });

    it('draws the register portrait fawn with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BelgianMalinois);

        expect($traits['coat_color'])->toStartWith('fawn')
            ->and($traits['tail'])->toContain('natural full tail')
            ->and($portrait->prompt(BreedType::BelgianMalinois))->toContain('Belgian Malinois')->toContain('fawn')->toContain('black mask')
            ->and(BreedPortraitService::relativeFile(BreedType::BelgianMalinois))->toBe('dog/belgian-malinois.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BelgianMalinois);
        expect($v1['prompt_anchor'])->toContain('Belgian Malinois')->toContain('fawn coat with black overlay')->toContain('black mask')->toContain('natural full tail')
            ->not->toContain('merle')->not->toContain('brindle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with black overlay', 'rich fawn with black overlay', 'mahogany with black overlay'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = bmPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Belgian Malinois')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 1), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.belgian_malinois'),
            config('breed_suitability.breeds.belgian_malinois'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::BelgianMalinois)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['long_daily_exercise', 'experienced_owner', 'not_for_small_children', 'may_herd_children', 'needs_mental_stimulation', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(bmData('belgian_malinois.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(bmData('proposed_game_parameters.belgian_malinois.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (12 y, tier C) is research only — never a seeded value (senior 108 is an alternative).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'belgian-malinois')->pluck('value')->all())->not->toContain(12.0)->not->toContain(108);
    });
});
