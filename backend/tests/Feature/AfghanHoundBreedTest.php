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
| M5-R10-114 — Afghan Hound (appended after the Gordon Setter: AKC 2024 No. 110 "Afghan Hound")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `afghan_hound` (S848–S854) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.afghan_hound` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 90 (0.75 × RKC lower bound "Over 10
| years"), arrival 2 / 9 / 36 / 90, learning × 0.5 (Coren rank 79, Lowest), paid breed, Border Collie care rates,
| brushing every day (RKC "Every day", M5-R13), the meal / exercise warning (PetMD, M5-R16), puppy socialisation with
| the PDSA quote (S851, M5-R17), tooth brushing every day (WebMD, M5-R15) and the parent's bath reminder every week
| (PetMD "at least once every week or two", M5-R19). RKC Breed Watch Category 1 → no welfare chip, in marketing;
| suits active_family / children / large_home; consider long_daily_exercise / sheds / frequent_grooming (no
| chases_small_pets: no verbatim high prey drive); gold with a black mask portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ahData(string $path): mixed
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

/** @return array<string, mixed> the Afghan Hound seeder row of (stage, from, key) */
function ahRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'afghan-hound'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("afghan-hound.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ahPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->afghanHound()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ahPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ahLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AfghanHound;
        $config = BreedConfig::where('breed_slug', 'afghan-hound')->sole();

        expect($breed->value)->toBe('afghan_hound')
            ->and($breed->slug())->toBe('afghan-hound')
            ->and(BreedType::fromSlug('afghan-hound'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1070, 'breeds.afghan_hound'])
            ->and($config->search_keywords)->toBe(['afghan hound', 'afganski hrt', 'afghan', 'afganec', 'hrt', 'tazi', 'baluchi hound', 'sighthound'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ahData('proposed_game_parameters.afghan_hound.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ahData('proposed_game_parameters.afghan_hound.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Afghan Hound pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ahPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('afghan_hound');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'afghan']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'tazi']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_04_120000_add_afghan_hound_breed.php');
        // down() refuses while a Afghan Hound exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'afghan_hound']))->toThrow(QueryException::class);
        // The Gordon Setter (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'gordon_setter']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'afghan_hound']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AfghanHound);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'afghan-hound')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'afghan-hound')->count());

        BreedConfig::where('breed_slug', 'afghan-hound')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'afghan-hound')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'afghan-hound')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'afghan-hound');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'afghan-hound');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'afghan-hound'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AFGHAN_HOUND)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_AFGHAN_HOUND');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.afghan_hound';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ahRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ahData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ahRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ahData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ahRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ahData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S850) = 90 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ahData('afghan_hound.lifespan.senior_from.months'))
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ahRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ahData('afghan_hound.lifespan.senior_from.derived_from'))->toBe(['S11', 'S850'])
            ->and(ahData('afghan_hound.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', ahData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_AFGHAN_HOUND)
            ->and(ahRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(ahRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ahData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ahData('afghan_hound.exercise.adult.quote'))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ahData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(ahRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S850')
            ->and(ahRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(ahRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ahData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ahRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            ->and(ahRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ahData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(ahRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ahData("{$pgp}.learning_multiplier.value"))
            ->and(ahRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AFGHAN_HOUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('afghan-hound')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.afghan_hound.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ahData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, RKC), the meal / exercise warning (M5-R16, PetMD), puppy
        // socialisation with the PDSA quote (M5-R17), tooth brushing every day (WebMD, M5-R15) and the bath every week
        // (PetMD, M5-R19). No ear / nail / groomer task (no frequency), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['afghan_hound'];
        expect(json_encode([ahData('afghan_hound'), ahData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(ahData("{$pgp}.grooming.value"))->toBe(7)
            ->and(ahData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP['afghan_hound'])->toBe(['S852', ahData("{$pgp}.meal_exercise_gap.quote")])
            ->and(ahData("{$pgp}.meal_exercise_gap.quote"))->toBe(ahData('afghan_hound.exercise.after_meals.quote'))
            ->and(ahData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['afghan_hound'])->toBe(['S851', ahData("{$pgp}.socialisation.quote")])
            ->and(ahData("{$pgp}.socialisation.quote"))->toBe(ahData('afghan_hound.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S853', ahData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'week', 'S852', ahData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(ahData("{$pgp}.bathing.quote"))->toBe(ahData('afghan_hound.suitability.petmd_bath.quote'))
            ->and(ahData("{$pgp}.tooth_brushing.quote"))->toBe(ahData('afghan_hound.suitability.webmd_teeth.quote'))
            ->and($tasks)->not->toHaveKey('groomer_visit')->not->toHaveKey('nail_trim')->not->toHaveKey('ear_cleaning')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('afghan_hound');
    });

    it('takes the breed-level numbers from the sourced afghan_hound entries', function () {
        // AKC standard (S854): dogs about 60 lb, bitches about 50 lb → 22.7–27.2 kg (PDSA 23-27 kg = alternative; FCI has no weight).
        expect(ahRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([22.7, 27.2])
            ->and(ahData('afghan_hound.adult_weight.akc.value'))->toEqual(['male' => [27.2, 27.2], 'female' => [22.7, 22.7]])
            ->and(ahRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ahData('afghan_hound.adult_weight.akc.source_id'))
            ->and(ahRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ahData('afghan_hound.adult_weight.akc.quote'))
            ->and(ahRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([15, 18])
            ->and(ahData('afghan_hound.growth.adult_weight_reached.value'))->toStartWith('15–18 months')
            // Coren rank 79 → Lowest → 0.5.
            ->and(ahRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(79)
            ->and(ahData('afghan_hound.trainability.coren_rank.value'))->toBe(79)
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('afghan_hound.lifespan.rkc')
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S850')
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10)
            ->and(ahRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ahData('afghan_hound.lifespan.rkc.quote'));

        // Brushing every day: RKC "Grooming: Every day" → 7 (PDSA "daily brushing" agrees).
        $grooming = ahRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S850')
            ->and($grooming['quote'])->toBe(ahData('afghan_hound.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(ahData('proposed_game_parameters.afghan_hound.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S851')
            ->and(ahRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue()
            ->and(ahRow('all', 0, StageParamKey::MealExerciseGapMinutes)['value'])->toBe(60)
            ->and(ahRow('all', 0, StageParamKey::MealExerciseGapMinutes)['source_id'])->toBe('S852');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'afghan-hound');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::ToothBrushingFrequency->value])->sort()->values()->all())
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
            // M5-R16 / R17: the meal warning (PetMD S852) and socialisation (PDSA S851 quote for this breed).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R19: tooth brushing (WebMD S853) and the parent's bath reminder (PetMD S852).
            StageParamKey::ToothBrushingFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('afghan-hound'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ahPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Gordon Setter', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('afghan-hound', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('afghan-hound', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('gordon-setter', 90))->toBe(LifeStage::Senior);
    });

    it('learns at 0.5 × the mixed-breed speed (Coren rank 79, Lowest) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ahPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'afghan_hound', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ahPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ahLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AfghanHound)
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
        foreach (['border_collie', 'afghan_hound'] as $breed) {
            $pin = ahPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ahLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['afghan_hound'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('afghan_hound')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ahPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Afghan Hound after the Gordon Setter with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'afghan_hound', 'gordon_setter');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'afghan_hound', 'slug' => 'afghan-hound', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.afghan_hound',
            'search_keywords' => seededBreedConfig('afghan_hound')['search_keywords'], 'sort_order' => 1070,
            'suitability' => [
                'suits' => ['active_family', 'children', 'large_home'],
                'consider' => ['long_daily_exercise', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 228 S849 "All colours, except merle")', function () {
    it('samples DNA only in its seven common standard colours — never merle, white head markings or a clipped coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('afghan_hound')
            ->and(PetDnaService::hasAppearance('afghan_hound'))->toBeTrue()
            ->and(config('breed_appearance.afghan_hound.verified'))->toBeFalse()
            ->and(config('breed_appearance.afghan_hound.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.afghan_hound.traits.coat_color'), 'value');
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('afghan_hound', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('afghan_hound', $traits);
            expect($prompt)->toContain('Afghan Hound')->toContain('ring at the end')
                ->not->toMatch('/\b(merle|clipped|docked|white markings)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.afghan_hound');
        expect($standard)->toBe(['golden with a black mask', 'cream', 'red with a black mask', 'black', 'black and tan', 'blue (slate grey)', 'brindle'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['gold-black-mask', 'cream', 'red-black-mask', 'black', 'black-tan', 'blue', 'brindle'])
            ->and(ahData('afghan_hound.appearance.colours.value'))->toBe(['gold_black_mask', 'cream', 'red', 'black', 'black_and_tan', 'blue', 'brindle'])
            ->and(ahData('afghan_hound.appearance.colours.quote'))->toContain('except merle');
    });

    it('draws the register portrait golden with a black mask (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AfghanHound);

        expect($traits['coat_color'])->toBe('golden with a black mask')
            ->and($portrait->prompt(BreedType::AfghanHound))->toContain('Afghan Hound')->toContain('golden with a black mask')
            ->and(BreedPortraitService::relativeFile(BreedType::AfghanHound))->toBe('dog/afghan-hound.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AfghanHound);
        expect($v1['prompt_anchor'])->toContain('Afghan Hound')->toContain('golden with a black mask')
            ->not->toMatch('/\b(clipped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('golden with a black mask');

        $pet = ahPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Afghan Hound')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.afghan_hound'),
            config('breed_suitability.breeds.afghan_hound'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'afghan-hound')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AfghanHound);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, no points of concern (S850), a long muzzle (S849) → no breathing / hips chip,
            // no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')
            ->and(ahData('afghan_hound.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(ahData('proposed_game_parameters.afghan_hound.marketing.value'))->toContain('in marketing: yes')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'afghan-hound')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family with children in a large home; consider long walks, shedding and daily grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AfghanHound);
        expect($tags['suits'])->toBe(['active_family', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'sheds', 'frequent_grooming'])
            // PDSA "if well socialised, are good with children" → children; PetMD: monitor around young children → never
            // small_children. Strong chase instinct / "prey drive nature" is no verbatim high prey drive → no chases_small_pets
            // (open for David), never other_pets.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('family_pet')->not->toContain('apartment')->not->toContain('first_time_owner')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('needs_company')
            ->not->toContain('experienced_owner')->not->toContain('not_for_small_children');

        expect(ahData('afghan_hound.behaviour.children.quote'))->toBe('Afghan Hounds, if well socialised, are good with children.')
            ->and(ahData('afghan_hound.behaviour.other_pets.quote'))->toContain('strong chase instinct')->not->toContain('prey drive')
            ->and(ahData('afghan_hound.behaviour.family.quote'))->toBeNull()
            ->and(ahData('afghan_hound.behaviour.time_alone.quote'))->toBeNull()
            ->and(ahData('afghan_hound.suitability.rkc_size_of_home.quote'))->toBe('Large house')
            ->and(ahData('afghan_hound.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(ahData('afghan_hound.lifespan.rkc.quote'))->toBe('Over 10 years');
    });
});
