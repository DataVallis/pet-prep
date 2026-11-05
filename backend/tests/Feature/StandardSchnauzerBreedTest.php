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
| M5-R10-95 — Standard Schnauzer (appended after the Keeshond: AKC 2024 No. 91 "Standard Schnauzer")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `standard_schnauzer` (S711–S720) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.standard_schnauzer` (exercise 60 min — RKC "Up to 1 hour per
| day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10 years"),
| arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren rank 18, Excellent), paid breed, Border Collie care rates, brushing 3× a
| week (PDSA "2-3 times a week", M5-R13), puppy socialisation with the PetMD quote (S717, M5-R17), the child's nail trim and
| ear cleaning once a week (PetMD "every one to two weeks", M5-R19 / M5-R20), the parent's bath reminder every 4 weeks and
| groomer reminder every 12 weeks (PetMD "Monthly" / "quarterly", M5-R19 / M5-R18). RKC Breed Watch Category 1 → in
| marketing; suits family_pet / children; consider experienced_owner / needs_mental_stimulation / frequent_grooming;
| pepper-and-salt portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ssData(string $path): mixed
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

/** @return array<string, mixed> the Standard Schnauzer seeder row of (stage, from, key) */
function ssRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'standard-schnauzer'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("standard-schnauzer.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ssPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->standardSchnauzer()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ssPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ssLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::StandardSchnauzer;
        $config = BreedConfig::where('breed_slug', 'standard-schnauzer')->sole();

        expect($breed->value)->toBe('standard_schnauzer')
            ->and($breed->slug())->toBe('standard-schnauzer')
            ->and(BreedType::fromSlug('standard-schnauzer'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 890, 'breeds.standard_schnauzer'])
            ->and($config->search_keywords)->toBe(['standard schnauzer', 'srednji šnavcer', 'srednji snavcer', 'mittelschnauzer', 'schnauzer', 'šnavcer'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ssData('proposed_game_parameters.standard_schnauzer.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ssData('proposed_game_parameters.standard_schnauzer.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Standard Schnauzer pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ssPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('standard_schnauzer');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'schnauzer']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'mittelschnauzer']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_13_120000_add_standard_schnauzer_breed.php');
        // down() refuses while a Standard Schnauzer exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'standard_schnauzer']))->toThrow(QueryException::class);
        // The Keeshond (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'keeshond']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'standard_schnauzer']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::StandardSchnauzer);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'standard-schnauzer')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'standard-schnauzer')->count());

        BreedConfig::where('breed_slug', 'standard-schnauzer')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'standard-schnauzer')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'standard-schnauzer')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'standard-schnauzer');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'standard-schnauzer');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'standard-schnauzer'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_SCHNAUZER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_STANDARD_SCHNAUZER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.standard_schnauzer';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ssRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ssData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ssRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ssData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ssRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ssData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S714) = 90 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ssData('standard_schnauzer.lifespan.senior_from.months'))
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ssRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ssData('standard_schnauzer.lifespan.senior_from.derived_from'))->toBe(['S11', 'S714'])
            ->and(ssData('standard_schnauzer.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', ssData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_SCHNAUZER)
            ->and(ssRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(ssRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(ssRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ssData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ssRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ssRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ssData('standard_schnauzer.exercise.adult.quote'))
            ->and(ssRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ssData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ssRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S714')
            ->and(ssRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ssRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ssData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ssRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(ssRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ssData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(ssRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ssData("{$pgp}.learning_multiplier.value"))
            ->and(ssRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_STANDARD_SCHNAUZER) {
                $decided++;
                expect($row['breed_slug'])->toBe('standard-schnauzer')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.standard_schnauzer.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ssData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing 3× a week (M5-R13, PDSA), puppy socialisation with the PetMD quote (M5-R17),
        // nail trim + ear cleaning once a week, bath every 4 weeks, groomer every 12 weeks (PetMD, M5-R18 / R19 / R20). No
        // meal gap, no tooth task, no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['standard_schnauzer'];
        expect(json_encode([ssData('standard_schnauzer'), ssData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ssData("{$pgp}.grooming.value"))->toBe(3)
            ->and(ssData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('standard_schnauzer')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['standard_schnauzer'])->toBe(['S717', ssData("{$pgp}.socialisation.quote")])
            ->and(ssData("{$pgp}.socialisation.quote"))->toBe(ssData('standard_schnauzer.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['nail_trim', 'ear_cleaning', 'bath', 'groomer_visit'])
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'week', 'S717', ssData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'week', 'S717', ssData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S717', ssData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'three_months', 'S717', ssData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(ssData("{$pgp}.nail_trimming.quote"))->toBe(ssData('standard_schnauzer.suitability.petmd_grooming.quote'))
            ->and(ssData("{$pgp}.bathing.quote"))->toBe(ssData('standard_schnauzer.suitability.petmd_bath.quote'))
            ->and(ssData("{$pgp}.professional_grooming.quote"))->toBe(ssData('standard_schnauzer.suitability.petmd_groomer.quote'))
            ->and($tasks)->not->toHaveKey('tooth_brushing')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('standard_schnauzer');
    });

    it('takes the breed-level numbers from the sourced standard_schnauzer entries', function () {
        // FCI (S712) "14 to 20 kg" for both sexes.
        expect(ssRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([14, 20])
            ->and(ssData('standard_schnauzer.adult_weight.fci.value'))->toEqual(['male' => [14, 20], 'female' => [14, 20]])
            ->and(ssRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ssData('standard_schnauzer.adult_weight.fci.source_id'))
            ->and(ssRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ssData('standard_schnauzer.adult_weight.fci.quote'))
            ->and(ssRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(ssData('standard_schnauzer.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 18 (Excellent) → learning 1.5.
            ->and(ssRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(18)
            ->and(ssData('standard_schnauzer.trainability.coren_rank.value'))->toBe(18)
            ->and(ssRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('standard_schnauzer.lifespan.rkc')
            ->and(ssRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S714')
            ->and(ssRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(ssRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ssData('standard_schnauzer.lifespan.rkc.quote'));

        // Brushing 3× a week: RKC "More than once a week" without a number → PDSA "2-3 times a week" → upper bound 3.
        $grooming = ssRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S715')
            ->and($grooming['quote'])->toBe(ssData('standard_schnauzer.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(ssData('proposed_game_parameters.standard_schnauzer.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(ssRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ssRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S717')
            ->and(ssRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'standard-schnauzer');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::NailTrimFrequency->value, StageParamKey::EarCleaningFrequency->value, StageParamKey::BathFrequency->value, StageParamKey::GroomerVisitFrequency->value])->sort()->values()->all())
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: socialisation (PetMD S717 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R18 / R19 / R20: the PetMD care tasks (S717).
            StageParamKey::NailTrimFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency, StageParamKey::GroomerVisitFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('standard-schnauzer'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ssPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, like the Keeshond', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('standard-schnauzer', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('standard-schnauzer', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('keeshond', 90))->toBe(LifeStage::Senior);
    });

    it('learns at 1.5 × the mixed-breed speed (Coren Excellent tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ssPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'standard_schnauzer', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ssPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ssLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::StandardSchnauzer)
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
        foreach (['border_collie', 'standard_schnauzer'] as $breed) {
            $pin = ssPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ssLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['standard_schnauzer'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('standard_schnauzer')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ssPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Standard Schnauzer after the Keeshond with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'standard_schnauzer', 'keeshond');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'standard_schnauzer', 'slug' => 'standard-schnauzer', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.standard_schnauzer',
            'search_keywords' => seededBreedConfig('standard_schnauzer')['search_keywords'], 'sort_order' => 890,
            'suitability' => [
                'suits' => ['family_pet', 'children'],
                'consider' => ['experienced_owner', 'needs_mental_stimulation', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 182 S712)', function () {
    it('samples DNA in pepper and salt or pure black only, never black and silver, with natural ears and tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('standard_schnauzer')
            ->and(PetDnaService::hasAppearance('standard_schnauzer'))->toBeTrue()
            ->and(config('breed_appearance.standard_schnauzer.verified'))->toBeFalse()
            ->and(config('breed_appearance.standard_schnauzer.prompt_order'))->toContain('head')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.standard_schnauzer.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('standard_schnauzer', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color'])
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['tail'])->toContain('undocked');

            $prompt = $prompts->imagePrompt('standard_schnauzer', $traits);
            expect($prompt)->toContain('Standard Schnauzer')
                ->not->toMatch('/\b(merle|brown|silver|white|cropped ears|docked tail)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // Same colour keys / values / labels as the Miniature and Giant Schnauzer, minus black and silver.
        $colours = config('breed_colours.standard_schnauzer');
        expect($standard)->toBe(['pepper and salt (banded grey) with a dark facial mask', 'solid black'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['pepper-salt', 'black'])
            ->and($colours)->toBe(array_values(array_filter(config('breed_colours.miniature_schnauzer'), fn (array $c) => $c['key'] !== 'black-silver')))
            ->and(ssData('standard_schnauzer.appearance.colours.value'))->toBe(['pepper_and_salt', 'black']);
    });

    it('draws the register portrait in pepper and salt', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::StandardSchnauzer);

        expect($traits['coat_color'])->toStartWith('pepper and salt')
            ->and($portrait->prompt(BreedType::StandardSchnauzer))->toContain('Standard Schnauzer')->toContain('pepper and salt')
            ->and(BreedPortraitService::relativeFile(BreedType::StandardSchnauzer))->toBe('dog/standard-schnauzer.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::StandardSchnauzer);
        expect($v1['prompt_anchor'])->toContain('Standard Schnauzer')->toContain('pepper and salt')
            ->not->toMatch('/\b(merle|brown|silver)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and(['pepper and salt with a dark facial mask', 'solid black'])->toContain($v1['visual_traits']['color_scheme']);

        $pet = ssPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Standard Schnauzer')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.standard_schnauzer'),
            config('breed_suitability.breeds.standard_schnauzer'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'standard-schnauzer')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::StandardSchnauzer);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S714), blunt-wedge muzzle (S712) → no breathing chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(ssData('standard_schnauzer.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(ssData('proposed_game_parameters.standard_schnauzer.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (117 Wikipedia, 108 VCA, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'standard-schnauzer')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(117)->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family with children; consider an experienced owner, mental stimulation and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::StandardSchnauzer);
        expect($tags['suits'])->toBe(['family_pet', 'children'])
            ->and($tags['consider'])->toBe(['experienced_owner', 'needs_mental_stimulation', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->not->toContain('low_shedding')->not->toContain('other_pets')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('shorter_lifespan')->not->toContain('chases_small_pets');

        expect(ssData('standard_schnauzer.behaviour.family.quote'))->toContain('devoted to their family')
            ->and(ssData('standard_schnauzer.suitability.pdsa_children.quote'))->toContain('introduced at an early age')
            ->and(ssData('standard_schnauzer.suitability.vca_children.quote'))->toBe('Playful and good with children.')
            ->and(ssData('standard_schnauzer.behaviour.experienced_owner.quote'))->toContain('experienced pet parents')
            ->and(ssData('standard_schnauzer.behaviour.mental_stimulation.quote'))->toContain('mental exercise')
            ->and(ssData('standard_schnauzer.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house')
            ->and(ssData('standard_schnauzer.lifespan.rkc.quote'))->toBe('Lifespan: Over 10 years');
    });
});
