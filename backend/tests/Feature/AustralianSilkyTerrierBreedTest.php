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
| M5-R10-115 — Australian Silky Terrier (appended after the Afghan Hound: AKC 2024 No. 111 "Silky Terrier")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `australian_silky_terrier` (S855–S863) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.australian_silky_terrier` (exercise 30 min — RKC "Up to
| 30 minutes per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 (0.75 × RKC lower bound
| "Over 12 years"), arrival 2 / 9 / 36 / 108, learning × 1.2 (Coren rank 37, Above average), paid breed, Border Collie
| care rates, brushing every day (RKC "Every day", M5-R13), puppy socialisation with the PetMD quote (S857, M5-R17),
| tooth brushing every day (WebMD, M5-R15), ear cleaning every 2 weeks (PetMD "every two to three weeks", M5-R18) and
| the parent's bath reminder every 4 weeks (WebMD "about once a month", M5-R19). RKC Breed Watch Category 1 → no
| welfare chip, in marketing; suits apartment / children; consider sheds / frequent_grooming (no chases_small_pets:
| no verbatim high prey drive); blue and tan portrait (the only standard colour).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function astData(string $path): mixed
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

/** @return array<string, mixed> the Australian Silky Terrier seeder row of (stage, from, key) */
function astRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'australian-silky-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("australian-silky-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function astPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->australianSilkyTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function astPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function astLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AustralianSilkyTerrier;
        $config = BreedConfig::where('breed_slug', 'australian-silky-terrier')->sole();

        expect($breed->value)->toBe('australian_silky_terrier')
            ->and($breed->slug())->toBe('australian-silky-terrier')
            ->and(BreedType::fromSlug('australian-silky-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1080, 'breeds.australian_silky_terrier'])
            ->and($config->search_keywords)->toBe(['australian silky terrier', 'avstralski svilnati terier', 'silky terrier', 'silky', 'silky terier', 'svilnati terier', 'svilni terier', 'terier', 'terrier'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) astData('proposed_game_parameters.australian_silky_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = astData('proposed_game_parameters.australian_silky_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an Australian Silky Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = astPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('australian_silky_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'silky_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'silky']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_05_120000_add_australian_silky_terrier_breed.php');
        // down() refuses while an Australian Silky Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'australian_silky_terrier']))->toThrow(QueryException::class);
        // The Afghan Hound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'afghan_hound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'australian_silky_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AustralianSilkyTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'australian-silky-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-silky-terrier')->count());

        BreedConfig::where('breed_slug', 'australian-silky-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'australian-silky-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'australian-silky-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'australian-silky-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-silky-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'australian-silky-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SILKY_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_AUSTRALIAN_SILKY_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.australian_silky_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(astRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(astData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(astRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(astData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(astRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(astData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S856) = 108 — the runbook order (McMillan 2024 only via
            // Wikipedia, no VetCompass median) → confirmed rule, verified.
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(astData('australian_silky_terrier.lifespan.senior_from.months'))
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * astRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(astData('australian_silky_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S856'])
            // McMillan 2024 via Wikipedia (tier C) is background only — never the seeded boundary.
            ->and(astData('australian_silky_terrier.lifespan.mcmillan_2024.source_id'))->toBe('S862')
            ->and(astData("{$pgp}.stage_boundaries_months.alternatives.senior_mcmillan_2024_via_wikipedia_13_3_y"))->toBe(120)
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', astData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SILKY_TERRIER)
            ->and(astRow('senior', 0, StageParamKey::ArrivalAgeMonths)['verified'])->toBeTrue()
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(astData("{$pgp}.exercise_minutes_adult.value"))
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(astData('australian_silky_terrier.exercise.adult.quote'))
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(astData("{$pgp}.exercise_minutes_adult.quote"))
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S856')
            ->and(astRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(astRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(astData("{$pgp}.exercise_minutes_senior.value"))
            ->and(astRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            ->and(astRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(astData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(astRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(astData("{$pgp}.learning_multiplier.value"))
            ->and(astRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AUSTRALIAN_SILKY_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('australian-silky-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.australian_silky_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) astData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing every day (M5-R13, RKC), puppy socialisation with the PetMD quote (M5-R17),
        // tooth brushing every day (WebMD, M5-R15), ear cleaning every 2 weeks (PetMD, M5-R18) and the bath every 4 weeks
        // (WebMD, M5-R19). No nail / groomer task (no frequency), no meal gap, no heat warning (not flat-faced).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['australian_silky_terrier'];
        expect(json_encode([astData('australian_silky_terrier'), astData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(astData("{$pgp}.grooming.value"))->toBe(7)
            ->and(astData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('australian_silky_terrier')
            ->and(astData('australian_silky_terrier.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['australian_silky_terrier'])->toBe(['S857', astData("{$pgp}.socialisation.quote")])
            ->and(astData("{$pgp}.socialisation.quote"))->toBe(astData('australian_silky_terrier.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'ear_cleaning', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S858', astData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(array_slice($tasks['ear_cleaning'], 0, 5))->toBe([1, 'two_weeks', 'S857', astData("{$pgp}.ear_care.quote"), "{$pgp}.ear_care"])
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S858', astData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(astData("{$pgp}.bathing.quote"))->toBe(astData('australian_silky_terrier.suitability.webmd_bath.quote'))
            ->and(astData("{$pgp}.ear_care.quote"))->toBe(astData('australian_silky_terrier.suitability.petmd_ears.quote'))
            ->and(astData("{$pgp}.tooth_brushing.quote"))->toBe(astData('australian_silky_terrier.suitability.webmd_teeth_nails.quote'))
            ->and($tasks)->not->toHaveKey('groomer_visit')->not->toHaveKey('nail_trim')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('australian_silky_terrier');
    });

    it('takes the breed-level numbers from the sourced australian_silky_terrier entries', function () {
        // PetMD (S857) "around 10 pounds" → 4.5 kg (WebMD "about 10 pounds" agrees; the standards give no weight).
        expect(astRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.5, 4.5])
            ->and(astData('australian_silky_terrier.adult_weight.petmd.value'))->toEqual(['male' => [4.5, 4.5], 'female' => [4.5, 4.5]])
            ->and(astRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(astData('australian_silky_terrier.adult_weight.petmd.source_id'))
            ->and(astRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(astData('australian_silky_terrier.adult_weight.petmd.quote'))
            ->and(astRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(astData('australian_silky_terrier.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 37 → Above average → 1.2.
            ->and(astRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(37)
            ->and(astData('australian_silky_terrier.trainability.coren_rank.value'))->toBe(37)
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('australian_silky_terrier.lifespan.rkc')
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S856')
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(astData('australian_silky_terrier.lifespan.rkc.quote'));

        // Brushing every day: RKC "Grooming: Every day" → 7 (PetMD / WebMD "at least twice a week" = alternative).
        $grooming = astRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S856')
            ->and($grooming['quote'])->toBe(astData('australian_silky_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(astData('proposed_game_parameters.australian_silky_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(astRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(astRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S857')
            ->and(astRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['verified'])->toBeTrue();

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-silky-terrier');
        expect($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'))->pluck('key')->sort()->values()->all())
            ->toBe(collect([StageParamKey::BathFrequency->value, StageParamKey::EarCleaningFrequency->value, StageParamKey::ToothBrushingFrequency->value])->sort()->values()->all())
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
            // M5-R17: socialisation (PetMD S857 quote for this breed).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R15 / R18 / R19: tooth brushing (WebMD S858), ear cleaning (PetMD S857) and the parent's bath reminder (WebMD S858).
            StageParamKey::ToothBrushingFrequency, StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('australian-silky-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = astPet($arrival);
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

    it('becomes senior at 108 months, like the Havanese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('australian-silky-terrier', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('australian-silky-terrier', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at 1.2 × the mixed-breed speed (Coren rank 37, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = astPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'australian_silky_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = astPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(astLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AustralianSilkyTerrier)
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
        foreach (['border_collie', 'australian_silky_terrier'] as $breed) {
            $pin = astPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(astLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['australian_silky_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('australian_silky_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = astPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Australian Silky Terrier after the Afghan Hound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'australian_silky_terrier', 'afghan_hound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'australian_silky_terrier', 'slug' => 'australian-silky-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.australian_silky_terrier',
            'search_keywords' => seededBreedConfig('australian_silky_terrier')['search_keywords'], 'sort_order' => 1080,
            'suitability' => [
                'suits' => ['apartment', 'children'],
                'consider' => ['sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 236 S855 blue and tan)', function () {
    it('samples DNA only in blue and tan — never black, silver, white or a floor-length coat', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('australian_silky_terrier')
            ->and(PetDnaService::hasAppearance('australian_silky_terrier'))->toBeTrue()
            ->and(config('breed_appearance.australian_silky_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.australian_silky_terrier.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('ear_carriage');

        $standard = config('breed_appearance.australian_silky_terrier.traits.coat_color');
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('australian_silky_terrier', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('australian_silky_terrier', $traits);
            expect($prompt)->toContain('Australian Silky Terrier')->toContain('blue and tan')->toContain('never touching the floor')
                ->not->toMatch('/\b(merle|docked|black and tan|silver and white|brindle)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.australian_silky_terrier');
        expect($standard)->toBe(['silver blue and tan: silver to slate blue body with rich tan legs and face, a silver or fawn topknot'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['blue-tan'])
            ->and(astData('australian_silky_terrier.appearance.colours.value'))->toBe(['blue and tan'])
            ->and(astData('australian_silky_terrier.appearance.colours.quote'))->toContain('blue and tan');
    });

    it('draws the register portrait in blue and tan (the only standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AustralianSilkyTerrier);

        expect($traits['coat_color'])->toStartWith('silver blue and tan')
            ->and($portrait->prompt(BreedType::AustralianSilkyTerrier))->toContain('Australian Silky Terrier')->toContain('silver blue and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::AustralianSilkyTerrier))->toBe('dog/australian-silky-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AustralianSilkyTerrier);
        expect($v1['prompt_anchor'])->toContain('Australian Silky Terrier')->toContain('silver blue and tan')
            ->not->toMatch('/\b(docked|merle)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('silver blue and tan');

        $pet = astPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Australian Silky Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.australian_silky_terrier'),
            config('breed_suitability.breeds.australian_silky_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-silky-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '10%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AustralianSilkyTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, no points of concern (S856) → no breathing / hips / back chip, no BVA marketing exclusion.
            ->and($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('short_muzzle_breathing')->not->toContain('hips_hind_legs')->not->toContain('back_spine')
            ->and(astData('australian_silky_terrier.suitability.rkc_breed_watch.value'))->toBe('Category 1')
            ->and(astData('proposed_game_parameters.australian_silky_terrier.marketing.value'))->toContain('in marketing: yes')
            // The alternatives (84 Dogs Trust, 120 McMillan via Wikipedia) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'australian-silky-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84)->not->toContain(120);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a flat and children; consider shedding and daily grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AustralianSilkyTerrier);
        expect($tags['suits'])->toBe(['apartment', 'children'])
            ->and($tags['consider'])->toBe(['sheds', 'frequent_grooming'])
            // PetMD "good around other pets and children if … socialized" → children; WebMD "only moderately good with young
            // children" → never small_children. "the prey drive inherited from their parent breeds" is no verbatim high prey
            // drive → no chases_small_pets (open for David), never other_pets.
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('other_pets')
            ->not->toContain('family_pet')->not->toContain('active_family')->not->toContain('large_home')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('shorter_lifespan')->not->toContain('needs_company')
            ->not->toContain('long_daily_exercise')->not->toContain('not_for_small_children');

        expect(astData('australian_silky_terrier.behaviour.children.quote'))->toBe("they're good around other pets and children if your pup is socialized properly at a young age")
            ->and(astData('australian_silky_terrier.behaviour.other_pets.quote'))->toContain('prey drive')->not->toContain('high prey drive')->not->toContain('strong prey drive')
            ->and(astData('australian_silky_terrier.behaviour.time_alone.quote'))->toBeNull()
            ->and(astData('australian_silky_terrier.suitability.rkc_size_of_home.quote'))->toBe('Flat/ Apartment')
            ->and(astData('australian_silky_terrier.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(astData('australian_silky_terrier.lifespan.rkc.quote'))->toBe('Over 12 years');
    });
});
