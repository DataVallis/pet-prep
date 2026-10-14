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
| M5-R10-28 — Shih Tzu (appended after the Miniature Schnauzer: AKC 2024 No. 20)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `shih_tzu` (S229–S236)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.shih_tzu` (exercise 60 min, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 114 — senior from the VetCompass median
| 12.7 y, S234, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 114,
| learning × 0.5, paid breed, Border Collie care rates, brushing every day (M5-R13),
| 60-minute meal / walk warning (M5-R16), puppy socialisation with the PDSA quote (M5-R17),
| suitability: suits apartment / family_pet / first_time_owner (no shedding chip — RKC and PDSA disagree), consider
| frequent_grooming / short_muzzle_breathing; gold and white (portrait), black and white or
| liver and white — never merle —, moderate muzzle with open nostrils; brachycephalic
| welfare breed (BVA) → NOT in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function stData(string $path): mixed
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

/** @return array<string, mixed> the Shih Tzu seeder row of (stage, from, key) */
function stRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'shih-tzu'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("shih_tzu.{$stage}.{$from}.{$key->value}");

    return $row;
}

function stPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->shihTzu()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function stPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function stLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ShihTzu;
        $config = BreedConfig::where('breed_slug', 'shih-tzu')->sole();

        expect($breed->value)->toBe('shih_tzu')
            ->and($breed->slug())->toBe('shih-tzu')
            ->and(BreedType::fromSlug('shih-tzu'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 240, 'breeds.shih_tzu'])
            ->and($config->search_keywords)->toBe(['shih tzu', 'shih-tzu', 'shihtzu', 'ši-cu', 'šicu', 'ši cu', 'sicu'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) stData('proposed_game_parameters.shih_tzu.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = stData('proposed_game_parameters.shih_tzu.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Shih Tzu pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = stPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('shih_tzu');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'si_cu']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_05_120000_add_shih_tzu_breed.php');
        // down() refuses while a Shih Tzu exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'shih_tzu']))->toThrow(QueryException::class);
        // The Miniature Schnauzer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'miniature_schnauzer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shih_tzu']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ShihTzu);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'shih-tzu')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shih-tzu')->count());

        BreedConfig::where('breed_slug', 'shih-tzu')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'shih-tzu')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'shih-tzu')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'shih-tzu');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shih-tzu');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SHIH_TZU)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Shih Tzu's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_SHIH_TZU');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.shih_tzu';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(stRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(stData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(stRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(stRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12.7 y (VetCompass median, S234) = 114.3 → 114 (provisional until McMillan 2024 is checked).
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stData('shih_tzu.lifespan.senior_from.months'))
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * stRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(stData('shih_tzu.lifespan.senior_from.derived_from'))->toBe(['S11', 'S234'])
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(114);

        expect(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stData("{$pgp}.exercise_minutes_adult.value"))
            ->and(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(stRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(stRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stData("{$pgp}.exercise_minutes_senior.value"))
            ->and(stRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(stRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(stData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(stRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(stData("{$pgp}.learning_multiplier.value"))
            ->and(stRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every Shih Tzu decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SHIH_TZU) {
                $decided++;
                expect($row['breed_slug'])->toBe('shih-tzu')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.shih_tzu.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) stData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs point at their ROADMAP tasks (brushing M5-R13, meal gap M5-R16 and socialisation M5-R17
        // are built; face care is ROADMAP M5-R14 and tooth brushing ROADMAP M5-R15, not built — needs David).
        expect(json_encode([stData('shih_tzu'), stData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(stData("{$pgp}.grooming.value"))->toBe(7)
            ->and(stData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(stData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(stData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['shih_tzu'][1])
            ->and(stData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shih_tzu'][1])
            ->and(stData('shih_tzu.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['shih_tzu'][1])
            ->and(stData("{$pgp}.face_care.decision"))->toContain('ROADMAP M5-R14')
            ->and(stData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15');
    });

    it('takes the breed-level numbers from the sourced shih_tzu entries', function () {
        // FCI 208 "4.5 to 8 kg" for dogs and bitches (S229); PDSA 4.5–7.5 kg (S233) inside it.
        expect(stRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.5, 8.0])
            ->and(stData('shih_tzu.adult_weight.fci.value'))->toEqual(['male' => [4.5, 8], 'female' => [4.5, 8]])
            ->and(stData('shih_tzu.adult_weight.pdsa.value'))->toEqual(['male' => [4.5, 7.5], 'female' => [4.5, 7.5]])
            ->and(stRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(stData('shih_tzu.adult_weight.fci.source_id'))
            ->and(stRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(stData('shih_tzu.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(stRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(stData('shih_tzu.trainability.coren_rank.value'))
            ->and(stRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(70)
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('shih_tzu.lifespan.median_uk')
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S234')
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.7)
            ->and(stData('shih_tzu.lifespan.median_uk.quote'))->toContain('was 12.7 years')
            ->and(stData('shih_tzu.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Brushing every day (RKC "Grooming: Every day" → 7), sourced and confirmed by the runbook rule.
        expect(stRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(7)
            ->and(stRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S231')
            ->and(stRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The digestion warning (M5-R16): 60 min, PDSA's verbatim advice.
            ->and(stRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(stRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S233')
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PDSA quote.
            ->and(stRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(stRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S233');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shih-tzu')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shih-tzu')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: the digestion warning and the puppy's weekly "new experience" with the
            // breed's own PDSA quotes (S233).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('shih-tzu'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = stPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (10 min × age)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 113 months' => [113, LifeStage::Adult, 60, 6000],
        'senior 114 months' => [114, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 114 months, after the Dachshund (108) and before the West Highland White Terrier (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('shih-tzu', 113))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('shih-tzu', 114))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('dachshund', 113))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('west-highland-white-terrier', 114))->toBe(LifeStage::Adult);
    });

    it('learns at 0.5 × the mixed-breed speed (Coren Lowest tier, rank 70) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = stPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'shih_tzu', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = stPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(stLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ShihTzu)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 114 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'shih_tzu'] as $breed) {
            $pin = stPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(stLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['shih_tzu'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(114)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Shih Tzu appearance data.
            ->and($dog->pet_dna['breed'])->toBe('shih_tzu')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = stPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Shih Tzu after the Miniature Schnauzer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel'])
            ->and($breeds[24])->toBe([
                'breed' => 'shih_tzu', 'slug' => 'shih-tzu', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.shih_tzu',
                'search_keywords' => BreedConfigsSeeder::configs()[24]['search_keywords'], 'sort_order' => 240,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'first_time_owner'],
                    'consider' => ['frequent_grooming', 'short_muzzle_breathing'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — never merle; welfare breed → moderate face)', function () {
    it('samples Shih Tzu DNA inside the standards (gold and white, black and white or liver and white; never merle; moderate muzzle)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('shih_tzu')
            ->and(PetDnaService::hasAppearance('shih_tzu'))->toBeTrue()
            ->and(config('breed_appearance.shih_tzu.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('shih_tzu', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('open nostrils')
                ->and($traits['coat_length'])->toContain('long')
                ->and($traits['tail'])->toContain('plumed');

            $prompt = $prompts->imagePrompt('shih_tzu', $traits);
            expect($prompt)->toContain('Shih Tzu')
                ->not->toMatch('/\b(merle|brindle)\b/i')
                ->not->toMatch('/\b(flat face|flat-faced|pushed-in)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black and white',
            'gold and white',
            'liver and white',
        ]);
    });

    it('draws the register portrait gold and white with a moderate muzzle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ShihTzu);

        expect($traits['coat_color'])->toBe('gold and white')
            ->and($portrait->prompt(BreedType::ShihTzu))->toContain('Shih Tzu')->toContain('gold and white')
            ->toContain('open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::ShihTzu))->toBe('dog/shih-tzu.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ShihTzu);
        expect($v1['prompt_anchor'])->toContain('Shih Tzu')->toContain('gold and white coat')
            ->toContain('open nostrils')->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['gold and white', 'black and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = stPet(114, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Shih Tzu')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; brachycephalic welfare breed, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the short-muzzle chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.shih_tzu'),
            config('breed_suitability.breeds.shih_tzu'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'shih-tzu')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '9.5%', '7.9 kg', '11,082'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.shih_tzu.traits')))->not->toMatch('/\bmerle\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::ShihTzu)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['frequent_grooming', 'short_muzzle_breathing'])
            // No Shih Tzu source names heat → not the flat-face chip (Boxer precedent).
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and(stData('proposed_game_parameters.shih_tzu.marketing.source_id'))->toBe('S92')
            ->and(stData('proposed_game_parameters.shih_tzu.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and(stData('shih_tzu.health.skull_shape.source_id'))->toBe('S236');
    });
});
