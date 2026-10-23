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
| M5-R10-58 — Scottish Terrier (appended after the Samoyed: AKC 2024 No. 54)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `scottish_terrier` (S472–S476) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.scottish_terrier` (exercise 60 min — PDSA "Minimum
| exercise (per day) 1 hour"; the RKC page was not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36, arrival 2 / 9 / 36, learning × 0.7 (Coren rank 65, Fair), paid breed, Border Collie care rates, brushing every
| day (PDSA "daily grooming", M5-R13), puppy socialisation with the PetMD quote (M5-R17); no digestion warning (no
| meal-gap quote). The senior boundary / senior arrival 90 (0.75 × PDSA lower bound "Over 10 years") is Claude's
| suggestion, verified=false, open for David. Suitability: suits family_pet, consider frequent_grooming / needs_company;
| neither other_pets nor chases_small_pets (PDSA and PetMD conflict); black portrait; no welfare chip, in marketing.
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

/** @return array<string, mixed> the Scottish Terrier seeder row of (stage, from, key) */
function stRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'scottish-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("scottish_terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function stPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->scottishTerrier()->create(array_merge([
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
        $breed = BreedType::ScottishTerrier;
        $config = BreedConfig::where('breed_slug', 'scottish-terrier')->sole();

        expect($breed->value)->toBe('scottish_terrier')
            ->and($breed->slug())->toBe('scottish-terrier')
            ->and(BreedType::fromSlug('scottish-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 540, 'breeds.scottish_terrier'])
            ->and($config->search_keywords)->toBe(['scottish terrier', 'skotski terier', 'škotski terier', 'scottie', 'scotty', 'aberdeen terrier', 'scottish'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) stData('proposed_game_parameters.scottish_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = stData('proposed_game_parameters.scottish_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Scottish Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = stPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('scottish_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'scottie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'scottish-terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_15_120000_add_scottish_terrier_breed.php');
        // down() refuses while a Scottish Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'scottish_terrier']))->toThrow(QueryException::class);
        // The Samoyed (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'samoyed']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'scottish_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ScottishTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'scottish-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'scottish-terrier')->count());

        BreedConfig::where('breed_slug', 'scottish-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'scottish-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'scottish-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'scottish-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'scottish-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'scottish-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SCOTTISH_TERRIER)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_SCOTTISH_TERRIER_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_SCOTTISH_TERRIER', 'PROPOSED_R10_SCOTTISH_TERRIER_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.scottish_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(stRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(stData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(stRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(stRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(stData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PDSA lower bound "Over 10 years", S474) = 90 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stData('scottish_terrier.lifespan.senior_from.months'))
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(stData("{$pgp}.senior_boundary_months.value"))
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * stRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(stData('scottish_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S474'])
            ->and(stData('scottish_terrier.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S474')
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SCOTTISH_TERRIER_SENIOR)
            ->and(stData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_SCOTTISH_TERRIER_SENIOR)
            ->and(stRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stData("{$pgp}.exercise_minutes_adult.value"))
            ->and(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(stData('scottish_terrier.exercise.adult.quote'))
            ->and(stRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S474')
            ->and(stRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(stRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(stData("{$pgp}.exercise_minutes_senior.value"))
            ->and(stRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(stRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(stData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(stRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(stData("{$pgp}.learning_multiplier.value"))
            ->and(stRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SCOTTISH_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('scottish-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.scottish_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) stData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); the professional groomer has no mechanic → open for David (M5-R18).
        // No meal-gap quote → no digestion warning (never invent a quote).
        expect(json_encode([stData('scottish_terrier'), stData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(stData("{$pgp}.grooming.value"))->toBe(7)
            ->and(stData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(stData("{$pgp}.professional_grooming.decision"))->toContain('M5-R18')
            ->and(stData($pgp))->not->toHaveKey('meal_exercise_gap')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('scottish_terrier')
            ->and(stData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['scottish_terrier'][1])
            ->and(stData('scottish_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['scottish_terrier'][1]);
    });

    it('takes the breed-level numbers from the sourced scottish_terrier entries', function () {
        // Kennel Club standard (S473) "8.5kg – 10.5kg." for both sexes.
        expect(stRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([8.5, 10.5])
            ->and(stData('scottish_terrier.adult_weight.kc_standard.value'))->toEqual(['male' => [8.5, 10.5], 'female' => [8.5, 10.5]])
            ->and(stRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(stData('scottish_terrier.adult_weight.kc_standard.source_id'))
            ->and(stRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(stData('scottish_terrier.adult_weight.kc_standard.quote'))
            ->and(stRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(stData('scottish_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 65 (S35), Fair tier → learning 0.7.
            ->and(stRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(65)
            ->and(stRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(stData('scottish_terrier.trainability.coren_rank.quote'))
            ->and(stData('scottish_terrier.trainability.coren_rank.value'))->toBe(65)
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('scottish_terrier.lifespan.pdsa')
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S474')
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(stRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(stData('scottish_terrier.lifespan.pdsa.quote'));

        // Brushing every day (PDSA "daily grooming" → 7) — the rule, verified.
        $grooming = stRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S474')
            ->and($grooming['quote'])->toBe(stData('scottish_terrier.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(stData('proposed_game_parameters.scottish_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(stRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(stRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S475');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'scottish-terrier');
        // Like the other pedigree breeds: no individual variation row; no digestion warning (no meal-gap quote).
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (90 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_SCOTTISH_TERRIER_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.scottish_terrier.senior_boundary_months')
                ->and($row['value'])->toBe(stData('proposed_game_parameters.scottish_terrier.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S475).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('scottish-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = stPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 90-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Samoyed and 31 months before the Whippet (121)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('scottish-terrier', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('scottish-terrier', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('samoyed', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('whippet', 121))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = stPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'scottish_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
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
            ->and($pet->breed_type)->toBe(BreedType::ScottishTerrier)
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
        foreach (['border_collie', 'scottish_terrier'] as $breed) {
            $pin = stPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(stLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['scottish_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('scottish_terrier')
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
    it('lists the Scottish Terrier after the Samoyed with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'scottish_terrier', 'samoyed');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'scottish_terrier', 'slug' => 'scottish-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.scottish_terrier',
            'search_keywords' => seededBreedConfig('scottish_terrier')['search_keywords'], 'sort_order' => 540,
            'suitability' => [
                'suits' => ['family_pet'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — black, wheaten, brindle)', function () {
    it('samples DNA in the three standard colours of the appearance block only, never white patches or other colours', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('scottish_terrier')
            ->and(PetDnaService::hasAppearance('scottish_terrier'))->toBeTrue()
            ->and(config('breed_appearance.scottish_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.scottish_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('scottish_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('wiry')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['eye_color'])->toContain('dark brown');

            $prompt = $prompts->imagePrompt('scottish_terrier', $traits);
            expect($prompt)->toContain('Scottish Terrier')
                ->not->toMatch('/\b(white patch|white markings|merle|docked|cropped|Westie|Schnauzer|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['black', 'brindle', 'wheaten'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.scottish_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.scottish_terrier'), 'key'))->toBe(['black', 'brindle', 'wheaten']);
    });

    it('draws the register portrait black', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ScottishTerrier);

        expect($traits['coat_color'])->toBe('black')
            ->and($portrait->prompt(BreedType::ScottishTerrier))->toContain('Scottish Terrier')->toContain('black')
            ->and(BreedPortraitService::relativeFile(BreedType::ScottishTerrier))->toBe('dog/scottish-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ScottishTerrier);
        expect($v1['prompt_anchor'])->toContain('Scottish Terrier')->toContain('black coat')
            ->not->toMatch('/\b(white patch|docked|shaved)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black', 'brindle', 'wheaten'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = stPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Scottish Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.scottish_terrier'),
            config('breed_suitability.breeds.scottish_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'scottish-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', 'bladder cancer', '20 times'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ScottishTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association named by the runbook names the breed → no welfare chip; PDSA "Over 10 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(stData('proposed_game_parameters.scottish_terrier.marketing.source_id'))->toBe('S474')
            ->and(stData('proposed_game_parameters.scottish_terrier.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic or low-shedding (no shedding source at all).
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (84 Dogs Trust, 108 PetMD, 114 via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'scottish-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(108)->not->toContain(114)
            // Bladder cancer is a tier-C claim with a risk multiple → never recorded as a health fact.
            ->and(stData('scottish_terrier.health'))->not->toHaveKey('bladder_cancer');
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives the family chip plus two consider chips; never children, other_pets, chases_small_pets, low_shedding or a welfare chip', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ScottishTerrier);
        expect($tags['suits'])->toBe(['family_pet'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('other_pets')->not->toContain('first_time_owner')->not->toContain('often_alone')->not->toContain('low_shedding')->not->toContain('active_family')
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            // PDSA "get on well with other pets" vs PetMD "a strong prey drive" → conflicting sources, neither chip.
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('secure_fencing')->not->toContain('long_daily_exercise')
            ->not->toContain('independent_experienced_owner')->not->toContain('sheds');

        expect(stData('scottish_terrier.exercise.adult.quote'))->toBe('Minimum exercise (per day) | 1 hour')
            ->and(stData('scottish_terrier.suitability.pdsa_grooming.quote'))->toStartWith('Scotties need daily grooming')
            ->and(stData('scottish_terrier.suitability.petmd_family.quote'))->toBe('With the right training and socialization, Scottish Terriers can be good dogs for families.')
            ->and(stData('scottish_terrier.behaviour.time_alone.quote'))->toContain('alone for extended periods')
            ->and(stData('scottish_terrier.behaviour.other_pets.quote'))->toContain('get on well with other pets')
            ->and(stData('scottish_terrier.behaviour.other_pets.alternatives.0.quote'))->toContain('strong prey drive')
            ->and(stData('proposed_game_parameters.scottish_terrier.suitability_tags.not_proposed.chases_small_pets'))->toContain('open for David');
    });
});
