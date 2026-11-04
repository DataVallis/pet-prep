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
| M5-R10-91 — Pekingese (appended after the Anatolian Shepherd Dog: AKC 2024 No. 87 "Pekingese")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `pekingese` (S690–S695) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.pekingese` (exercise 30 min — PDSA "a minimum of half an hour", the RKC
| page was not reachable —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36, arrival 2 / 9 / 36, learning
| × 0.5 (Coren rank 73, Lowest), paid breed, Border Collie care rates, brushing every day (PDSA "a daily brush", M5-R13),
| puppy socialisation with the PDSA quote (M5-R17), care tasks with a PetMD frequency (teeth every day M5-R15, ears once a
| week M5-R20, groomer every 6 weeks M5-R18) and the summer walk warning (PDSA, M5-R14). Open for David: the senior boundary /
| senior arrival 108 (0.75 × PDSA lower bound 12 y — Claude's proposal, Basenji precedent). Flat-faced welfare breed (PDSA
| brachycephalic): no suits chip; consider brachycephalic_breathing / older_children / needs_company / eyelid_problems /
| sensitive_skin / sheds / frequent_grooming; red-gold portrait with a black mask; not in marketing (BVA S92).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pkData(string $path): mixed
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

/** @return array<string, mixed> the Pekingese seeder row of (stage, from, key) */
function pkRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'pekingese'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("pekingese.{$stage}.{$from}.{$key->value}");

    return $row;
}

function pkPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->pekingese()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pkPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pkLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Pekingese;
        $config = BreedConfig::where('breed_slug', 'pekingese')->sole();

        expect($breed->value)->toBe('pekingese')
            ->and($breed->slug())->toBe('pekingese')
            ->and(BreedType::fromSlug('pekingese'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 850, 'breeds.pekingese'])
            ->and($config->search_keywords)->toBe(['pekingese', 'pekinese', 'peke', 'lion dog', 'pekinezer', 'pekinez', 'pekinški pes', 'pekinski pes'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pkData('proposed_game_parameters.pekingese.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pkData('proposed_game_parameters.pekingese.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Pekingese pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pkPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('pekingese');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'pekinese']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'peke']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_08_120000_add_pekingese_breed.php');
        // down() refuses while a Pekingese exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'pekingese']))->toThrow(QueryException::class);
        // The Anatolian Shepherd Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'anatolian_shepherd_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pekingese']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Pekingese);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'pekingese')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pekingese')->count());

        BreedConfig::where('breed_slug', 'pekingese')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'pekingese')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'pekingese')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'pekingese');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pekingese');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'pekingese'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PEKINGESE)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_PEKINGESE_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_PEKINGESE', 'PROPOSED_R10_PEKINGESE_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.pekingese';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pkRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pkData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pkRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pkData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pkRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pkData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PDSA lower bound "Over 12 years", S693) = 108 — the closest rule row, Claude's
            // suggestion (no McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pkData('pekingese.lifespan.senior_from.months'))
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pkData("{$pgp}.senior_boundary_months.value"))
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pkRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pkData('pekingese.lifespan.senior_from.derived_from'))->toBe(['S11', 'S693'])
            ->and(pkData('pekingese.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S693')
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_PEKINGESE_SENIOR)
            ->and(pkData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_PEKINGESE_SENIOR)
            ->and(pkRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(pkRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pkData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pkRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(pkRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(pkData('pekingese.suitability.pdsa_exercise.quote'))
            ->and(pkRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S693')
            ->and(pkRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(pkRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pkData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pkRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            ->and(pkRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pkData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(pkRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pkData("{$pgp}.learning_multiplier.value"))
            ->and(pkRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PEKINGESE) {
                $decided++;
                expect($row['breed_slug'])->toBe('pekingese')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.pekingese.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pkData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13), socialisation (M5-R17), teeth / ears / groomer
        // (M5-R15 / R20 / R18) and the summer walk warning (M5-R14). No meal gap, no fold / nail / bath task (no frequency).
        expect(json_encode([pkData('pekingese'), pkData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(pkData("{$pgp}.grooming.value"))->toBe(7)
            ->and(pkData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(pkData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('pekingese')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['pekingese'])->toBe(['S693', pkData('pekingese.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['pekingese'][1])->toBe(pkData("{$pgp}.socialisation.quote"))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['pekingese']))->toBe(['tooth_brushing', 'ear_cleaning', 'groomer_visit'])
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT['pekingese'])->toBe(['S693', pkData("{$pgp}.no_exercise_in_heat.quote"), "{$pgp}.no_exercise_in_heat"]);

        // The care tasks quote their data.json entries verbatim.
        foreach (BreedStageParamsSeeder::DOG_CARE_TASKS['pekingese'] as $task => [$times, $period, $source, $quote, $ref]) {
            expect(pkData($ref)['quote'])->toBe($quote, $task)
                ->and(pkData($ref)['source_id'])->toBe($source, $task);
        }
        expect(array_map(fn (array $t) => [$t[0], $t[1]], BreedStageParamsSeeder::DOG_CARE_TASKS['pekingese']))
            ->toBe(['tooth_brushing' => [7, 'day'], 'ear_cleaning' => [1, 'week'], 'groomer_visit' => [1, 'six_weeks']]);
    });

    it('takes the breed-level numbers from the sourced pekingese entries', function () {
        // PDSA (S693) average "5kg" for both sexes — the FCI standard gives only upper limits per sex.
        expect(pkRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5, 5])
            ->and(pkData('pekingese.adult_weight.pdsa.value'))->toEqual(['male' => [5, 5], 'female' => [5, 5]])
            ->and(pkData('pekingese.adult_weight.fci.value'))->toEqual(['male' => [5, 5], 'female' => [5.4, 5.4]])
            ->and(pkRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pkData('pekingese.adult_weight.pdsa.source_id'))
            ->and(pkRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(pkData('pekingese.adult_weight.pdsa.quote'))
            ->and(pkRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(pkData('pekingese.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 73 (Lowest) → learning 0.5.
            ->and(pkRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(73)
            ->and(pkData('pekingese.trainability.coren_rank.value'))->toBe(73)
            ->and(pkRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('pekingese.lifespan.pdsa')
            ->and(pkRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S693')
            ->and(pkRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(pkRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(pkData('pekingese.lifespan.pdsa.quote'));

        // Brushing every day: PDSA "a daily brush" → 7 (runbook rule, verified).
        $grooming = pkRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S693')
            ->and($grooming['quote'])->toBe(pkData('pekingese.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(pkData('proposed_game_parameters.pekingese.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(pkRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(pkRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S693')
            ->and(pkRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pekingese');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(1)
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (108 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_PEKINGESE_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.pekingese.senior_boundary_months')
                ->and($row['value'])->toBe(pkData('proposed_game_parameters.pekingese.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the breed's own socialisation quote (PDSA S693).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            // M5-R14 / R15 / R18 / R20: the breed's care tasks and the summer walk warning (the Border Collie has none).
            ->reject(fn (array $r) => str_ends_with($r['key'], '_frequency') || $r['key'] === StageParamKey::NoWalkInHeat->value)
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('pekingese'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pkPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, like the Basenji and 18 months after the Anatolian Shepherd Dog (90)', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('pekingese', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('pekingese', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('basenji', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('anatolian-shepherd-dog', 90))->toBe(LifeStage::Senior);
    });

    it('learns at half the mixed-breed speed (Coren Lowest tier, × 0.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = pkPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'pekingese', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pkPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pkLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Pekingese)
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
        foreach (['border_collie', 'pekingese'] as $breed) {
            $pin = pkPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pkLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['pekingese'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('pekingese')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pkPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Pekingese after the Anatolian Shepherd Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'pekingese', 'anatolian_shepherd_dog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'pekingese', 'slug' => 'pekingese', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.pekingese',
            'search_keywords' => seededBreedConfig('pekingese')['search_keywords'], 'sort_order' => 850,
            'suitability' => [
                'suits' => [],
                'consider' => ['brachycephalic_breathing', 'older_children', 'needs_company', 'eyelid_problems', 'sensitive_skin', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — KC breed standard S692 / FCI S690)', function () {
    it('samples DNA in the eight colours of the appearance block only, never albino, liver or merle, with a moderate face', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('pekingese')
            ->and(PetDnaService::hasAppearance('pekingese'))->toBeTrue()
            ->and(config('breed_appearance.pekingese.verified'))->toBeFalse()
            ->and(config('breed_appearance.pekingese.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('pekingese', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('clearly open');

            $prompt = $prompts->imagePrompt('pekingese', $traits);
            expect($prompt)->toContain('Pekingese')
                ->not->toMatch('/\b(merle|albino|liver|brindle|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The eight colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.pekingese.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(8)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.pekingese'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.pekingese'), 'key'))->toBe(['red-gold', 'fawn', 'sable', 'cream', 'black', 'black-tan', 'white', 'parti-colour'])
            ->and(pkData('pekingese.appearance.colours.value'))->toBe(['red', 'fawn', 'sable', 'cream', 'black', 'black and tan', 'white', 'parti-colour']);
    });

    it('draws the register portrait red-gold with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Pekingese);

        expect($traits['coat_color'])->toBe('red-gold with a black mask')
            ->and($portrait->prompt(BreedType::Pekingese))->toContain('Pekingese')->toContain('red-gold')->toContain('clearly open')
            ->and(BreedPortraitService::relativeFile(BreedType::Pekingese))->toBe('dog/pekingese.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Pekingese);
        expect($v1['prompt_anchor'])->toContain('Pekingese')->toContain('clearly open nostrils')
            ->not->toMatch('/\b(merle|albino|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red-gold with a black mask', 'fawn with a black mask']);

        $pet = pkPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Pekingese')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the flat-face chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.pekingese'),
            config('breed_suitability.breeds.pekingese'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pekingese')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%', 'grade 0'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Pekingese);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // PDSA (S693) flat face + hotter days → the flat-face chip (French Bulldog / Pug / Brussels Griffon precedent).
            ->and($tags['consider'])->toContain('brachycephalic_breathing')
            ->and($tags['consider'])->not->toContain('short_muzzle_breathing')->not->toContain('shorter_lifespan')
            ->and(pkData('proposed_game_parameters.pekingese.marketing.source_id'))->toBe('S92')
            ->and(pkData('proposed_game_parameters.pekingese.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (120 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pekingese')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(120)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('has no suits chip; consider the flat face, older children, company, eyelids, skin folds, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Pekingese);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['brachycephalic_breathing', 'older_children', 'needs_company', 'eyelid_problems', 'sensitive_skin', 'sheds', 'frequent_grooming'])
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('not_for_small_children')->not->toContain('long_daily_exercise');

        expect(pkData('pekingese.exercise.adult.quote'))->toContain('half an hour')
            ->and(pkData('pekingese.behaviour.children.quote'))->toContain('older children')
            ->and(pkData('pekingese.behaviour.other_pets.quote'))->toContain('prey drive')
            ->and(pkData('pekingese.behaviour.time_alone.quote'))->toContain('separation anxiety');
    });
});
