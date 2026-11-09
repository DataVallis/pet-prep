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
| M5-R10-98 — Lhasa Apso (appended after the English Setter: AKC 2024 No. 94 "Lhasa Apso")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `lhasa_apso` (S737–S745) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.lhasa_apso` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12
| years"), arrival 2 / 9 / 36 / 108, learning × 0.7 (Coren rank 68, Fair), paid breed, Border Collie care rates,
| brushing every day (RKC "Every day", M5-R13), puppy socialisation with the PDSA quote (S741, M5-R17), the child's
| tooth brushing every day (M5-R15) and nail trimming every 4 weeks (M5-R18), the parent's bath reminder every 2 weeks
| (M5-R19) — all from WebMD (S743). RKC Breed Watch Category 1 → in marketing; suits apartment / children / other_pets /
| low_shedding; consider frequent_grooming; golden portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function laData(string $path): mixed
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

/** @return array<string, mixed> the Lhasa Apso seeder row of (stage, from, key) */
function laRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'lhasa-apso'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("lhasa-apso.{$stage}.{$from}.{$key->value}");

    return $row;
}

function laPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->lhasaApso()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function laPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function laLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::LhasaApso;
        $config = BreedConfig::where('breed_slug', 'lhasa-apso')->sole();

        expect($breed->value)->toBe('lhasa_apso')
            ->and($breed->slug())->toBe('lhasa-apso')
            ->and(BreedType::fromSlug('lhasa-apso'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 920, 'breeds.lhasa_apso'])
            ->and($config->search_keywords)->toBe(['lhasa apso', 'lhasa', 'apso', 'lasa apso', 'tibetan dog', 'tibetanski pes'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) laData('proposed_game_parameters.lhasa_apso.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = laData('proposed_game_parameters.lhasa_apso.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Lhasa Apso pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = laPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('lhasa_apso');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'lhasa']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'apso']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_17_120000_add_lhasa_apso_breed.php');
        // down() refuses while a Lhasa Apso exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'lhasa_apso']))->toThrow(QueryException::class);
        // The English Setter (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'english_setter']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'lhasa_apso']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::LhasaApso);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'lhasa-apso')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'lhasa-apso')->count());

        BreedConfig::where('breed_slug', 'lhasa-apso')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'lhasa-apso')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'lhasa-apso')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'lhasa-apso');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'lhasa-apso');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'lhasa-apso'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_LHASA_APSO)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_LHASA_APSO');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.lhasa_apso';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(laRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(laData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(laRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(laData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(laRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(laData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S740) = 108 — the runbook order (McMillan 2024 not reachable,
            // no VetCompass median) → confirmed rule, verified.
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(laData('lhasa_apso.lifespan.senior_from.months'))
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * laRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(laData('lhasa_apso.lifespan.senior_from.derived_from'))->toBe(['S11', 'S740'])
            ->and(laData('lhasa_apso.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', laData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_LHASA_APSO)
            ->and(laRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(laRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(laRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(laData("{$pgp}.exercise_minutes_adult.value"))
            ->and(laRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(laRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(laData('lhasa_apso.exercise.adult.quote'))
            ->and(laRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(laData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(laRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S740')
            ->and(laRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(laRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(laData("{$pgp}.exercise_minutes_senior.value"))
            ->and(laRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(laRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(laData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(laRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(laData("{$pgp}.learning_multiplier.value"))
            ->and(laRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_LHASA_APSO) {
                $decided++;
                expect($row['breed_slug'])->toBe('lhasa-apso')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.lhasa_apso.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) laData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, RKC / PDSA), puppy socialisation with the PDSA quote (M5-R17),
        // tooth brushing every day, nail trimming every 4 weeks and bath every 2 weeks (WebMD, M5-R15 / R18 / R19). No meal gap,
        // no ear / groomer task (no frequency), no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['lhasa_apso'];
        expect(json_encode([laData('lhasa_apso'), laData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(laData("{$pgp}.grooming.value"))->toBe(7)
            ->and(laData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('lhasa_apso')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['lhasa_apso'])->toBe(['S741', laData("{$pgp}.socialisation.quote")])
            ->and(laData("{$pgp}.socialisation.quote"))->toBe(laData('lhasa_apso.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S743', laData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'month', 'S743', laData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'two_weeks', 'S743', laData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(laData("{$pgp}.tooth_brushing.quote"))->toBe(laData('lhasa_apso.suitability.webmd_teeth.quote'))
            ->and(laData("{$pgp}.nail_trimming.quote"))->toBe(laData('lhasa_apso.suitability.webmd_nails.quote'))
            ->and(laData("{$pgp}.bathing.quote"))->toBe(laData('lhasa_apso.suitability.webmd_bath.quote'))
            ->and($tasks)->not->toHaveKey('ear_cleaning')->not->toHaveKey('groomer_visit')->not->toHaveKey('fold_cleaning')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('lhasa_apso');
    });

    it('takes the breed-level numbers from the sourced lhasa_apso entries', function () {
        // No standard gives a weight → VCA (S742) 13–15 lb = 5.9–6.8 kg.
        expect(laRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5.9, 6.8])
            ->and(laData('lhasa_apso.adult_weight.vca.value'))->toEqual(['male' => [5.9, 6.8], 'female' => [5.9, 6.8]])
            ->and(laRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(laData('lhasa_apso.adult_weight.vca.source_id'))
            ->and(laRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(laData('lhasa_apso.adult_weight.vca.quote'))
            ->and(laRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(laData('lhasa_apso.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 68 (Fair) → learning 0.7.
            ->and(laRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(68)
            ->and(laData('lhasa_apso.trainability.coren_rank.value'))->toBe(68)
            ->and(laRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('lhasa_apso.lifespan.rkc')
            ->and(laRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S740')
            ->and(laRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(laRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(laData('lhasa_apso.lifespan.rkc.quote'));

        // Brushing every day: RKC "Grooming: Every day" → 7 (PDSA "daily" agrees).
        $grooming = laRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S740')
            ->and($grooming['quote'])->toBe(laData('lhasa_apso.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(laData('proposed_game_parameters.lhasa_apso.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(laRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(laRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S741')
            ->and(laRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'lhasa-apso');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::ToothBrushingFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::BathFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PDSA S741 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R18 / R19: the WebMD care tasks (S743).
            StageParamKey::ToothBrushingFrequency, StageParamKey::NailTrimFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('lhasa-apso'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = laPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, like the Havanese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('lhasa-apso', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('lhasa-apso', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at 0.7 × the mixed-breed speed (Coren rank 68, Fair) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = laPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'lhasa_apso', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = laPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(laLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::LhasaApso)
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
        foreach (['border_collie', 'lhasa_apso'] as $breed) {
            $pin = laPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(laLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['lhasa_apso'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('lhasa_apso')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = laPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Lhasa Apso after the English Setter with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'lhasa_apso', 'english_setter');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'lhasa_apso', 'slug' => 'lhasa-apso', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.lhasa_apso',
            'search_keywords' => seededBreedConfig('lhasa_apso')['search_keywords'], 'sort_order' => 920,
            'suitability' => [
                'suits' => ['apartment', 'children', 'other_pets', 'low_shedding'],
                'consider' => ['frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 227 S738)', function () {
    it('samples DNA in the ten standard colours only, never merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('lhasa_apso')
            ->and(PetDnaService::hasAppearance('lhasa_apso'))->toBeTrue()
            ->and(config('breed_appearance.lhasa_apso.verified'))->toBeFalse()
            ->and(config('breed_appearance.lhasa_apso.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = array_column(config('breed_appearance.lhasa_apso.traits.coat_color'), 'value');
        foreach (range(1, 50) as $seed) {
            $traits = $dna->sample('lhasa_apso', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('lhasa_apso', $traits);
            expect($prompt)->toContain('Lhasa Apso')->toContain('one-third of the head length')
                ->not->toMatch('/\b(merle|brindle|flat face|flat-faced)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.lhasa_apso');
        expect($standard)->toBe(['golden', 'sandy', 'honey', 'dark grizzle', 'slate grey', 'smoke grey', 'parti-colour (white with coloured patches)', 'black', 'white', 'brown'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['golden', 'sandy', 'honey', 'dark-grizzle', 'slate', 'smoke', 'parti-colour', 'black', 'white', 'brown'])
            ->and(count(laData('lhasa_apso.appearance.colours.value')))->toBe(count($standard))
            // Golden carries the highest weight → the portrait default.
            ->and(config('breed_appearance.lhasa_apso.traits.coat_color.0.weight'))->toBe(max(array_column(config('breed_appearance.lhasa_apso.traits.coat_color'), 'weight')));
    });

    it('draws the register portrait in golden', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::LhasaApso);

        expect($traits['coat_color'])->toBe('golden')
            ->and($portrait->prompt(BreedType::LhasaApso))->toContain('Lhasa Apso')->toContain('golden')
            ->and(BreedPortraitService::relativeFile(BreedType::LhasaApso))->toBe('dog/lhasa-apso.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::LhasaApso);
        expect($v1['prompt_anchor'])->toContain('Lhasa Apso')->toContain('golden')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('golden');

        $pet = laPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Lhasa Apso')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.lhasa_apso'),
            config('breed_suitability.breeds.lhasa_apso'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'lhasa-apso')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::LhasaApso);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1 (S740), muzzle about one-third of the head (S738) → no breathing chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')
            ->and(laData('lhasa_apso.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(laData('proposed_game_parameters.lhasa_apso.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (126 Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'lhasa-apso')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(126)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an apartment, children and other pets, low shedding; consider daily grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::LhasaApso);
        expect($tags['suits'])->toBe(['apartment', 'children', 'other_pets', 'low_shedding'])
            ->and($tags['consider'])->toBe(['frequent_grooming'])
            // PDSA's children statement has a socialisation condition → never small_children; no family sentence → no family_pet.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('often_alone')
            ->not->toContain('large_home')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('shorter_lifespan')->not->toContain('chases_small_pets')
            ->not->toContain('needs_company')->not->toContain('experienced_owner');

        expect(laData('lhasa_apso.behaviour.family.value'))->toBeNull()
            ->and(laData('lhasa_apso.suitability.pdsa_children.quote'))->toContain('given the right socialisation as puppies')
            ->and(laData('lhasa_apso.behaviour.time_alone.value'))->toBeNull()
            ->and(laData('lhasa_apso.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(laData('lhasa_apso.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(laData('lhasa_apso.lifespan.rkc.quote'))->toBe('Lifespan: Over 12 years');
    });
});
