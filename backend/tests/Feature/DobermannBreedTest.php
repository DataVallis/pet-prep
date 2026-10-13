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
| M5-R10-25 — Dobermann (appended after the Pembroke Welsh Corgi: AKC 2024 No. 16)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `dobermann` (S207–S212) and the
| runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.dobermann` (exercise 120 min, senior 90 min, puppy 10 min ×
| age capped at 120, stages 9 / 36 / 83 — senior from the VetCompass median 9.2 y,
| provisional —, arrival 2 / 9 / 36 / 83, learning × 1.9, paid breed, Border Collie care
| rates, suitability: suits active_family / family_pet / large_home, consider
| long_daily_exercise / not_for_small_children / experienced_owner /
| needs_mental_stimulation / sheds / chases_small_pets (David 2026-10-12); FCI colours black or brown with rust, natural ears
| and tail; no welfare chip).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function dmData(string $path): mixed
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

/** @return array<string, mixed> the Dobermann seeder row of (stage, from, key) */
function dmRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'dobermann'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("dobermann.{$stage}.{$from}.{$key->value}");

    return $row;
}

function dmPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->dobermann()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function dmPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function dmLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Dobermann;
        $config = BreedConfig::where('breed_slug', 'dobermann')->sole();

        expect($breed->value)->toBe('dobermann')
            ->and($breed->slug())->toBe('dobermann')
            ->and(BreedType::fromSlug('dobermann'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 210, 'breeds.dobermann'])
            ->and($config->search_keywords)->toBe(['dobermann', 'doberman', 'doberman pinscher', 'dobie', 'dobermann pinscher'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) dmData('proposed_game_parameters.dobermann.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = dmData('proposed_game_parameters.dobermann.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Dobermann pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = dmPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('dobermann');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'doberman']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_27_120000_add_dobermann_breed.php');
        // down() refuses while a Dobermann exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'dobermann']))->toThrow(QueryException::class);
        // The Pembroke Welsh Corgi (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pembroke_welsh_corgi']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'dobermann']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Dobermann);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'dobermann')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dobermann')->count());

        BreedConfig::where('breed_slug', 'dobermann')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'dobermann')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'dobermann')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'dobermann');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dobermann');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_DOBERMANN)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Dobermann's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_DOBERMANN');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.dobermann';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(dmRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(dmData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(dmRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(dmData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(dmRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(dmData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.2 y (VetCompass median, S212) = 82.8 → 83 (provisional).
            ->and(dmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(dmData('dobermann.lifespan.senior_from.months'))
            ->and(dmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * dmRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(dmData('dobermann.lifespan.senior_from.derived_from'))->toBe(['S11', 'S212'])
            ->and(dmRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(83);

        expect(dmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(dmData("{$pgp}.exercise_minutes_adult.value"))
            ->and(dmRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(dmRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(dmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(dmData("{$pgp}.exercise_minutes_senior.value"))
            ->and(dmRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(dmRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(dmData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(dmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(dmData("{$pgp}.learning_multiplier.value"))
            ->and(dmRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.9);

        // Every Dobermann decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_DOBERMANN) {
                $decided++;
                expect($row['breed_slug'])->toBe('dobermann')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.dobermann.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) dmData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs without a mechanic (weekly brushing, a meal / exercise gap, socialisation) point at their ROADMAP tasks.
        expect(json_encode([dmData('dobermann'), dmData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(dmData("{$pgp}.grooming.value"))->toBe(1)
            ->and(dmData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(dmData("{$pgp}.meal_exercise_gap.decision"))->toContain('ROADMAP M5-R16')
            ->and(dmData("{$pgp}.socialisation.decision"))->toContain('ROADMAP M5-R17');
    });

    it('takes the breed-level numbers from the sourced dobermann entries', function () {
        // FCI males about 40–45 kg, females about 32–35 kg (S207) → game range 32–45 kg; PDSA 30–40 kg.
        expect(dmRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([32.0, 45.0])
            ->and(dmData('dobermann.adult_weight.fci.value'))->toEqual(['male' => [40, 45], 'female' => [32, 35]])
            ->and(dmData('dobermann.adult_weight.pdsa.value'))->toEqual([30, 40])
            ->and(dmRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(dmData('dobermann.adult_weight.fci.source_id'))
            ->and(dmRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(dmData('dobermann.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            ->and(dmRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(dmData('dobermann.trainability.coren_rank.value'))
            ->and(dmRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(5)
            ->and(dmRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('dobermann.lifespan.median_uk')
            ->and(dmRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S212')
            ->and(dmRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.2)
            ->and(dmData('dobermann.lifespan.median_uk.quote'))->toBe('Doberman 9.2 6.2-11.0 2.1-13.0 37')
            ->and(dmData('dobermann.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dobermann')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dobermann')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (sourced socialisation advice; the Border Collie has none).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the digestion warning after a meal (PDSA; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('dobermann'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = dmPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (10 min × age)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (reaches the cap)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 82 months' => [82, LifeStage::Adult, 120, 12000],
        'senior 83 months' => [83, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 83 months, one month before the Cane Corso (84)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('dobermann', 82))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('dobermann', 83))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cane-corso', 83))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('cane-corso', 84))->toBe(LifeStage::Senior);
    });

    it('learns at 1.9 × the mixed-breed speed (Coren Brightest tier, rank 5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = dmPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.9);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'dobermann', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = dmPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(dmLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Dobermann)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 83 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'dobermann'] as $breed) {
            $pin = dmPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(dmLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['dobermann'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(83)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Dobermann appearance data.
            ->and($dog->pet_dna['breed'])->toBe('dobermann')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = dmPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Dobermann after the Pembroke Welsh Corgi with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian'])
            ->and($breeds[21])->toBe([
                'breed' => 'dobermann', 'slug' => 'dobermann', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.dobermann',
                'search_keywords' => BreedConfigsSeeder::configs()[21]['search_keywords'], 'sort_order' => 210,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'sheds', 'chases_small_pets'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural ears and tail as in the FCI standard)', function () {
    it('samples Dobermann DNA inside the standard (black or brown with rust, natural ears and tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('dobermann')
            ->and(PetDnaService::hasAppearance('dobermann'))->toBeTrue()
            ->and(config('breed_appearance.dobermann.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('dobermann', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('uncropped');

            $prompt = $prompts->imagePrompt('dobermann', $traits);
            expect($prompt)->toContain('Dobermann')
                ->not->toMatch('/\b(merle|blue|fawn|isabella|white markings|white patch)\b/i')
                ->not->toMatch('/\b(cropped ears|docked tail)\b/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black with clearly defined rust red tan markings',
            'brown with clearly defined rust red tan markings',
        ]);
    });

    it('draws the register portrait black and rust', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Dobermann);

        expect($traits['coat_color'])->toStartWith('black with clearly defined rust')
            ->and($portrait->prompt(BreedType::Dobermann))->toContain('Dobermann')->toContain('black')->toContain('undocked')->toContain('uncropped')
            ->and(BreedPortraitService::relativeFile(BreedType::Dobermann))->toBe('dog/dobermann.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Dobermann);
        expect($v1['prompt_anchor'])->toContain('Dobermann')->toContain('black coat and clearly defined rust red tan markings')
            ->toContain('natural uncropped ears')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black and rust', 'brown and rust'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = dmPet(83, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Dobermann')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.dobermann'),
            config('breed_suitability.breeds.dobermann'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'dobermann')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.dobermann.traits')))->not->toMatch('/\b(cropped ears|docked tail)\b/')->not->toMatch('/\b(merle|blue|fawn|white markings)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::Dobermann)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('heart_and_spine')
            ->and($consider)->not->toContain('shorter_lifespan')
            ->and(dmData('proposed_game_parameters.dobermann.marketing.decision'))->toContain('in marketing yes');
    });
});
