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
| M5-R10-27 — Miniature Schnauzer (appended after the Boxer: AKC 2024 No. 18)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `miniature_schnauzer` (S221–S228)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.miniature_schnauzer` (exercise 60 min, senior 45 min, puppy
| 10 min × age capped at 60, stages 9 / 36 / 104 — senior from the VetCompass median
| 11.6 y, S227, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 104,
| learning × 1.5, paid breed, Border Collie care rates, brushing 3× a week (M5-R13),
| suitability: suits apartment / children / low_shedding, consider frequent_grooming;
| pepper and salt (portrait), black and silver or black — never white —, natural ears
| and tail; not a welfare breed → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function msData(string $path): mixed
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

/** @return array<string, mixed> the Miniature Schnauzer seeder row of (stage, from, key) */
function msRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'miniature-schnauzer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("miniature_schnauzer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function msPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->miniatureSchnauzer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function msPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function msLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::MiniatureSchnauzer;
        $config = BreedConfig::where('breed_slug', 'miniature-schnauzer')->sole();

        expect($breed->value)->toBe('miniature_schnauzer')
            ->and($breed->slug())->toBe('miniature-schnauzer')
            ->and(BreedType::fromSlug('miniature-schnauzer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 230, 'breeds.miniature_schnauzer'])
            ->and($config->search_keywords)->toBe(['miniature schnauzer', 'pritlikavi šnavcer', 'pritlikavi snavcer', 'mali šnavcer', 'zwergschnauzer', 'schnauzer', 'šnavcer', 'mini schnauzer'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) msData('proposed_game_parameters.miniature_schnauzer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = msData('proposed_game_parameters.miniature_schnauzer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Miniature Schnauzer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = msPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('miniature_schnauzer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'pritlikavi_snavcer']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_03_120000_add_miniature_schnauzer_breed.php');
        // down() refuses while a Miniature Schnauzer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'miniature_schnauzer']))->toThrow(QueryException::class);
        // The Boxer (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'boxer']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'miniature_schnauzer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::MiniatureSchnauzer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'miniature-schnauzer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-schnauzer')->count());

        BreedConfig::where('breed_slug', 'miniature-schnauzer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'miniature-schnauzer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'miniature-schnauzer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'miniature-schnauzer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-schnauzer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_MINIATURE_SCHNAUZER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Miniature Schnauzer's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_MINIATURE_SCHNAUZER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.miniature_schnauzer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(msRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(msData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(msRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(msData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(msRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(msData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 11.6 y (VetCompass median, S227) = 104.4 → 104 (provisional until McMillan 2024 is checked).
            ->and(msRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(msData('miniature_schnauzer.lifespan.senior_from.months'))
            ->and(msRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * msRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(msData('miniature_schnauzer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S227'])
            ->and(msRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(104);

        expect(msRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(msData("{$pgp}.exercise_minutes_adult.value"))
            ->and(msRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(msRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(msRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(msData("{$pgp}.exercise_minutes_senior.value"))
            ->and(msRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(msRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(msData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(msRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(msData("{$pgp}.learning_multiplier.value"))
            ->and(msRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Miniature Schnauzer decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_MINIATURE_SCHNAUZER) {
                $decided++;
                expect($row['breed_slug'])->toBe('miniature-schnauzer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.miniature_schnauzer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) msData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs point at their ROADMAP tasks (brushing M5-R13 is built; dental care is ROADMAP M5-R15,
        // not built — needs David). No socialisation quote and no meal-gap quote → no own rows.
        expect(json_encode([msData('miniature_schnauzer'), msData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(msData("{$pgp}.grooming.value"))->toBe(3)
            ->and(msData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(msData("{$pgp}.tooth_brushing.decision"))->toContain('ROADMAP M5-R15')
            ->and(msData($pgp))->not->toHaveKey('meal_exercise_gap')->not->toHaveKey('socialisation');
    });

    it('takes the breed-level numbers from the sourced miniature_schnauzer entries', function () {
        // FCI 183 "approximately 4 to 8 kg" for dogs and bitches (S221); PDSA 6–7 kg (S226) inside it.
        expect(msRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.0, 8.0])
            ->and(msData('miniature_schnauzer.adult_weight.fci.value'))->toEqual(['male' => [4, 8], 'female' => [4, 8]])
            ->and(msData('miniature_schnauzer.adult_weight.pdsa.value'))->toEqual(['male' => [6, 7], 'female' => [6, 7]])
            ->and(msRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(msData('miniature_schnauzer.adult_weight.fci.source_id'))
            ->and(msRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(msData('miniature_schnauzer.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(msRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(msData('miniature_schnauzer.trainability.coren_rank.value'))
            ->and(msRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(12)
            ->and(msRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('miniature_schnauzer.lifespan.median_uk')
            ->and(msRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S227')
            ->and(msRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(11.6)
            ->and(msData('miniature_schnauzer.lifespan.median_uk.quote'))->toContain('was 11.6 years')
            ->and(msData('miniature_schnauzer.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Brushing 3× a week (PDSA "2-3 times a week" → upper bound), sourced and confirmed by the runbook rule.
        expect(msRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(3)
            ->and(msRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S226')
            ->and(msRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue();

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-schnauzer')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-schnauzer')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" — David's general row, the same as the Border Collie's
            // (no breed quote), so it is compared, not skipped. No meal / exercise gap (no quote).
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('miniature-schnauzer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = msPet($arrival);
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
        'adult 103 months' => [103, LifeStage::Adult, 60, 6000],
        'senior 104 months' => [104, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 104 months, after the Boxer (102) and before the Dachshund (108)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('miniature-schnauzer', 103))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('miniature-schnauzer', 104))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('boxer', 103))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('dachshund', 104))->toBe(LifeStage::Adult);
    });

    it('learns at 1.5 × the mixed-breed speed (Coren Excellent tier, rank 12) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = msPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'miniature_schnauzer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = msPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(msLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::MiniatureSchnauzer)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 104 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'miniature_schnauzer'] as $breed) {
            $pin = msPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(msLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['miniature_schnauzer'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(104)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Miniature Schnauzer appearance data.
            ->and($dog->pet_dna['breed'])->toBe('miniature_schnauzer')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = msPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Miniature Schnauzer after the Boxer with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel'])
            ->and(collect($breeds[23])->except('colours')->all())->toBe([
                'breed' => 'miniature_schnauzer', 'slug' => 'miniature-schnauzer', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.miniature_schnauzer',
                'search_keywords' => BreedConfigsSeeder::configs()[23]['search_keywords'], 'sort_order' => 230,
                'suitability' => [
                    'suits' => ['apartment', 'children', 'low_shedding'],
                    'consider' => ['frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — never a disqualifying one; natural ears and tail)', function () {
    it('samples Miniature Schnauzer DNA inside the standards (pepper and salt, black and silver or black; never white; natural ears and tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('miniature_schnauzer')
            ->and(PetDnaService::hasAppearance('miniature_schnauzer'))->toBeTrue()
            ->and(config('breed_appearance.miniature_schnauzer.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('miniature_schnauzer', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['coat_length'])->toContain('wiry');

            $prompt = $prompts->imagePrompt('miniature_schnauzer', $traits);
            expect($prompt)->toContain('Miniature Schnauzer')
                ->not->toMatch('/\b(merle|liver|white coat|pure white|parti)\b/i')
                ->not->toMatch('/\b(cropped ears|docked tail)\b/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black and silver',
            'pepper and salt (banded grey) with a dark facial mask',
            'solid black',
        ]);
    });

    it('draws the register portrait pepper and salt with natural ears and tail', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::MiniatureSchnauzer);

        expect($traits['coat_color'])->toStartWith('pepper and salt')
            ->and($portrait->prompt(BreedType::MiniatureSchnauzer))->toContain('Miniature Schnauzer')->toContain('pepper and salt')
            ->toContain('undocked')->toContain('uncropped')
            ->and(BreedPortraitService::relativeFile(BreedType::MiniatureSchnauzer))->toBe('dog/miniature-schnauzer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::MiniatureSchnauzer);
        expect($v1['prompt_anchor'])->toContain('Miniature Schnauzer')->toContain('pepper and salt coat')
            ->toContain('natural uncropped')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['pepper and salt', 'black and silver'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = msPet(104, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Miniature Schnauzer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; not a welfare breed, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.miniature_schnauzer'),
            config('breed_suitability.breeds.miniature_schnauzer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'miniature-schnauzer')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', '17.4', '8.3'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.miniature_schnauzer.traits')))->not->toMatch('/\b(cropped ears|docked tail)\b/')->not->toMatch('/\b(merle|white)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::MiniatureSchnauzer)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['frequent_grooming'])
            ->and(msData('proposed_game_parameters.miniature_schnauzer.marketing.source_id'))->toBe('S223')
            ->and(msData('proposed_game_parameters.miniature_schnauzer.marketing.value'))->toContain('in marketing: yes');
    });
});
