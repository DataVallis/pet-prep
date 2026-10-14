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
| M5-R10-35 — Shetland Sheepdog (appended after the Shetland Sheepdog: AKC 2024 No. 29)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `shetland_sheepdog` (S289–S295) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.shetland_sheepdog`
| (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60,
| stages 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until McMillan 2024 is checked —,
| arrival 2 / 9 / 36 / 90, learning × 1.8 (Coren rank 6, Brightest), paid breed, Border Collie care
| rates, brushing every day (RKC "Grooming: Every day", M5-R13), puppy socialisation with the PDSA
| quote (M5-R17), no meal / walk warning (no source); suitability: suits family_pet / children,
| consider needs_mental_stimulation / may_herd_children / frequent_grooming / sheds; sable and white
| (portrait), never brindle or white body patches; Breed Watch Category 2 without a named point → no
| welfare chip, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function shsData(string $path): mixed
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

/** @return array<string, mixed> the Shetland Sheepdog seeder row of (stage, from, key) */
function shsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'shetland-sheepdog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("shetland-sheepdog.{$stage}.{$from}.{$key->value}");

    return $row;
}

function shsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->shetlandSheepdog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function shsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function shsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ShetlandSheepdog;
        $config = BreedConfig::where('breed_slug', 'shetland-sheepdog')->sole();

        expect($breed->value)->toBe('shetland_sheepdog')
            ->and($breed->slug())->toBe('shetland-sheepdog')
            ->and(BreedType::fromSlug('shetland-sheepdog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 310, 'breeds.shetland_sheepdog'])
            ->and($config->search_keywords)->toBe(['shetland sheepdog', 'šetlandski ovčar', 'setlandski ovcar', 'sheltie', 'šeltie', 'shetland'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) shsData('proposed_game_parameters.shetland_sheepdog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = shsData('proposed_game_parameters.shetland_sheepdog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Shetland Sheepdog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = shsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('shetland_sheepdog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'rough_collie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_13_120000_add_shetland_sheepdog_breed.php');
        // down() refuses while an Shetland Sheepdog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'shetland_sheepdog']))->toThrow(QueryException::class);
        // The English Springer Spaniel (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_springer_spaniel']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shetland_sheepdog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ShetlandSheepdog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'shetland-sheepdog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')->count());

        BreedConfig::where('breed_slug', 'shetland-sheepdog')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'shetland-sheepdog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'shetland-sheepdog')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'shetland-sheepdog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'brittany', 'american-cocker-spaniel', 'belgian-malinois'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SHETLAND_SHEEPDOG)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Shetland Sheepdog's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_SHETLAND_SHEEPDOG');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.shetland_sheepdog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(shsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(shsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(shsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(shsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(shsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(shsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S291) = 90 — provisional until McMillan 2024 is checked.
            ->and(shsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(shsData('shetland_sheepdog.lifespan.senior_from.months'))
            ->and(shsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * shsRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(shsData('shetland_sheepdog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S291'])
            ->and(shsRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S291')
            ->and(shsRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(shsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(shsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(shsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(shsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(shsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(shsData('shetland_sheepdog.exercise.adult.quote'))
            ->and(shsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(shsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(shsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(shsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(shsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(shsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(shsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(shsData("{$pgp}.learning_multiplier.value"))
            ->and(shsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.8);

        // Every Shetland Sheepdog decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SHETLAND_SHEEPDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('shetland-sheepdog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.shetland_sheepdog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) shsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); nail trimming waits for ROADMAP M5-R19.
        expect(json_encode([shsData('shetland_sheepdog'), shsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(shsData("{$pgp}.grooming.value"))->toBe(7)
            ->and(shsData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(shsData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(shsData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shetland_sheepdog'][1])
            ->and(shsData('shetland_sheepdog.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shetland_sheepdog'][1])
            // No source quotes a gap after meals → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('shetland_sheepdog');
    });

    it('takes the breed-level numbers from the sourced shetland_sheepdog entries', function () {
        // AKC 15–25 lb (S294) ≈ 6.8–11.3 kg; PDSA average 9 kg (S293) inside it.
        expect(shsRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6.8, 11.3])
            ->and(shsData('shetland_sheepdog.adult_weight.akc.value'))->toEqual(['male' => [6.8, 11.3], 'female' => [6.8, 11.3]])
            ->and(shsData('shetland_sheepdog.adult_weight.pdsa.value'))->toEqual(['male' => [9, 9], 'female' => [9, 9]])
            ->and(shsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(shsData('shetland_sheepdog.adult_weight.akc.source_id'))
            ->and(shsRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(shsData('shetland_sheepdog.adult_weight.akc.quote'))
            ->and(shsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(shsData('shetland_sheepdog.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 6 (S35; S34 the same), Brightest tier → learning 1.8.
            ->and(shsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(6)
            ->and(shsData('shetland_sheepdog.trainability.coren_rank.value'))->toBe(6)
            ->and(shsRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('shetland_sheepdog.lifespan.rkc')
            ->and(shsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S291')
            ->and(shsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(shsRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(shsData('shetland_sheepdog.lifespan.rkc.quote'));

        // Brushing every day (RKC "Grooming: Every day" → 7; PDSA "daily grooming" agrees).
        expect(shsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(7)
            ->and(shsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S291')
            ->and(shsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(shsData('shetland_sheepdog.suitability.rkc_grooming.quote'))
            ->and(shsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(shsData('proposed_game_parameters.shetland_sheepdog.grooming.quote'))
            ->and(shsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(shsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(shsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S293');
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PDSA quote (S293).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('shetland-sheepdog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = shsPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Boston Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('shetland-sheepdog', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('shetland-sheepdog', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('boston-terrier', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Brightest tier, × 1.8) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = shsPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.8);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'shetland_sheepdog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = shsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(shsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ShetlandSheepdog)
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
        foreach (['border_collie', 'shetland_sheepdog'] as $breed) {
            $pin = shsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(shsLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['shetland_sheepdog'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Shetland Sheepdog appearance data.
            ->and($dog->pet_dna['breed'])->toBe('shetland_sheepdog')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = shsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Shetland Sheepdog after the English Springer Spaniel with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois'])
            ->and($breeds[31])->toBe([
                'breed' => 'shetland_sheepdog', 'slug' => 'shetland-sheepdog', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.shetland_sheepdog',
                'search_keywords' => BreedConfigsSeeder::configs()[31]['search_keywords'], 'sort_order' => 310,
                'suitability' => [
                    'suits' => ['family_pet', 'children'],
                    'consider' => ['needs_mental_stimulation', 'may_herd_children', 'frequent_grooming', 'sheds'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: FCI standard colours only)', function () {
    it('samples Shetland Sheepdog DNA inside the FCI standard (sable, tricolour, blue merle, black and white, black and tan; never brindle)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('shetland_sheepdog')
            ->and(PetDnaService::hasAppearance('shetland_sheepdog'))->toBeTrue()
            ->and(config('breed_appearance.shetland_sheepdog.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('shetland_sheepdog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('semi-erect')
                ->and($traits['eye_color'])->toContain('dark brown')
                ->and($traits['muzzle'])->toContain('wedge');

            $prompt = $prompts->imagePrompt('shetland_sheepdog', $traits);
            expect($prompt)->toContain('Shetland Sheepdog')
                ->not->toMatch('/\b(brindle|double merle|docked tail|bobtail|smooth coat)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black and tan', 'black and white', 'blue merle (silvery blue marbled with black, with white and tan markings)', 'sable and white (golden sable with a white collar, frill, chest, legs and tail tip)', 'tricolour (black with tan markings and a white collar, frill, chest, legs and tail tip)']);
    });

    it('draws the register portrait sable and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ShetlandSheepdog);

        expect($traits['coat_color'])->toStartWith('sable and white')
            ->and($traits['tail'])->toContain('natural')
            ->and($portrait->prompt(BreedType::ShetlandSheepdog))->toContain('Shetland Sheepdog')->toContain('sable and white')
            ->and(BreedPortraitService::relativeFile(BreedType::ShetlandSheepdog))->toBe('dog/shetland-sheepdog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ShetlandSheepdog);
        expect($v1['prompt_anchor'])->toContain('Shetland Sheepdog')->toContain('sable and white coat')
            ->not->toContain('brindle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['sable and white', 'tricolour (black, tan and white)', 'blue merle and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = shsPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Shetland Sheepdog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.shetland_sheepdog'),
            config('breed_suitability.breeds.shetland_sheepdog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::ShetlandSheepdog)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['needs_mental_stimulation', 'may_herd_children', 'frequent_grooming', 'sheds'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(shsData('shetland_sheepdog.suitability.rkc_breed_watch.value'))->toBe('Category 2')
            ->and(shsData('proposed_game_parameters.shetland_sheepdog.marketing.value'))->toContain('in marketing: yes')
            // The McMillan 2024 figure quoted by Wikipedia (tier C) is research only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shetland-sheepdog')->pluck('value')->all())->not->toContain(13.4);
    });
});
