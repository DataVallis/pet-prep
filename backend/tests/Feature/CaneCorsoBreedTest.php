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
| M5-R10-23 — Cane Corso (appended after the Yorkshire Terrier: AKC 2024 No. 14)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `cane_corso` (S197–S201) and the
| runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.cane_corso` (exercise 120 min from PDSA — the breed has no
| RKC page —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 84 — senior
| from the closest rule row (Korec et al. 2017 median 9.29 y, S201): Claude's suggestion,
| verified=false until David decides —, arrival 2 / 9 / 36 / 84, learning × 1.0 (not ranked
| by Coren), paid breed, Border Collie care rates, suitability active_family +
| long_daily_exercise / not_for_small_children (new key) / experienced_owner /
| needs_mental_stimulation / shorter_lifespan / sheds / drools (new key); FCI colours only,
| natural ears and tail; no welfare chip).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ccData(string $path): mixed
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

/** @return array<string, mixed> the Cane Corso seeder row of (stage, from, key) */
function ccRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'cane-corso'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("cane-corso.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ccPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->caneCorso()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ccPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ccLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::CaneCorso;
        $config = BreedConfig::where('breed_slug', 'cane-corso')->sole();

        expect($breed->value)->toBe('cane_corso')
            ->and($breed->slug())->toBe('cane-corso')
            ->and(BreedType::fromSlug('cane-corso'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 190, 'breeds.cane_corso'])
            ->and($config->search_keywords)->toBe(['cane corso', 'cane corso italiano', 'italian cane corso', 'italian corso dog', 'corso', 'italijanski cane corso', 'korzo', 'kane korso'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ccData('proposed_game_parameters.cane_corso.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ccData('proposed_game_parameters.cane_corso.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Cane Corso pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ccPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('cane_corso');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'corso']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_25_120000_add_cane_corso_breed.php');
        // down() refuses while a Cane Corso exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'cane_corso']))->toThrow(QueryException::class);
        // The Yorkshire Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'yorkshire_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cane_corso']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::CaneCorso);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'cane-corso')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cane-corso')->count());

        BreedConfig::where('breed_slug', 'cane-corso')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'cane-corso')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'cane-corso')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'cane-corso');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cane-corso');
        $own = ['CONFIRMED_R10_CANE_CORSO', 'PROPOSED_R10_CANE_CORSO_SENIOR'];

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CANE_CORSO)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_CANE_CORSO_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Cane Corso's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, $own, true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.cane_corso';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ccRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ccData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ccRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ccData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ccRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ccData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.29 y (Korec et al. 2017, S201) = 83.61 → 84 (Claude's suggestion).
            ->and(ccRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ccData('cane_corso.lifespan.senior_from.months'))
            ->and(ccRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ccRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ccData('cane_corso.lifespan.senior_from.derived_from'))->toBe(['S11', 'S201'])
            ->and(ccRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(84)
            ->and(ccData("{$pgp}.senior_boundary_months.value"))->toBe(84);

        expect(ccRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ccData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ccRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(ccRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(ccRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ccData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ccRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ccRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ccData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(ccRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ccData("{$pgp}.learning_multiplier.value"))
            ->and(ccRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every confirmed Cane Corso decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CANE_CORSO) {
                $decided++;
                expect($row['breed_slug'])->toBe('cane-corso')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.cane_corso.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ccData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; the sourced needs without a mechanic
        // (weekly brushing, a gap between a meal and exercise, socialisation) point at their ROADMAP tasks.
        expect(json_encode([ccData('cane_corso'), ccData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ccData("{$pgp}.grooming.value"))->toBe(1)
            ->and(ccData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(ccData("{$pgp}.meal_exercise_gap.source_id"))->toBe('S199')
            ->and(ccData("{$pgp}.meal_exercise_gap.decision"))->toContain('ROADMAP M5-R16')
            ->and(ccData("{$pgp}.socialisation.source_id"))->toBe('S199')
            ->and(ccData("{$pgp}.socialisation.decision"))->toContain('ROADMAP M5-R17');
    });

    it('takes the breed-level numbers from the sourced cane_corso entries', function () {
        // FCI males 45–50 kg, females 40–45 kg (S197) → 40–50 kg; PDSA 40–50 kg (S199) agrees.
        expect(ccRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([40.0, 50.0])
            ->and(ccData('cane_corso.adult_weight.fci.value'))->toEqual(['male' => [45, 50], 'female' => [40, 45]])
            ->and(ccData('cane_corso.adult_weight.pdsa.value'))->toEqual([40, 50])
            ->and(ccRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ccData('cane_corso.adult_weight.fci.source_id'))
            ->and(ccRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(ccData('cane_corso.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Not ranked by Coren (S35) → no rank, learning 1.0.
            ->and(ccRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(ccData('cane_corso.trainability.coren_rank.value'))->toBeNull()
            ->and(ccRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(ccData('cane_corso.lifespan.median_international.value'))
            ->and(ccRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('cane_corso.lifespan.median_international')
            ->and(ccRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S201')
            ->and(ccRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.29);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cane-corso')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior
        // boundary / senior arrival age (84 — Claude's suggestion, no runbook rule produced a value).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cane-corso')->where('verified', false);
        expect($open->map(fn (array $r) => "{$r['stage']}.{$r['key']}")->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('key', '!=', StageParamKey::ChewingChancePerDay->value) as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_CANE_CORSO_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.cane_corso.senior_boundary_months')
                ->and($row['value'])->toBe(ccData('proposed_game_parameters.cane_corso.senior_boundary_months.value'))
                ->and((string) ccData($row['ref'])['decision'])->toStartWith('predlog Claude')->toContain('čaka Davida')->not->toContain('potrdil David');
        }
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

        expect($general('cane-corso'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ccPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 84-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age, below the cap)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 83 months' => [83, LifeStage::Adult, 120, 12000],
        'senior 84 months' => [84, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 84 months, three months after the Rottweiler', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('cane-corso', 83))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('cane-corso', 84))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rottweiler', 83))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $corso = ccPet(36);
        $date = $corso->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($corso))->toBe(1.0)
            ->and($training->gainPerSuccess($corso, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'cane_corso', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ccPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ccLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::CaneCorso)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 84 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'cane_corso'] as $breed) {
            $pin = ccPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ccLogin($pin)->assertOk()->json('pet.id'));
        }

        $corso = $pets['cane_corso'];
        expect($corso->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($corso->plan)->toBe(PetPlan::Challenge)
            ->and($corso->arrival_age_months)->toBe(84)
            ->and($corso->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Cane Corso appearance data.
            ->and($corso->pet_dna['breed'])->toBe('cane_corso')
            ->and((int) $corso->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ccPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Cane Corso after the Yorkshire Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier'])
            ->and($breeds[19])->toBe([
                'breed' => 'cane_corso', 'slug' => 'cane-corso', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.cane_corso',
                'search_keywords' => BreedConfigsSeeder::configs()[19]['search_keywords'], 'sort_order' => 190,
                'suitability' => [
                    'suits' => ['active_family'],
                    'consider' => ['long_daily_exercise', 'not_for_small_children', 'experienced_owner', 'needs_mental_stimulation', 'shorter_lifespan', 'sheds', 'drools'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural ears and tail — FCI 2023)', function () {
    it('samples Cane Corso DNA inside the standard (FCI colours, uncropped ears, undocked tail, moderate face)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('cane_corso')
            ->and(PetDnaService::hasAppearance('cane_corso'))->toBeTrue()
            ->and(config('breed_appearance.cane_corso.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('cane_corso', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['muzzle'])->toContain('moderate');

            $prompt = $prompts->imagePrompt('cane_corso', $traits);
            expect($prompt)->toContain('Cane Corso')
                ->not->toMatch('/\b(tan|merle|white patches|liver|chocolate)\b/i')
                ->not->toMatch('/\b(docked tail|cropped ears)\b/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['solid black', 'solid grey (blue)', 'black brindle', 'grey (blue) brindle', 'fawn with a black mask not beyond the eyes', 'stag red with a dark mask', 'dark wheat']);
    });

    it('draws the register portrait solid black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::CaneCorso);

        expect($traits['coat_color'])->toBe('solid black')
            ->and($portrait->prompt(BreedType::CaneCorso))->toContain('Cane Corso')->toContain('solid black')->toContain('undocked')->toContain('uncropped')
            ->and(BreedPortraitService::relativeFile(BreedType::CaneCorso))->toBe('dog/cane-corso.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::CaneCorso);
        expect($v1['prompt_anchor'])->toContain('Cane Corso')->toContain('solid black')->toContain('natural uncropped')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['solid black', 'solid grey (blue)', 'black brindle'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = ccPet(84, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Cane Corso')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.cane_corso'),
            config('breed_suitability.breeds.cane_corso'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cane-corso')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.cane_corso.traits')))->not->toMatch('/\b(docked tail|cropped ears)\b/')->not->toMatch('/\b(tan|merle|liver)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::CaneCorso)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and(config('breed_suitability.vocabulary.not_for_small_children'))->toBe('consider')
            ->and(config('breed_suitability.vocabulary.drools'))->toBe('consider')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->toContain('shorter_lifespan')
            ->and($consider)->toContain('not_for_small_children')
            ->and(app(BreedSuitability::class)->for(BreedType::CaneCorso)['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('family_pet')
            ->and(ccData('proposed_game_parameters.cane_corso.marketing.decision'))->toContain('in marketing yes');
    });
});
