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
| M5-R10-112 — Toy Fox Terrier (appended after the Belgian Tervuren: AKC 2024 No. 108)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `toy_fox_terrier` (S833–S839) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.toy_fox_terrier` (exercise 60 min — PetMD "at least an hour every day";
| no RKC / PDSA page exists for this American breed —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36,
| arrival 2 / 9 / 36, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing twice a week
| (PetMD "once or twice a week", M5-R13), puppy socialisation with the WebMD quote (M5-R17), the child's tooth-brushing game
| every day (M5-R15), ear cleaning every week (M5-R18) and nail trim every 4 weeks (M5-R19, all WebMD); no meal gap, no bath
| reminder). The senior boundary / senior arrival 117 (0.75 × PetMD lower bound "13–15 years") is Claude's suggestion,
| verified=false, open for David. Suitability: no suits chip; consider older_children / needs_company / chases_small_pets;
| tricolour portrait; no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function tftData(string $path): mixed
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

/** @return array<string, mixed> the Toy Fox Terrier seeder row of (stage, from, key) */
function tftRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'toy-fox-terrier'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("toy-fox-terrier.{$stage}.{$from}.{$key->value}");

    return $row;
}

function tftPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->toyFoxTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function tftPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function tftLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ToyFoxTerrier;
        $config = BreedConfig::where('breed_slug', 'toy-fox-terrier')->sole();

        expect($breed->value)->toBe('toy_fox_terrier')
            ->and($breed->slug())->toBe('toy-fox-terrier')
            ->and(BreedType::fromSlug('toy-fox-terrier'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1050, 'breeds.toy_fox_terrier'])
            ->and($config->search_keywords)->toBe(['toy fox terrier', 'toy fox terier', 'tft', 'american toy terrier', 'amertoy'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) tftData('proposed_game_parameters.toy_fox_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = tftData('proposed_game_parameters.toy_fox_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Toy Fox Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = tftPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('toy_fox_terrier');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'amertoy']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'toy-fox-terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_02_120000_add_toy_fox_terrier_breed.php');
        // down() refuses while a Toy Fox Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'toy_fox_terrier']))->toThrow(QueryException::class);
        // The Belgian Tervuren (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'belgian_tervuren']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'toy_fox_terrier']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ToyFoxTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'toy-fox-terrier')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier')->count());

        BreedConfig::where('breed_slug', 'toy-fox-terrier')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'toy-fox-terrier')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'toy-fox-terrier')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'toy-fox-terrier');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'toy-fox-terrier'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_TOY_FOX_TERRIER)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_TOY_FOX_TERRIER_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_TOY_FOX_TERRIER', 'PROPOSED_R10_TOY_FOX_TERRIER_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.toy_fox_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(tftRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(tftData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(tftRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tftData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(tftRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(tftData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13 y (PetMD lower bound "13–15 years", S833) = 117 — the closest rule row, Claude's
            // suggestion (no A/B McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(tftData('toy_fox_terrier.lifespan.senior_from.months'))
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(tftData("{$pgp}.senior_boundary_months.value"))
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * tftRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(tftData('toy_fox_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S833'])
            // McMillan 2024 12.9 y only via Wikipedia (tier C) — background, never the seeded value.
            ->and(tftData('toy_fox_terrier.lifespan.mcmillan_2024.value'))->toBe(12.9)
            ->and(tftData('toy_fox_terrier.lifespan.mcmillan_2024.source_id'))->toBe('S837')
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S833')
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_TOY_FOX_TERRIER_SENIOR)
            ->and(tftData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_TOY_FOX_TERRIER_SENIOR)
            ->and(tftRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(117);

        expect(tftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tftData("{$pgp}.exercise_minutes_adult.value"))
            ->and(tftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(tftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(tftData('toy_fox_terrier.exercise.adult.quote'))
            ->and(tftRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S833')
            ->and(tftRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(tftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(tftData("{$pgp}.exercise_minutes_senior.value"))
            ->and(tftRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(tftRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(tftData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(tftRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(tftData("{$pgp}.learning_multiplier.value"))
            ->and(tftRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_TOY_FOX_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('toy-fox-terrier')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.toy_fox_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) tftData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (brushing M5-R13,
        // socialisation M5-R17, teeth M5-R15, ears M5-R18, nails M5-R19); no meal gap, no bath / groomer reminder (no frequency).
        expect(json_encode([tftData('toy_fox_terrier'), tftData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(tftData("{$pgp}.grooming.value"))->toBe(2)
            ->and(tftData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(tftData("{$pgp}.nail_trimming.decision"))->toContain('M5-R19')
            ->and(tftData("{$pgp}.tooth_brushing.decision"))->toContain('M5-R15')
            ->and(tftData("{$pgp}.ear_care.decision"))->toContain('M5-R18')
            ->and(tftData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('toy_fox_terrier')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['toy_fox_terrier'])->toBe(['S834', tftData('toy_fox_terrier.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['toy_fox_terrier'][1])->toBe(tftData("{$pgp}.socialisation.quote"));
    });

    it('takes the breed-level numbers from the sourced toy_fox_terrier entries', function () {
        // PetMD (S833) "4–9 pounds" for both sexes — the AKC standard gives no weight.
        expect(tftRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([1.8, 4.1])
            ->and(tftData('toy_fox_terrier.adult_weight.petmd.value'))->toEqual(['male' => [1.8, 4.1], 'female' => [1.8, 4.1]])
            ->and(tftRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(tftData('toy_fox_terrier.adult_weight.petmd.source_id'))
            ->and(tftRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(tftData('toy_fox_terrier.adult_weight.petmd.quote'))
            ->and(tftRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(tftData('toy_fox_terrier.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Not in Coren's ranking (S35) → unranked → learning 1.0.
            ->and(tftRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(tftData('toy_fox_terrier.trainability.coren_rank.value'))->toBeNull()
            ->and(tftRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('toy_fox_terrier.lifespan.petmd')
            ->and(tftRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S833')
            ->and(tftRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.0)
            ->and(tftRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(tftData('toy_fox_terrier.lifespan.petmd.quote'));

        // Brushing twice a week (PetMD "once or twice a week" → the upper bound 2) — the rule, verified.
        $grooming = tftRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S833')
            ->and($grooming['quote'])->toBe(tftData('toy_fox_terrier.suitability.petmd_grooming.quote'))
            ->and($grooming['quote'])->toBe(tftData('proposed_game_parameters.toy_fox_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the WebMD quote.
            ->and(tftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(tftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S834')
            ->and(tftRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION)
            // No digestion warning (no source).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Care tasks (David 2026-10-24), all WebMD S834: tooth brushing every day, ear cleaning every week, nail trim every
        // 4 weeks ("every month"); no bath ("occasional baths") or groomer reminder.
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe(collect([
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::NailTrimFrequency->value => [1, 28],
        ])->sortKeys()->all())
            ->and($tasks->pluck('source_id')->unique()->values()->all())->toBe(['S834'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['toy_fox_terrier']['tooth_brushing'][3])->toBe(tftData('proposed_game_parameters.toy_fox_terrier.tooth_brushing.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['toy_fox_terrier']['ear_cleaning'][3])->toBe(tftData('proposed_game_parameters.toy_fox_terrier.ear_care.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['toy_fox_terrier']['nail_trim'][3])->toBe(tftData('proposed_game_parameters.toy_fox_terrier.nail_trimming.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (117 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_TOY_FOX_TERRIER_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.toy_fox_terrier.senior_boundary_months')
                ->and($row['value'])->toBe(tftData('proposed_game_parameters.toy_fox_terrier.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: no meal gap; the breed's own socialisation quote (WebMD S834).
            StageParamKey::MealExerciseGapMinutes, StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('toy-fox-terrier'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = tftPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBe($stage !== LifeStage::Senior);
        // The senior stage rests on the unconfirmed 117-month boundary (and arrival age).
        expect($rules->unverifiedKeys())->toEqualCanonicalizing($stage === LifeStage::Senior
            ? [StageParamKey::StartsAtMonths->value, StageParamKey::ArrivalAgeMonths->value]
            : []);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 116 months' => [116, LifeStage::Adult, 60, 6000],
        'senior 117 months' => [117, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 117 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('toy-fox-terrier', 116))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('toy-fox-terrier', 117))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = tftPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'toy_fox_terrier', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = tftPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(tftLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ToyFoxTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 117 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'toy_fox_terrier'] as $breed) {
            $pin = tftPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(tftLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['toy_fox_terrier'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(117)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('toy_fox_terrier')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = tftPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Toy Fox Terrier after the Belgian Tervuren with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'toy_fox_terrier', 'belgian_tervuren');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'toy_fox_terrier', 'slug' => 'toy-fox-terrier', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.toy_fox_terrier',
            'search_keywords' => seededBreedConfig('toy_fox_terrier')['search_keywords'], 'sort_order' => 1050,
            'suitability' => [
                'suits' => [],
                'consider' => ['older_children', 'needs_company', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — AKC standard S835)', function () {
    it('samples DNA in the four AKC colours of the appearance block only, with a short satiny coat and a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('toy_fox_terrier')
            ->and(PetDnaService::hasAppearance('toy_fox_terrier'))->toBeTrue()
            ->and(config('breed_appearance.toy_fox_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.toy_fox_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('toy_fox_terrier', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('satiny')
                ->and($traits['tail'])->toContain('natural tail')
                ->and($traits['coat_color'])->toContain('predominantly white body');

            $prompt = $prompts->imagePrompt('toy_fox_terrier', $traits);
            expect($prompt)->toContain('Toy Fox Terrier')
                ->not->toMatch('/\b(blue eyes|merle|brindle|cropped|docked tail|long coat|fluffy|hairless|blue coat|grey coat)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.toy_fox_terrier.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(4)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.toy_fox_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.toy_fox_terrier'), 'key'))->toBe(['tricolour', 'chocolate-tan-white', 'tan-white', 'black-white'])
            ->and(tftData('toy_fox_terrier.appearance.colours.value'))->toHaveCount(4)
            ->and(tftData('toy_fox_terrier.appearance.colours.value')[0])->toStartWith('tricolour');
    });

    it('draws the register portrait in tricolour', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ToyFoxTerrier);

        expect($traits['coat_color'])->toStartWith('tricolour: predominantly white body')
            ->and($portrait->prompt(BreedType::ToyFoxTerrier))->toContain('Toy Fox Terrier')->toContain('black head with sharply defined tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::ToyFoxTerrier))->toBe('dog/toy-fox-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ToyFoxTerrier);
        expect($v1['prompt_anchor'])->toContain('Toy Fox Terrier')->toContain('black head with sharply defined tan markings')->toContain('natural tail')
            ->not->toMatch('/\b(merle|brindle|docked tail|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['tricolour: white body, black head with tan markings', 'white body with a tan head', 'white body with a black head']);

        $pet = tftPet(117, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Toy Fox Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.toy_fox_terrier'),
            config('breed_suitability.breeds.toy_fox_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ToyFoxTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; PetMD "13–15 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(tftData('proposed_game_parameters.toy_fox_terrier.marketing.source_id'))->toBe('S833')
            ->and(tftData('proposed_game_parameters.toy_fox_terrier.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic; no RKC 'Sheds: No' → no low_shedding chip.
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'toy-fox-terrier')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('has no suits chip; consider older children, company and small pets; never children, small_children, apartment or other_pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ToyFoxTerrier);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['older_children', 'needs_company', 'chases_small_pets'])
            // WebMD advises against small children; "apartments" is not the RKC key fact; prey drive vs "accommodate other pets".
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('not_for_small_children')->not->toContain('long_daily_exercise');

        expect(tftData('toy_fox_terrier.exercise.adult.quote'))->toContain('at least an hour every day')
            ->and(tftData('toy_fox_terrier.behaviour.children.quote'))->toContain('not recommended for families with small children')
            ->and(tftData('toy_fox_terrier.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(tftData('toy_fox_terrier.behaviour.other_pets.quote'))->toMatch('/(high|strong) prey drive/');
    });
});
