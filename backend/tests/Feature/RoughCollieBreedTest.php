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
| M5-R10-44 — Rough Collie (appended after the Pug: AKC 2024 No. 39 "Collie")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `rough_collie` (S363–S369) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.rough_collie` (exercise 60 min — RKC "Up to 1
| hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 — 0.75 × RKC lower
| bound 12 y, provisional until McMillan 2024 is checked —, arrival 2 / 9 / 36 / 108, learning × 1.5 (Coren
| rank 16, Excellent), paid breed, Border Collie care rates, brushing every day (RKC "Every day", M5-R13),
| puppy socialisation with the PetMD quote (M5-R17), no meal / walk gap (no source); suitability: suits
| children / other_pets, consider may_herd_children / frequent_grooming / needs_company / sheds; sable and
| white portrait (sable / tricolour / blue merle, never white or a double merle); not a welfare breed (RKC
| Breed Watch Category 2 without a named point) → in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function rcData(string $path): mixed
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

/** @return array<string, mixed> the Rough Collie seeder row of (stage, from, key) */
function rcRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'rough-collie'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("rough_collie.{$stage}.{$from}.{$key->value}");

    return $row;
}

function rcPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->roughCollie()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function rcPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function rcLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::RoughCollie;
        $config = BreedConfig::where('breed_slug', 'rough-collie')->sole();

        expect($breed->value)->toBe('rough_collie')
            ->and($breed->slug())->toBe('rough-collie')
            ->and(BreedType::fromSlug('rough-collie'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 400, 'breeds.rough_collie'])
            ->and($config->search_keywords)->toBe(['rough collie', 'collie', 'dolgodlaki škotski ovčar', 'dolgodlaki skotski ovcar', 'škotski ovčar', 'skotski ovcar', 'koli', 'long-haired collie', 'collie rough'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) rcData('proposed_game_parameters.rough_collie.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = rcData('proposed_game_parameters.rough_collie.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Rough Collie pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = rcPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('rough_collie');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // The Smooth Collie (FCI 296) is a different breed and no breed key.
            ->and($refused(['breed_type' => 'smooth_collie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_22_120000_add_rough_collie_breed.php');
        // down() refuses while a Rough Collie exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'rough_collie']))->toThrow(QueryException::class);
        // The Pug (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pug']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rough_collie']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::RoughCollie);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'rough-collie')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')->count());

        BreedConfig::where('breed_slug', 'rough-collie')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'rough-collie')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'rough-collie')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'rough-collie');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'rough-collie'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ROUGH_COLLIE)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Rough Collie's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_ROUGH_COLLIE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.rough_collie';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(rcRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(rcData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(rcRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rcData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(rcRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rcData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S365) = 108 (provisional until McMillan 2024 is checked).
            ->and(rcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rcData('rough_collie.lifespan.senior_from.months'))
            ->and(rcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * rcRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(rcData('rough_collie.lifespan.senior_from.derived_from'))->toBe(['S11', 'S365'])
            ->and(rcRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S365')
            ->and(rcRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(rcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(rcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rcData("{$pgp}.exercise_minutes_adult.value"))
            ->and(rcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(rcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(rcData('rough_collie.exercise.adult.quote'))
            ->and(rcRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(rcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rcData("{$pgp}.exercise_minutes_senior.value"))
            ->and(rcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(rcRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(rcData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(rcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(rcData("{$pgp}.learning_multiplier.value"))
            ->and(rcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Rough Collie decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ROUGH_COLLIE) {
                $decided++;
                expect($row['breed_slug'])->toBe('rough-collie')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.rough_collie.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) rcData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); nail trimming waits for ROADMAP M5-R19, ear cleaning /
        // bathing for M5-R20, the groomer for M5-R18. No source names a meal / walk gap → no M5-R16 row.
        expect(json_encode([rcData('rough_collie'), rcData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(rcData("{$pgp}.grooming.value"))->toBe(7)
            ->and(rcData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(rcData("{$pgp}.nail_care.decision"))->toContain('ROADMAP M5-R19')
            ->and(rcData("{$pgp}.ear_care_bathing.decision"))->toContain('ROADMAP M5-R20')
            ->and(rcData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(rcData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['rough_collie'][1])
            ->and(rcData('rough_collie.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['rough_collie'][1])
            // No meal / exercise gap source → no MEAL_EXERCISE_GAP entry and no game value.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('rough_collie')
            ->and(rcData($pgp))->not->toHaveKey('meal_exercise_gap');
    });

    it('takes the breed-level numbers from the sourced rough_collie entries', function () {
        // AKC standard: dogs 60–75 lb, bitches 50–65 lb (S367) → 22.7–34.0 kg for the game.
        expect(rcRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([22.7, 34.0])
            ->and(rcData('rough_collie.adult_weight.akc.value'))->toEqual(['male' => [27.2, 34.0], 'female' => [22.7, 29.5]])
            ->and(rcRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(rcData('rough_collie.adult_weight.akc.source_id'))
            ->and(rcRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(rcData('rough_collie.adult_weight.akc.quote'))
            ->and(rcRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(rcData('rough_collie.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 16 (S35), Excellent tier → learning 1.5.
            ->and(rcRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(16)
            ->and(rcRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(rcData('rough_collie.trainability.coren_rank.quote'))
            ->and(rcData('rough_collie.trainability.coren_rank.value'))->toBe(16)
            ->and(rcRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('rough_collie.lifespan.rkc')
            ->and(rcRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S365')
            ->and(rcRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(rcRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(rcData('rough_collie.lifespan.rkc.quote'));

        // Brushing every day (RKC "Every day"; PetMD daily brushing recommended) — the rule, verified.
        $grooming = rcRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S365')
            ->and($grooming['quote'])->toBe(rcData('rough_collie.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(rcData('proposed_game_parameters.rough_collie.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(rcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(rcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S368');
        // No meal / walk gap row (no source names one for the breed).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S368).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('rough-collie'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = rcPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, the same month as the Maltese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('rough-collie', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('rough-collie', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('maltese', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('maltese', 107))->toBe(LifeStage::Adult);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = rcPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'rough_collie', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = rcPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(rcLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::RoughCollie)
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
        foreach (['border_collie', 'rough_collie'] as $breed) {
            $pin = rcPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(rcLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['rough_collie'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Rough Collie appearance data.
            ->and($dog->pet_dna['breed'])->toBe('rough_collie')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = rcPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Rough Collie after the Pug with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'rough_collie', 'pug');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'rough_collie', 'slug' => 'rough-collie', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.rough_collie',
            'search_keywords' => seededBreedConfig('rough_collie')['search_keywords'], 'sort_order' => 400,
            'suitability' => [
                'suits' => ['children', 'other_pets'],
                'consider' => ['may_herd_children', 'frequent_grooming', 'needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — sable and white, tricolour, blue merle)', function () {
    it('samples Rough Collie DNA in the three standard colours only with the full collie coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('rough_collie')
            ->and(PetDnaService::hasAppearance('rough_collie'))->toBeTrue()
            ->and(config('breed_appearance.rough_collie.verified'))->toBeFalse()
            ->and(config('breed_appearance.rough_collie.prompt_order'))->toContain('nose');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('rough_collie', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['nose'])->toContain('not short-faced')
                ->and($traits['coat_length'])->toContain('mane and frill');

            $prompt = $prompts->imagePrompt('rough_collie', $traits);
            expect($prompt)->toContain('Rough Collie')
                ->not->toMatch('/\b(brindle|cream|fawn|liver|double merle|red merle|docked|cropped)\b/i')
                ->not->toContain('predominantly white')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['sable and white', 'tricolour (black with tan markings and white)', 'blue merle and white'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.rough_collie'), 'value'))->toBe($standard);
    });

    it('draws the register portrait sable and white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::RoughCollie);

        expect($traits['coat_color'])->toBe('sable and white')
            ->and($portrait->prompt(BreedType::RoughCollie))->toContain('Rough Collie')->toContain('sable and white')
            ->and(BreedPortraitService::relativeFile(BreedType::RoughCollie))->toBe('dog/rough-collie.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::RoughCollie);
        expect($v1['prompt_anchor'])->toContain('Rough Collie')->toContain('sable and white')
            ->not->toContain('brindle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['sable and white', 'tricolour (black with tan markings and white)', 'blue merle and white'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = rcPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Rough Collie')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 2, no named point), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.rough_collie'),
            config('breed_suitability.breeds.rough_collie'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::RoughCollie);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toBe(['may_herd_children', 'frequent_grooming', 'needs_company', 'sheds'])
            // Breed Watch Category 2 without a named point: no welfare chip; RKC "Over 12 years"; RKC "Up to 1 hour"; no prey-drive statement.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'eyelid_problems', 'back_spine', 'shorter_lifespan', 'sensitive_skin', 'long_daily_exercise', 'chases_small_pets']))->toBe([])
            ->and(array_intersect($tags['suits'], ['small_children', 'family_pet', 'first_time_owner', 'large_home', 'active_family', 'apartment']))->toBe([])
            ->and(rcData('rough_collie.suitability.rkc_breed_watch.value'))->toBe('Category 2')
            ->and(rcData('proposed_game_parameters.rough_collie.marketing.value'))->toContain('in marketing: yes')
            // The PetMD lower bound (senior 90) and the Dogs Trust rule of thumb (84) are alternatives only — never a seeded value.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rough-collie')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(90)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the Rough Collie children / other_pets and no welfare chip — herding, daily brushing, needs company, sheds; no family_pet (no family-life statement)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::RoughCollie);
        expect($tags['suits'])->toBe(['children', 'other_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('active_family')->not->toContain('first_time_owner')
            ->and($tags['consider'])->toBe(['may_herd_children', 'frequent_grooming', 'needs_company', 'sheds'])
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('needs_mental_stimulation')->not->toContain('older_children')->not->toContain('not_for_small_children');

        expect(rcData('rough_collie.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(rcData('rough_collie.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house')
            ->and(rcData('rough_collie.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(rcData('rough_collie.behaviour.family.quote'))->toBe('A great companion dog, friendly, happy and active, good with children and other dogs.')
            ->and(rcData('rough_collie.behaviour.time_alone.quote'))->toBe('do not do well by themselves for long periods of time.');
    });
});
