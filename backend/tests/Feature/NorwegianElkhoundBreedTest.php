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
| M5-R10-128 — Norwegian Elkhound (appended after the Welsh Terrier: AKC 2024 No. 124)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `norwegian_elkhound` (S960–S966) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.norwegian_elkhound` (exercise 60 min — PetMD "One hour of exercise a day is
| recommended"; no RKC / PDSA page reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 1.2 (Coren rank 36, Above average), paid breed, Border Collie care rates, brushing every day (WebMD
| "every day", M5-R13), puppy socialisation with the PetMD quote (S963, M5-R17), tooth brushing every day (WebMD S964, M5-R15);
| no nail / ear / bath / groomer task, no meal gap, no heat warning). The senior boundary / senior arrival 108 (0.75 × PetMD
| lower bound "12–15 years") is Claude's suggestion, verified=false, open for David. Suitability: suits active_family /
| family_pet / children; consider needs_mental_stimulation / secure_fencing / frequent_grooming / sheds / heat_sensitive;
| grey portrait (the only FCI colour); no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function neData(string $path): mixed
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

/** @return array<string, mixed> the Norwegian Elkhound seeder row of (stage, from, key) */
function neRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'norwegian-elkhound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("norwegian-elkhound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function nePet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->norwegianElkhound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function nePin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function neLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::NorwegianElkhound;
        $config = BreedConfig::where('breed_slug', 'norwegian-elkhound')->sole();

        expect($breed->value)->toBe('norwegian_elkhound')
            ->and($breed->slug())->toBe('norwegian-elkhound')
            ->and(BreedType::fromSlug('norwegian-elkhound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1190, 'breeds.norwegian_elkhound'])
            ->and($config->search_keywords)->toBe(['norwegian elkhound', 'elkhound', 'elkie', 'norsk elghund', 'norveški losovec', 'norveski losovec', 'losovec', 'norveški elkhund'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) neData('proposed_game_parameters.norwegian_elkhound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = neData('proposed_game_parameters.norwegian_elkhound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Norwegian Elkhound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = nePet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('norwegian_elkhound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'elkhound']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'norsk_elghund']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_22_120000_add_norwegian_elkhound_breed.php');
        // down() refuses while a Norwegian Elkhound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'norwegian_elkhound']))->toThrow(QueryException::class);
        // The Welsh Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'welsh_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'norwegian_elkhound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::NorwegianElkhound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'norwegian-elkhound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwegian-elkhound')->count());

        BreedConfig::where('breed_slug', 'norwegian-elkhound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'norwegian-elkhound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'norwegian-elkhound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'norwegian-elkhound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwegian-elkhound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'norwegian-elkhound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_NORWEGIAN_ELKHOUND)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_NORWEGIAN_ELKHOUND_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && ! in_array($name, ['CONFIRMED_R10_NORWEGIAN_ELKHOUND', 'PROPOSED_R10_NORWEGIAN_ELKHOUND_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.norwegian_elkhound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(neRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(neData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(neRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(neData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(neRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(neData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (PetMD lower bound "12–15 years", S963) = 108 — the closest rule row, Claude's
            // suggestion (no A/B McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(neData('norwegian_elkhound.lifespan.senior_from.months'))
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(neData("{$pgp}.senior_boundary_months.value"))
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * neRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(neData('norwegian_elkhound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S963'])
            // McMillan 2024 only via Wikipedia (tier C) — UNSOURCED, never the seeded value (116 is David's alternative).
            ->and(neData('norwegian_elkhound.lifespan.mcmillan_2024.source_id'))->toBeNull()
            ->and(neData("{$pgp}.stage_boundaries_months.alternatives.senior_mcmillan_wikipedia_12_9_y"))->toBe(116)
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S963')
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(neRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeFalse()
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_NORWEGIAN_ELKHOUND_SENIOR)
            ->and(neData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_NORWEGIAN_ELKHOUND_SENIOR)
            ->and(neRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(neRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(neData("{$pgp}.exercise_minutes_adult.value"))
            ->and(neRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(neRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(neData('norwegian_elkhound.exercise.adult.quote'))
            ->and(neRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(neData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(neRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S963')
            ->and(neRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(neRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(neData("{$pgp}.exercise_minutes_senior.value"))
            ->and(neRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(neRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(neData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(neRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(neData("{$pgp}.learning_multiplier.value"))
            ->and(neRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_NORWEGIAN_ELKHOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('norwegian-elkhound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.norwegian_elkhound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) neData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing every day (M5-R13, WebMD), puppy socialisation with the PetMD
        // quote (M5-R17), tooth brushing every day (WebMD, M5-R15), the fence / lead = the secure_fencing chip, the heat = the
        // heat_sensitive chip. No nail / ear / bath / groomer task (no frequency the rules map), no meal gap (no quote), no heat warning.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['norwegian_elkhound'];
        expect(json_encode([neData('norwegian_elkhound'), neData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(neData("{$pgp}.grooming.value"))->toBe(7)
            ->and(neData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('norwegian_elkhound')
            ->and(neData('norwegian_elkhound.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['norwegian_elkhound'])->toBe(['S963', neData("{$pgp}.socialisation.quote")])
            ->and(neData("{$pgp}.socialisation.quote"))->toBe(neData('norwegian_elkhound.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S964', neData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(neData("{$pgp}.tooth_brushing.quote"))->toBe(neData('norwegian_elkhound.suitability.webmd_teeth.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('norwegian_elkhound');
    });

    it('takes the breed-level numbers from the sourced norwegian_elkhound entries', function () {
        // AKC standard (S961): dogs about 55 lb, bitches about 48 lb → 21.8–24.9 kg (the FCI standard gives no weight).
        expect(neRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([21.8, 24.9])
            ->and(neData('norwegian_elkhound.adult_weight.akc.value'))->toEqual(['male' => [24.9, 24.9], 'female' => [21.8, 21.8]])
            ->and(neRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(neData('norwegian_elkhound.adult_weight.akc.source_id'))
            ->and(neRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(neData('norwegian_elkhound.adult_weight.akc.quote'))
            ->and(neRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(neData('norwegian_elkhound.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 36, Above average → 1.2.
            ->and(neRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(36)
            ->and(neData('norwegian_elkhound.trainability.coren_rank.value'))->toBe(36)
            ->and(neData('norwegian_elkhound.trainability.coren_tier.value'))->toBe('Above average')
            ->and(neRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('norwegian_elkhound.lifespan.petmd')
            ->and(neRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S963')
            ->and(neRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(neRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(neData('norwegian_elkhound.lifespan.petmd.quote'));

        // Brushing every day: WebMD "at least two minutes every day" → 7.
        $grooming = neRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S964')
            ->and($grooming['quote'])->toBe(neData('norwegian_elkhound.suitability.webmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(neData('proposed_game_parameters.norwegian_elkhound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(neRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(neRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S963')
            ->and(neRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwegian-elkhound');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($tasks->pluck('source_id')->unique()->sort()->values()->all())->toBe(['S964'])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the senior boundary / senior arrival (Claude's suggestion) and the general teething chewing chance.
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing([
                'senior.'.StageParamKey::StartsAtMonths->value,
                'senior.'.StageParamKey::ArrivalAgeMonths->value,
                'puppy.'.StageParamKey::ChewingChancePerDay->value,
            ]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the socialisation quote (PetMD S963) for this breed.
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15: tooth brushing (WebMD S964).
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('norwegian-elkhound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = nePet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (capped at adult)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months like the Welsh Terrier, later than the Mudi', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('norwegian-elkhound', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('norwegian-elkhound', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('welsh-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mudi', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('norwegian-elkhound', 90))->toBe(LifeStage::Adult);
    });

    it('learns 1.2× faster than the mixed breed (Coren rank 36, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = nePet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'norwegian_elkhound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = nePin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(neLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::NorwegianElkhound)
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
        foreach (['border_collie', 'norwegian_elkhound'] as $breed) {
            $pin = nePin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(neLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['norwegian_elkhound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('norwegian_elkhound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = nePet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Norwegian Elkhound after the Welsh Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'norwegian_elkhound', 'welsh_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'norwegian_elkhound', 'slug' => 'norwegian-elkhound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.norwegian_elkhound',
            'search_keywords' => seededBreedConfig('norwegian_elkhound')['search_keywords'], 'sort_order' => 1190,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['needs_mental_stimulation', 'secure_fencing', 'frequent_grooming', 'sheds', 'heat_sensitive'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 242 S960)', function () {
    it('samples DNA only in the one FCI colour (grey), with erect ears and a curled tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('norwegian_elkhound')
            ->and(PetDnaService::hasAppearance('norwegian_elkhound'))->toBeTrue()
            ->and(config('breed_appearance.norwegian_elkhound.verified'))->toBeFalse()
            ->and(config('breed_appearance.norwegian_elkhound.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.norwegian_elkhound.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('norwegian_elkhound', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('norwegian_elkhound', $traits);
            expect($prompt)->toContain('Norwegian Elkhound')->toContain('erect')->toContain('curled')
                ->not->toMatch('/\b(tricolou?r|merle|brindle|red|black and tan|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.norwegian_elkhound');
        expect($standard)->toBe(['grey'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['grey'])
            ->and(neData('norwegian_elkhound.appearance.colours.value'))->toBe($standard)
            ->and(neData('norwegian_elkhound.appearance.colours.quote'))->toBe('Grey, of various shades.');
    });

    it('draws the register portrait grey (the only standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::NorwegianElkhound);

        expect($traits['coat_color'])->toBe('grey')
            ->and($portrait->prompt(BreedType::NorwegianElkhound))->toContain('Norwegian Elkhound')->toContain('grey')
            ->and(BreedPortraitService::relativeFile(BreedType::NorwegianElkhound))->toBe('dog/norwegian-elkhound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::NorwegianElkhound);
        expect($v1['prompt_anchor'])->toContain('Norwegian Elkhound')->toContain('grey')
            ->not->toMatch('/\b(tricolou?r|merle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBe('grey');

        $pet = nePet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Norwegian Elkhound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.norwegian_elkhound'),
            config('breed_suitability.breeds.norwegian_elkhound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwegian-elkhound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::NorwegianElkhound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed for conformation problems → no welfare chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('eyelid_problems')
            ->and(neData('proposed_game_parameters.norwegian_elkhound.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 116 McMillan via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'norwegian-elkhound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(116);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families, family life and children; consider mental stimulation, a fence, daily brushing, shedding and heat', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::NorwegianElkhound);
        expect(config('breed_suitability.vocabulary.heat_sensitive'))->toBe('consider')
            ->and($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'secure_fencing', 'frequent_grooming', 'sheds', 'heat_sensitive'])
            // PetMD asks for supervision and WebMD names young children → never small_children; no RKC home key fact → never
            // apartment / large_home; 60 min → no long_daily_exercise; PetMD names no prey drive → no chases_small_pets (open for David);
            // no separation statement → no needs_company.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')
            ->not->toContain('needs_company')->not->toContain('shorter_lifespan')->not->toContain('experienced_owner');

        expect(neData('norwegian_elkhound.behaviour.family.quote'))->toBe('When socialized properly as puppies and trained early, Norwegian Elkhounds make wonderful family dogs.')
            ->and(neData('norwegian_elkhound.behaviour.children.quote'))->toContain('supervised')
            ->and(neData('norwegian_elkhound.behaviour.other_pets.quote'))->not->toContain('prey drive')
            ->and(neData('norwegian_elkhound.exercise.fencing.quote'))->toBe("Always keep this dog inside a secure, fenced area or on a leash when they're outside.")
            ->and(neData('norwegian_elkhound.exercise.heat.quote'))->toBe('this thick-coated breed is prone to overheating.')
            ->and(neData('norwegian_elkhound.lifespan.petmd.quote'))->toContain('12–15 years');
    });
});
