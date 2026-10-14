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
| M5-R10-34 — English Springer Spaniel (appended after the Chihuahua: AKC 2024 No. 28)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `english_springer_spaniel` (S281–S288) and the
| runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.english_springer_spaniel` (exercise 120 min — RKC "More than 2 hours per
| day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 122 — 0.75 × McMillan 2024
| median 13.5 y —, arrival 2 / 9 / 36 / 122, learning × 1.5 (Coren rank 13, Excellent), paid breed,
| Border Collie care rates, brushing 2× a week (RKC "More than once a week" → PDSA "once or twice a
| week", M5-R13), puppy socialisation with the AKC quote (M5-R17), no meal / walk warning (no source);
| suitability: suits active_family / family_pet / children / other_pets, consider long_daily_exercise /
| needs_company / sheds / frequent_grooming; liver and white (portrait), natural tail; not a welfare
| breed → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function essData(string $path): mixed
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

/** @return array<string, mixed> the English Springer Spaniel seeder row of (stage, from, key) */
function essRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'english-springer-spaniel'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("english-springer-spaniel.{$stage}.{$from}.{$key->value}");

    return $row;
}

function essPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->englishSpringerSpaniel()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function essPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function essLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::EnglishSpringerSpaniel;
        $config = BreedConfig::where('breed_slug', 'english-springer-spaniel')->sole();

        expect($breed->value)->toBe('english_springer_spaniel')
            ->and($breed->slug())->toBe('english-springer-spaniel')
            ->and(BreedType::fromSlug('english-springer-spaniel'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 300, 'breeds.english_springer_spaniel'])
            ->and($config->search_keywords)->toBe(['english springer spaniel', 'angleški springer španjel', 'angleski springer spanjel', 'springer spaniel', 'springer španjel', 'springer'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) essData('proposed_game_parameters.english_springer_spaniel.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = essData('proposed_game_parameters.english_springer_spaniel.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an English Springer Spaniel pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = essPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('english_springer_spaniel');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'welsh_springer_spaniel']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_12_120000_add_english_springer_spaniel_breed.php');
        // down() refuses while an English Springer Spaniel exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'english_springer_spaniel']))->toThrow(QueryException::class);
        // The Chihuahua (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'chihuahua']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_springer_spaniel']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::EnglishSpringerSpaniel);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'english-springer-spaniel')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')->count());

        BreedConfig::where('breed_slug', 'english-springer-spaniel')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'english-springer-spaniel')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'english-springer-spaniel')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'english-springer-spaniel');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'belgian-malinois'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the English Springer Spaniel's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.english_springer_spaniel';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(essRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(essData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(essRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(essData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(essRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(essData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.5 y (McMillan 2024 via the Dogs Trust table, S123) = 121.5 → 122 (half up).
            ->and(essRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(essData('english_springer_spaniel.lifespan.senior_from.months'))
            ->and(essRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * essRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(essData('english_springer_spaniel.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(essRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(essRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(essRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(122);

        expect(essRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(essData("{$pgp}.exercise_minutes_adult.value"))
            ->and(essRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(essRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(essData('english_springer_spaniel.exercise.adult.quote'))
            ->and(essRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(essRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(essData("{$pgp}.exercise_minutes_senior.value"))
            ->and(essRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(essRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(essData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(essRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(essData("{$pgp}.learning_multiplier.value"))
            ->and(essRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every English Springer Spaniel decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ENGLISH_SPRINGER_SPANIEL) {
                $decided++;
                expect($row['breed_slug'])->toBe('english-springer-spaniel')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.english_springer_spaniel.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) essData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); clipping waits for ROADMAP M5-R18, nail trimming for M5-R19.
        expect(json_encode([essData('english_springer_spaniel'), essData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(essData("{$pgp}.grooming.value"))->toBe(2)
            ->and(essData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(essData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(essData("{$pgp}.nail_trimming.decision"))->toContain('ROADMAP M5-R19')
            ->and(essData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['english_springer_spaniel'][1])
            ->and(essData('english_springer_spaniel.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['english_springer_spaniel'][1])
            // No source quotes a gap after meals → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('english_springer_spaniel');
    });

    it('takes the breed-level numbers from the sourced english_springer_spaniel entries', function () {
        // PDSA "Average weight | 18-23 kg" (S285); AKC standard about 50 lb / 40 lb (S283) inside it.
        expect(essRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([18, 23])
            ->and(essData('english_springer_spaniel.adult_weight.pdsa.value'))->toEqual(['male' => [18, 23], 'female' => [18, 23]])
            ->and(essRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(essData('english_springer_spaniel.adult_weight.pdsa.source_id'))
            ->and(essRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(essData('english_springer_spaniel.adult_weight.pdsa.quote'))
            ->and(essRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(essData('english_springer_spaniel.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 13 (S35), Excellent tier → learning 1.5.
            ->and(essRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(13)
            ->and(essData('english_springer_spaniel.trainability.coren_rank.value'))->toBe(13)
            ->and(essRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('english_springer_spaniel.lifespan.median_uk')
            ->and(essRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(essRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.5)
            ->and(essRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(essData('english_springer_spaniel.lifespan.median_uk.quote'));

        // Brushing 2× a week (RKC "More than once a week" has no number → PDSA "once or twice a week" → 2).
        expect(essRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(2)
            ->and(essRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S285')
            ->and(essRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(essData('english_springer_spaniel.suitability.pdsa_grooming.quote'))
            ->and(essRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(essData('proposed_game_parameters.english_springer_spaniel.grooming.quote'))
            ->and(essRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own AKC quote.
            ->and(essRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(essRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S286');
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own AKC quote (S286).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('english-springer-spaniel'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = essPet($arrival);
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
        'young 12 months (reaches the cap)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 121 months' => [121, LifeStage::Adult, 120, 12000],
        'senior 122 months' => [122, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 122 months, like the Yorkshire Terrier', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('english-springer-spaniel', 121))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('english-springer-spaniel', 122))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('yorkshire-terrier', 122))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = essPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'english_springer_spaniel', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = essPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(essLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::EnglishSpringerSpaniel)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 122 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'english_springer_spaniel'] as $breed) {
            $pin = essPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(essLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['english_springer_spaniel'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(122)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the English Springer Spaniel appearance data.
            ->and($dog->pet_dna['breed'])->toBe('english_springer_spaniel')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = essPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the English Springer Spaniel after the Chihuahua with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois'])
            ->and(collect($breeds[30])->except('colours')->all())->toBe([
                'breed' => 'english_springer_spaniel', 'slug' => 'english-springer-spaniel', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.english_springer_spaniel',
                'search_keywords' => BreedConfigsSeeder::configs()[30]['search_keywords'], 'sort_order' => 300,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'children', 'other_pets'],
                    'consider' => ['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: KC standard colours only; natural tail)', function () {
    it('samples English Springer Spaniel DNA inside the KC standard (liver / black and white, ± tan; never roan, lemon, red or orange)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('english_springer_spaniel')
            ->and(PetDnaService::hasAppearance('english_springer_spaniel'))->toBeTrue()
            ->and(config('breed_appearance.english_springer_spaniel.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('english_springer_spaniel', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('feathered')
                ->and($traits['eye_color'])->toContain('dark hazel')
                ->and($traits['tail'])->toContain('undocked');

            $prompt = $prompts->imagePrompt('english_springer_spaniel', $traits);
            expect($prompt)->toContain('English Springer Spaniel')
                ->not->toMatch('/\b(roan|lemon|orange|red|merle|docked tail|bobtail)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black and white', 'black and white with tan markings', 'liver and white', 'liver and white with tan markings']);
    });

    it('draws the register portrait liver and white with a natural tail', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::EnglishSpringerSpaniel);

        expect($traits['coat_color'])->toBe('liver and white')
            ->and($traits['tail'])->toContain('undocked')
            ->and($portrait->prompt(BreedType::EnglishSpringerSpaniel))->toContain('English Springer Spaniel')->toContain('liver and white')
            ->and(BreedPortraitService::relativeFile(BreedType::EnglishSpringerSpaniel))->toBe('dog/english-springer-spaniel.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::EnglishSpringerSpaniel);
        expect($v1['prompt_anchor'])->toContain('English Springer Spaniel')->toContain('liver and white coat')
            ->toContain('undocked')->not->toContain('roan')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['liver and white', 'black and white', 'liver and white with tan markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark hazel');

        $pet = essPet(122, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('English Springer Spaniel')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.english_springer_spaniel'),
            config('breed_suitability.breeds.english_springer_spaniel'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::EnglishSpringerSpaniel)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['long_daily_exercise', 'needs_company', 'sheds', 'frequent_grooming'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(essData('english_springer_spaniel.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(essData('proposed_game_parameters.english_springer_spaniel.marketing.value'))->toContain('in marketing: yes')
            // The Teng 2022 life expectancy (a mean) is research only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'english-springer-spaniel')->pluck('value')->all())->not->toContain(11.9);
    });
});
