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
| M5-R10-117 — Wire Fox Terrier (appended after the Wire Fox Terrier: AKC 2024 No. 113)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `wire_fox_terrier` (S871–S878) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.wire_fox_terrier` (exercise 60 min — RKC "Up to 1 hour per
| day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12 years"),
| arrival 2 / 9 / 36 / 108, learning × 1.0 (Coren 51, Average), paid breed, Border Collie care rates, brushing twice a
| week (RKC "More than once a week" without a number → PDSA "once or twice a week", M5-R13), puppy socialisation with the
| PetMD quote (S874, M5-R17), tooth brushing every day (PetMD, M5-R15), nail trimming every 4 weeks (WebMD, M5-R14), the
| parent's groomer reminder every 12 weeks (PetMD hand-stripping "three to four times a year", M5-R20) and bath reminder
| every 4 weeks (PetMD "about once a month", M5-R19). RKC Breed Watch Category 1 → no welfare chip, in marketing; suits
| active_family / family_pet / children; consider chases_small_pets (shedding sources conflict → no chip); tricolour
| portrait. Not the Toy Fox Terrier (toy_fox_terrier).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function wftData(string $path): mixed
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

/** @return array<string, mixed> the Wire Fox Terrier seeder row of (stage, from, key) */
function wftRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'wire-fox-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("wire-fox-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function wftPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->wireFoxTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function wftPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function wftLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::WireFoxTerrier;
        $config = BreedConfig::where('breed_slug', 'wire-fox-terrier')->sole();

        expect($breed->value)->toBe('wire_fox_terrier')
            ->and($breed->slug())->toBe('wire-fox-terrier')
            ->and(BreedType::fromSlug('wire-fox-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1100, 'breeds.wire_fox_terrier'])
            ->and($config->search_keywords)->toBe(['wire fox terrier', 'ostrodlaki foksterier', 'fox terrier wire', 'wire haired fox terrier', 'wirehaired fox terrier', 'foksterier', 'fox terier', 'foxterier', 'terier', 'terrier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) wftData('proposed_game_parameters.wire_fox_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = wftData('proposed_game_parameters.wire_fox_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Wire Fox Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = wftPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('wire_fox_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'fox_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'smooth_fox_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_09_120000_add_wire_fox_terrier_breed.php');
        // down() refuses while an Wire Fox Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'wire_fox_terrier']))->toThrow(QueryException::class);
        // The Parson Russell Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'parson_russell_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'wire_fox_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::WireFoxTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'wire-fox-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wire-fox-terrier')->count());

        BreedConfig::where('breed_slug', 'wire-fox-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'wire-fox-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'wire-fox-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'wire-fox-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wire-fox-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'wire-fox-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_WIRE_FOX_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_WIRE_FOX_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.wire_fox_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(wftRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(wftData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(wftRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wftData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(wftRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(wftData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S872) = 108 — the runbook order (McMillan 2024 only via
            // Wikipedia, no VetCompass median) → confirmed rule, verified.
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(wftData('wire_fox_terrier.lifespan.senior_from.months'))
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * wftRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(wftData('wire_fox_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S872'])
            // McMillan 2024 via Wikipedia (tier C) is background only — never the seeded boundary.
            ->and(wftData('wire_fox_terrier.lifespan.mcmillan_2024.source_id'))->toBe('S878')
            ->and(wftData("{$pgp}.stage_boundaries_months.alternatives.senior_mcmillan_2024_via_wikipedia_13_5_y"))->toBe(122)
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', wftData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_WIRE_FOX_TERRIER)
            ->and(wftRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(wftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(wftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wftData("{$pgp}.exercise_minutes_adult.value"))
            ->and(wftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(wftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wftData('wire_fox_terrier.exercise.adult.quote'))
            ->and(wftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(wftData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(wftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S872')
            ->and(wftRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(wftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(wftData("{$pgp}.exercise_minutes_senior.value"))
            ->and(wftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(wftRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(wftData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(wftRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(wftData("{$pgp}.learning_multiplier.value"))
            ->and(wftRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_WIRE_FOX_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('wire-fox-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.wire_fox_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) wftData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing twice a week (M5-R13, PDSA), puppy socialisation with the PetMD quote (M5-R17),
        // tooth brushing every day (PetMD, M5-R15), nails every 4 weeks (WebMD, M5-R14), the groomer every 12 weeks (PetMD,
        // M5-R20) and the bath every 4 weeks (PetMD, M5-R19). No ear task (no frequency), no meal gap, no heat warning.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['wire_fox_terrier'];
        expect(json_encode([wftData('wire_fox_terrier'), wftData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(wftData("{$pgp}.grooming.value"))->toBe(2)
            ->and(wftData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('wire_fox_terrier')
            ->and(wftData('wire_fox_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['wire_fox_terrier'])->toBe(['S874', wftData("{$pgp}.socialisation.quote")])
            ->and(wftData("{$pgp}.socialisation.quote"))->toBe(wftData('wire_fox_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim', 'groomer_visit', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S874', wftData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'month', 'S875', wftData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(array_slice($tasks['groomer_visit'], 0, 5))->toBe([1, 'three_months', 'S874', wftData("{$pgp}.professional_grooming.quote"), "{$pgp}.professional_grooming"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S874', wftData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(wftData("{$pgp}.bathing.quote"))->toBe(wftData('wire_fox_terrier.suitability.petmd_bath.quote'))
            ->and(wftData("{$pgp}.nail_trimming.quote"))->toBe(wftData('wire_fox_terrier.suitability.webmd_nails.quote'))
            ->and(wftData("{$pgp}.professional_grooming.quote"))->toBe(wftData('wire_fox_terrier.suitability.petmd_grooming.quote'))
            ->and(wftData("{$pgp}.tooth_brushing.quote"))->toBe(wftData('wire_fox_terrier.suitability.petmd_teeth.quote'))
            ->and($tasks)->not->toHaveKey('ear_cleaning')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('wire_fox_terrier');
    });

    it('takes the breed-level numbers from the sourced wire_fox_terrier entries', function () {
        // AKC standard (S876): dogs 18 lb ± 1, bitches two pounds less → 7.7–8.6 / 6.8–7.7 kg (FCI 8.25 kg males only).
        expect(wftRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([6.8, 8.6])
            ->and(wftData('wire_fox_terrier.adult_weight.akc.value'))->toEqual(['male' => [7.7, 8.6], 'female' => [6.8, 7.7]])
            ->and(wftRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(wftData('wire_fox_terrier.adult_weight.akc.source_id'))
            ->and(wftRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(wftData('wire_fox_terrier.adult_weight.akc.quote'))
            ->and(wftRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(wftData('wire_fox_terrier.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 51, Average → 1.0.
            ->and(wftRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(51)
            ->and(wftData('wire_fox_terrier.trainability.coren_rank.value'))->toBe(51)
            ->and(wftRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('wire_fox_terrier.lifespan.rkc')
            ->and(wftRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S872')
            ->and(wftRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(wftRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(wftData('wire_fox_terrier.lifespan.rkc.quote'));

        // Brushing twice a week: RKC "More than once a week" without a number → PDSA "once or twice a week" → 2.
        $grooming = wftRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S873')
            ->and($grooming['quote'])->toBe(wftData('wire_fox_terrier.suitability.pdsa_grooming.quote'))
            ->and(wftData('wire_fox_terrier.suitability.rkc_grooming.quote'))->toBe('More than once a week')
            ->and($grooming['quote'])->toBe(wftData('proposed_game_parameters.wire_fox_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(wftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(wftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S874')
            ->and(wftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wire-fox-terrier');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::GroomerVisitFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::ToothBrushingFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S874 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R19 / R20: tooth brushing, nails and the parent's groomer and bath reminders (PetMD S874, WebMD S875).
            StageParamKey::ToothBrushingFrequency, StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('wire-fox-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = wftPet($arrival);
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

        expect($service->stageForAge('wire-fox-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('wire-fox-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (Coren 51, Average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = wftPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'wire_fox_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = wftPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(wftLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::WireFoxTerrier)
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
        foreach (['border_collie', 'wire_fox_terrier'] as $breed) {
            $pin = wftPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(wftLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['wire_fox_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('wire_fox_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = wftPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Wire Fox Terrier after the Parson Russell Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'wire_fox_terrier', 'parson_russell_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'wire_fox_terrier', 'slug' => 'wire-fox-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.wire_fox_terrier',
            'search_keywords' => seededBreedConfig('wire_fox_terrier')['search_keywords'], 'sort_order' => 1100,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 169 S871)', function () {
    it('samples DNA only in white with black and tan, tan or black markings — never brindle, red, liver, slate-blue or a docked tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('wire_fox_terrier')
            ->and(PetDnaService::hasAppearance('wire_fox_terrier'))->toBeTrue()
            ->and(config('breed_appearance.wire_fox_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.wire_fox_terrier.prompt_order'))->toContain('coat_color')->toContain('tail')->toContain('ear_carriage');

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.wire_fox_terrier.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('wire_fox_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('wire_fox_terrier', $traits);
            expect($prompt)->toContain('Wire Fox Terrier')->toContain('white with')->toContain('undocked')
                ->not->toMatch('/\b(merle|brindle|liver|slate|solid black)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.wire_fox_terrier');
        expect($standard)->toBe(['white with black and tan markings', 'white with tan markings', 'white with black markings'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['tricolour', 'white-tan', 'white-black'])
            ->and(wftData('wire_fox_terrier.appearance.colours.value'))->toBe(['white with black and tan markings', 'white with tan markings', 'white with black markings'])
            ->and(wftData('wire_fox_terrier.appearance.colours.quote'))->toBe('White predominates with black, black and tan or tan markings.');
    });

    it('draws the register portrait white with black and tan markings (the highest weight)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::WireFoxTerrier);

        expect($traits['coat_color'])->toBe('white with black and tan markings')
            ->and($portrait->prompt(BreedType::WireFoxTerrier))->toContain('Wire Fox Terrier')->toContain('white with black and tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::WireFoxTerrier))->toBe('dog/wire-fox-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::WireFoxTerrier);
        expect($v1['prompt_anchor'])->toContain('Wire Fox Terrier')->toContain('white with black and tan markings')
            ->not->toMatch('/\b(merle|brindle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('white with black and tan markings');

        $pet = wftPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Wire Fox Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.wire_fox_terrier'),
            config('breed_suitability.breeds.wire_fox_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wire-fox-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::WireFoxTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, no points of concern (S872) → no breathing / hips / back chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(wftData('wire_fox_terrier.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(wftData('proposed_game_parameters.wire_fox_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 122 McMillan via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'wire-fox-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(122);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family, a family pet and children; consider small pets — no shedding chip (sources conflict)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::WireFoxTerrier);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['chases_small_pets'])
            // WebMD "great companions for children" + "Always supervise your dog around young kids." → children, never
            // small_children. RKC "Small house" → never apartment; 60 min → never long_daily_exercise; RKC "Sheds: Yes" vs
            // PetMD / WebMD minimal shedding → neither sheds nor low_shedding; no time-alone source → no needs_company.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('apartment')->not->toContain('large_home')->not->toContain('low_shedding')->not->toContain('first_time_owner')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('needs_company')->not->toContain('frequent_grooming')
            ->not->toContain('shorter_lifespan')->not->toContain('long_daily_exercise')->not->toContain('not_for_small_children');

        expect(wftData('wire_fox_terrier.behaviour.children.quote'))->toBe('Wire fox terriers are protective and social, making them great companions for children.')
            ->and(wftData('wire_fox_terrier.behaviour.family.quote'))->toBe('For this reason, they make excellent family pets.')
            ->and(wftData('wire_fox_terrier.behaviour.other_pets.quote'))->toContain('high prey drive')
            ->and(wftData('wire_fox_terrier.behaviour.time_alone.quote'))->toBeNull()
            ->and(wftData('wire_fox_terrier.suitability.rkc_size_of_home.quote'))->toBe('Small house')
            ->and(wftData('wire_fox_terrier.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(wftData('wire_fox_terrier.suitability.petmd_shedding.quote'))->toContain('sheds minimally')
            ->and(wftData('wire_fox_terrier.lifespan.rkc.quote'))->toBe('Over 12 years');
    });
});
