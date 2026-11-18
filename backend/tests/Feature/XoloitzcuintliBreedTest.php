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
| M5-R10-123 — Xoloitzcuintli (appended after the Greyhound: AKC 2024 No. 119)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `xoloitzcuintli` (S923–S933) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.xoloitzcuintli` (exercise 20 min — PetMD "about 20 minutes of exercise
| every day"; no RKC / PDSA page reachable —, senior 15 min, puppy 10 min × age capped at 20, stages 9 / 36, arrival
| 2 / 9 / 36, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, puppy socialisation with the PetMD
| quote (M5-R17), the child's tooth-brushing game twice a week (WebMD "two to three times a week", M5-R15); no brushing
| (hairless), no meal gap, no nail / ear / bath / groomer task). The senior boundary / senior arrival 117 (0.75 × PetMD lower
| bound "13–18 years") is Claude's suggestion, verified=false, open for David. Suitability: suits family_pet / children;
| consider sun_protection / cold_sensitive / chases_small_pets; black hairless portrait; no welfare chip, in marketing.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function xoData(string $path): mixed
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

/** @return array<string, mixed> the Xoloitzcuintli seeder row of (stage, from, key) */
function xoRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'xoloitzcuintli'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("xoloitzcuintli.{$stage}.{$from}.{$key->value}");

    return $row;
}

function xoPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->xoloitzcuintli()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function xoPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function xoLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Xoloitzcuintli;
        $config = BreedConfig::where('breed_slug', 'xoloitzcuintli')->sole();

        expect($breed->value)->toBe('xoloitzcuintli')
            ->and($breed->slug())->toBe('xoloitzcuintli')
            ->and(BreedType::fromSlug('xoloitzcuintli'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1140, 'breeds.xoloitzcuintli'])
            ->and($config->search_keywords)->toBe(['xoloitzcuintli', 'xoloitzcuintle', 'xolo', 'mehiški goli pes', 'mehiski goli pes', 'mexican hairless dog', 'mexican hairless', 'sholo', 'goli pes'])
            // Legacy fallback = the adult step goal (20 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) xoData('proposed_game_parameters.xoloitzcuintli.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(2000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = xoData('proposed_game_parameters.xoloitzcuintli.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Xoloitzcuintli pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = xoPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('xoloitzcuintli');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'mexican_hairless']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'xoloitzcuintle']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_04_17_120000_add_xoloitzcuintli_breed.php');
        // down() refuses while a Xoloitzcuintli exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'xoloitzcuintli']))->toThrow(QueryException::class);
        // The Greyhound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'greyhound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'xoloitzcuintli']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Xoloitzcuintli);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'xoloitzcuintli')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')->count());

        BreedConfig::where('breed_slug', 'xoloitzcuintli')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'xoloitzcuintli')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'xoloitzcuintli')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'xoloitzcuintli');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'xoloitzcuintli'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_XOLOITZCUINTLI)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_XOLOITZCUINTLI_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_XOLOITZCUINTLI', 'PROPOSED_R10_XOLOITZCUINTLI_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.xoloitzcuintli';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(xoRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(xoData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(xoRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(xoData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(xoRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(xoData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13 y (PetMD lower bound "13–18 years", S926) = 117 — the closest rule row, Claude's
            // suggestion (no A/B McMillan 2024 / VetCompass / RKC value reachable) → verified=false, open for David.
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(xoData('xoloitzcuintli.lifespan.senior_from.months'))
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(xoData("{$pgp}.senior_boundary_months.value"))
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * xoRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(xoData('xoloitzcuintli.lifespan.senior_from.derived_from'))->toBe(['S11', 'S926'])
            // No McMillan 2024 value reachable — UNSOURCED, never the seeded value.
            ->and(xoData('xoloitzcuintli.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(xoData('xoloitzcuintli.lifespan.mcmillan_2024.source_id'))->toBeNull()
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S926')
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_XOLOITZCUINTLI_SENIOR)
            ->and(xoData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_XOLOITZCUINTLI_SENIOR)
            ->and(xoRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(117);

        expect(xoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(xoData("{$pgp}.exercise_minutes_adult.value"))
            ->and(xoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(20)
            ->and(xoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(xoData('xoloitzcuintli.exercise.adult.quote'))
            ->and(xoRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S926')
            ->and(xoRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(20)
            // 75 % of 20 = 15.
            ->and(xoRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(xoData("{$pgp}.exercise_minutes_senior.value"))
            ->and(xoRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(15)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(xoRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(xoData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(20)
            ->and(xoRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(xoData("{$pgp}.learning_multiplier.value"))
            ->and(xoRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_XOLOITZCUINTLI) {
                $decided++;
                expect($row['breed_slug'])->toBe('xoloitzcuintli')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.xoloitzcuintli.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) xoData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a frequency is built (socialisation M5-R17,
        // teeth M5-R15); no brushing (hairless), no meal gap, no nail / ear / bath / groomer task (no frequency). The sun / cold
        // protection has no mechanic → open for David (needs a new ROADMAP task).
        expect(json_encode([xoData('xoloitzcuintli'), xoData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(xoData($pgp))->not->toHaveKey('grooming')
            ->and(BreedStageParamsSeeder::DOG_GROOMING)->not->toHaveKey('xoloitzcuintli')
            ->and(xoData("{$pgp}.tooth_brushing.decision"))->toContain('M5-R15')
            ->and(xoData("{$pgp}.sun_cold_protection.decision"))->toContain('nova naloga v ROADMAP')
            ->and(xoData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('xoloitzcuintli')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['xoloitzcuintli'])->toBe(['S926', xoData('xoloitzcuintli.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['xoloitzcuintli'][1])->toBe(xoData("{$pgp}.socialisation.quote"));
    });

    it('takes the breed-level numbers from the sourced xoloitzcuintli entries', function () {
        // WebMD (S928) 10–55 lb over the three size varieties — the FCI / UKC standards give no weight.
        expect(xoRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([4.5, 24.9])
            ->and(xoData('xoloitzcuintli.adult_weight.webmd.value'))->toEqual(['male' => [4.5, 24.9], 'female' => [4.5, 24.9]])
            ->and(xoRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(xoData('xoloitzcuintli.adult_weight.webmd.source_id'))
            ->and(xoRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(xoData('xoloitzcuintli.adult_weight.webmd.quote'))
            ->and(xoRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(xoData('xoloitzcuintli.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not in Coren's ranking (S35) → unranked → learning 1.0.
            ->and(xoRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(xoData('xoloitzcuintli.trainability.coren_rank.value'))->toBeNull()
            ->and(xoRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('xoloitzcuintli.lifespan.petmd')
            ->and(xoRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S926')
            ->and(xoRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.0)
            ->and(xoRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(xoData('xoloitzcuintli.lifespan.petmd.quote'));

        // Hairless: no brushing routine. The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')->where('key', StageParamKey::GroomingSessionsPerWeek->value)->all())->toBe([])
            ->and(xoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(xoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S926')
            ->and(xoRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION)
            // No digestion warning (no source).
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Care tasks (David 2026-10-24): only tooth brushing twice a week (WebMD S928 "two to three times a week" → lower bound).
        $tasks = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')
            ->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->all())->toBe([StageParamKey::ToothBrushingFrequency->value => [2, 7]])
            ->and($tasks->pluck('source_id')->unique()->values()->all())->toBe(['S928'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['xoloitzcuintli']['tooth_brushing'][3])->toBe(xoData('proposed_game_parameters.xoloitzcuintli.tooth_brushing.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS['xoloitzcuintli']['tooth_brushing'][3])->toBe(xoData('xoloitzcuintli.suitability.webmd_teeth.quote'));

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli');
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the senior boundary / senior
        // arrival age (117 — Claude's suggestion, no runbook rule produced a value).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_XOLOITZCUINTLI_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.xoloitzcuintli.senior_boundary_months')
                ->and($row['value'])->toBe(xoData('proposed_game_parameters.xoloitzcuintli.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: no brushing routine (hairless) — listed for the comparison like every breed.
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R16 / M5-R17: no meal gap; the breed's own socialisation quote (PetMD S926).
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

        expect($general('xoloitzcuintli'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 20 minutes = 2,000 steps and 15 minutes = 1,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = xoPet($arrival);
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
        'puppy 2 months (10 min × age = the cap)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (capped)' => [5, LifeStage::Puppy, 20, 2000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 20, 2000],
        'young 9 months (capped)' => [9, LifeStage::Young, 20, 2000],
        'adult 36 months' => [36, LifeStage::Adult, 20, 2000],
        'adult 116 months' => [116, LifeStage::Adult, 20, 2000],
        'senior 117 months' => [117, LifeStage::Senior, 15, 1500],
    ]);

    it('becomes senior at 117 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('xoloitzcuintli', 116))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('xoloitzcuintli', 117))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed-breed speed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = xoPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'xoloitzcuintli', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = xoPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(xoLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Xoloitzcuintli)
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
        foreach (['border_collie', 'xoloitzcuintli'] as $breed) {
            $pin = xoPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(xoLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['xoloitzcuintli'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(117)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the appearance data.
            ->and($dog->pet_dna['breed'])->toBe('xoloitzcuintli')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = xoPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Xoloitzcuintli after the Greyhound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'xoloitzcuintli', 'greyhound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'xoloitzcuintli', 'slug' => 'xoloitzcuintli', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.xoloitzcuintli',
            'search_keywords' => seededBreedConfig('xoloitzcuintli')['search_keywords'], 'sort_order' => 1140,
            'suitability' => [
                'suits' => ['family_pet', 'children'],
                'consider' => ['sun_protection', 'cold_sensitive', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI standard S924)', function () {
    it('samples DNA in the six FCI colours of the appearance block only, hairless with a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('xoloitzcuintli')
            ->and(PetDnaService::hasAppearance('xoloitzcuintli'))->toBeTrue()
            ->and(config('breed_appearance.xoloitzcuintli.verified'))->toBeFalse()
            ->and(config('breed_appearance.xoloitzcuintli.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('xoloitzcuintli', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('hairless')
                ->and($traits['tail'])->toContain('natural tail')
                ->and($traits['coat_color'])->toContain('skin');

            $prompt = $prompts->imagePrompt('xoloitzcuintli', $traits);
            expect($prompt)->toContain('Xoloitzcuintli')
                ->not->toMatch('/\b(blue eyes|merle|brindle|cropped|docked tail|long coat|fluffy|sable|black and tan|albino)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The standard colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.xoloitzcuintli.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(6)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.xoloitzcuintli'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.xoloitzcuintli'), 'key'))->toBe(['black', 'slate-grey', 'reddish', 'liver', 'bronze', 'blonde'])
            ->and(xoData('xoloitzcuintli.appearance.colours.value'))->toHaveCount(6)
            ->and(xoData('xoloitzcuintli.appearance.colours.value')[0])->toBe('black');
    });

    it('draws the register portrait with solid black skin', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Xoloitzcuintli);

        expect($traits['coat_color'])->toBe('solid black skin')
            ->and($portrait->prompt(BreedType::Xoloitzcuintli))->toContain('Xoloitzcuintli')->toContain('hairless')
            ->and(BreedPortraitService::relativeFile(BreedType::Xoloitzcuintli))->toBe('dog/xoloitzcuintli.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Xoloitzcuintli);
        expect($v1['prompt_anchor'])->toContain('Xoloitzcuintli')->toContain('hairless')->toContain('natural tail')
            ->not->toMatch('/\b(merle|brindle|docked tail|cropped)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('dark')
            ->and($v1['visual_traits']['color_scheme'])->toBe('solid black skin');

        $pet = xoPet(117, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Xoloitzcuintli')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.xoloitzcuintli'),
            config('breed_suitability.breeds.xoloitzcuintli'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '5%', '30%'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Xoloitzcuintli);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // No veterinary association names the breed → no welfare chip; PetMD "13–18 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'hips_hind_legs', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(xoData('proposed_game_parameters.xoloitzcuintli.marketing.source_id'))->toBe('S926')
            ->and(xoData('proposed_game_parameters.xoloitzcuintli.marketing.value'))->toContain('in marketing: yes')
            // Never hypoallergenic, never low_shedding for the hairless breed (no RKC 'Sheds: No').
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'xoloitzcuintli')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family with children; consider sun, cold and small pets; never small_children, apartment or other_pets', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Xoloitzcuintli);
        expect($tags['suits'])->toBe(['family_pet', 'children'])
            ->and($tags['consider'])->toBe(['sun_protection', 'cold_sensitive', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('needs_company');

        expect(xoData('xoloitzcuintli.exercise.adult.quote'))->toContain('about 20 minutes')
            ->and(xoData('xoloitzcuintli.behaviour.family.quote'))->toContain('good family dog')
            ->and(xoData('xoloitzcuintli.behaviour.children.notes'))->toContain('always be supervised')
            ->and(xoData('xoloitzcuintli.suitability.petmd_sun.quote'))->toContain('sunscreen')
            ->and(xoData('xoloitzcuintli.suitability.vca_cold.quote'))->toContain('sweater')
            ->and(xoData('xoloitzcuintli.behaviour.other_pets.quote'))->toMatch('/(high|strong) prey drive/');
    });
});
