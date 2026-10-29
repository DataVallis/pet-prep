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
| M5-R10-78 — Pyrenean Mountain Dog (appended after the Miniature Pinscher: AKC 2024 No. 74 "Great Pyrenees")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `pyrenean_mountain_dog` (S599–S607) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.pyrenean_mountain_dog` (exercise 60 min — RKC "Exercise:
| Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36, arrival 2 / 9 / 36, learning
| × 0.7 (Coren rank 64, Fair), paid breed, Border Collie care rates, brushing 1× a week (RKC "More than once a week"
| without a number → VCA / PetMD weekly, M5-R13), puppy socialisation with the VCA quote (M5-R17), the 60-minute
| digestion warning (PetMD "Avoid vigorous exercise around mealtimes", M5-R16), the child's nail trim and ear cleaning
| once a week (VCA "weekly pedicure and ear cleaning", M5-R19 / R20)). The senior boundary / senior arrival 90 (0.75 ×
| PetMD lower bound 10 y — the RKC band "Under 10 years" has no lower bound) is Claude's suggestion, verified=false, open
| for David. Suitability: suits children, large_home, other_pets; consider not_for_small_children, sheds,
| frequent_grooming, shorter_lifespan. PDSA weight 40–50 kg (the FCI standard gives none); pure white portrait; no
| welfare chip (the RKC Breed Watch pages disagree — open for David) → in marketing.
*/

const PMD_KEY = 'pyrenean_mountain_dog';
const PMD_SLUG = 'pyrenean-mountain-dog';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pmdData(string $path): mixed
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

/** @return array<string, mixed> the Pyrenean Mountain Dog seeder row of (stage, from, key) */
function pmdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === PMD_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(PMD_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function pmdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->pyreneanMountainDog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pmdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pmdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::PyreneanMountainDog;
        $config = BreedConfig::where('breed_slug', PMD_SLUG)->sole();

        expect($breed->value)->toBe(PMD_KEY)
            ->and($breed->slug())->toBe(PMD_SLUG)
            ->and(BreedType::fromSlug(PMD_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 720, 'breeds.pyrenean_mountain_dog'])
            ->and($config->search_keywords)->toBe(['pyrenean mountain dog', 'great pyrenees', 'pyrenees', 'pyr', 'patou', 'chien de montagne des pyrenees', 'pirenejski gorski pes', 'pirenejski pes', 'pirenejec', 'pirenejski planšar'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pmdData('proposed_game_parameters.pyrenean_mountain_dog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pmdData('proposed_game_parameters.pyrenean_mountain_dog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Pyrenean Mountain Dog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pmdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(PMD_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'great_pyrenees']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'pyrenean_mastiff']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_12_120000_add_pyrenean_mountain_dog_breed.php');
        // down() refuses while a Pyrenean Mountain Dog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => PMD_KEY]))->toThrow(QueryException::class);
        // The Miniature Pinscher (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'miniature_pinscher']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => PMD_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::PyreneanMountainDog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', PMD_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', PMD_SLUG)->count());

        BreedConfig::where('breed_slug', PMD_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', PMD_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', PMD_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', PMD_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', PMD_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, PMD_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PYRENEAN_MOUNTAIN_DOG)->all())->toBe([])
            ->and($others->where('decision', BreedStageParamsSeeder::PROPOSED_R10_PYRENEAN_MOUNTAIN_DOG_SENIOR)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && ! in_array($name, ['CONFIRMED_R10_PYRENEAN_MOUNTAIN_DOG', 'PROPOSED_R10_PYRENEAN_MOUNTAIN_DOG_SENIOR'], true));
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.pyrenean_mountain_dog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pmdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pmdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pmdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pmdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pmdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pmdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (PetMD lower bound "10–12 years", S604) = 90 — the closest rule row, Claude's
            // suggestion (RKC "Under 10 years" has no lower bound, no McMillan 2024 / VetCompass value) → verified=false.
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pmdData('pyrenean_mountain_dog.lifespan.senior_from.months'))
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pmdData("{$pgp}.senior_boundary_months.value"))
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pmdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pmdData('pyrenean_mountain_dog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S604'])
            ->and(pmdData('pyrenean_mountain_dog.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S604')
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeFalse()
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_PYRENEAN_MOUNTAIN_DOG_SENIOR)
            ->and(pmdData("{$pgp}.senior_boundary_months.decision"))->toStartWith(BreedStageParamsSeeder::PROPOSED_R10_PYRENEAN_MOUNTAIN_DOG_SENIOR)
            ->and(pmdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(pmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pmdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(pmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(pmdData('pyrenean_mountain_dog.exercise.adult.quote'))
            ->and(pmdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S600')
            ->and(pmdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(pmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pmdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pmdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(pmdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pmdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(pmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pmdData("{$pgp}.learning_multiplier.value"))
            ->and(pmdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PYRENEAN_MOUNTAIN_DOG) {
                $decided++;
                expect($row['breed_slug'])->toBe(PMD_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.pyrenean_mountain_dog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pmdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13 — 2 per week, Claude's proposal), socialisation
        // (M5-R17), the digestion warning (M5-R16) and the weekly nail / ear tasks (M5-R19 / R20) are built.
        expect(json_encode([pmdData(PMD_KEY), pmdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(pmdData("{$pgp}.grooming.value"))->toBe(2)
            ->and(pmdData("{$pgp}.grooming.decision"))->toContain('M5-R13')->toStartWith('predlog Claude')->toContain('čaka Davida')
            ->and(pmdData("{$pgp}.grooming.alternatives.0.value"))->toBe(1)
            ->and(pmdData("{$pgp}.grooming.quote"))->toBe(BreedStageParamsSeeder::DOG_GROOMING[PMD_KEY][2])
            ->and(pmdData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[PMD_KEY][1])
            ->and(pmdData('pyrenean_mountain_dog.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[PMD_KEY][1])
            ->and(pmdData("{$pgp}.meal_exercise_gap.value"))->toBe(60)
            ->and(pmdData("{$pgp}.meal_exercise_gap.quote"))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[PMD_KEY][1])
            ->and(pmdData('pyrenean_mountain_dog.exercise.after_meals.quote'))->toBe(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[PMD_KEY][1])
            // VCA "weekly pedicure and ear cleaning" → nail trim and ear cleaning once a week; not flat-faced → no heat rule.
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[PMD_KEY]))->toBe(['nail_trim', 'ear_cleaning'])
            ->and(pmdData("{$pgp}.nail_trimming.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[PMD_KEY]['nail_trim'][3])
            ->and(pmdData("{$pgp}.ear_care.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[PMD_KEY]['ear_cleaning'][3])
            ->and(pmdData('pyrenean_mountain_dog.suitability.vca_nails_ears.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[PMD_KEY]['nail_trim'][3])
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(PMD_KEY);
    });

    it('takes the breed-level numbers from the sourced pyrenean_mountain_dog entries', function () {
        // PDSA (S603) "Average weight: 40-50 kg" (both sexes) — the FCI standard gives no weight.
        expect(pmdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([40, 50])
            ->and(pmdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pmdData('pyrenean_mountain_dog.adult_weight.pdsa.source_id'))
            ->and(pmdRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(pmdData('pyrenean_mountain_dog.adult_weight.pdsa.quote'))
            ->and(pmdData('pyrenean_mountain_dog.adult_weight.pdsa.value'))->toEqual(['male' => [40, 50], 'female' => [40, 50]])
            ->and(pmdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(pmdData('pyrenean_mountain_dog.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 64 (S35) → Fair → 0.7.
            ->and(pmdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(64)
            ->and(pmdRow('all', 0, StageParamKey::CorenRank)['quote'])->toBe(pmdData('pyrenean_mountain_dog.trainability.coren_rank.quote'))
            ->and(pmdData('pyrenean_mountain_dog.trainability.coren_rank.value'))->toBe(64)
            ->and(pmdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('pyrenean_mountain_dog.lifespan.petmd')
            ->and(pmdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S604')
            ->and(pmdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(pmdRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(pmdData('pyrenean_mountain_dog.lifespan.petmd.quote'));

        // Brushing twice a week: RKC "More than once a week" without a number; VCA / PetMD "weekly" (1) would contradict it
        // → Claude's proposal 2 (Belgian Malinois precedent), verified=false, open for David.
        $grooming = pmdRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S600')
            ->and($grooming['quote'])->toBe(pmdData('pyrenean_mountain_dog.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(pmdData('proposed_game_parameters.pyrenean_mountain_dog.grooming.quote'))
            ->and($grooming['verified'])->toBeFalse()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R13_PYRENEAN_MOUNTAIN_DOG)
            // The puppy's weekly "new experience" (M5-R17) with the VCA quote.
            ->and(pmdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(pmdRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S605');

        // The 60-minute digestion warning (M5-R16) with the PetMD quote — David's rule, verified.
        $gap = pmdRow('all', 0, StageParamKey::MealExerciseGapMinutes);
        expect($gap['value'])->toBe(60)
            ->and($gap['source_id'])->toBe('S604')
            ->and($gap['quote'])->toBe('Avoid vigorous exercise around mealtimes')
            ->and($gap['verified'])->toBeTrue()
            ->and($gap['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', PMD_SLUG);
        // VCA "weekly pedicure and ear cleaning" → nail trim and ear cleaning once a week (M5-R19 / R20); no tooth / fold /
        // bath / groomer frequency, not flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toEqualCanonicalizing([StageParamKey::NailTrimFrequency->value, StageParamKey::EarCleaningFrequency->value]);
        foreach ($care as $row) {
            expect($row['value'])->toBe([1, 7])
                ->and($row['source_id'])->toBe('S605')
                ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
                ->and($row['verified'])->toBeTrue();
        }
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog), the senior boundary / senior arrival
        // age (90 — Claude's suggestion, no runbook rule produced a value) and the brushing (2 per week).
        $open = $mine->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toEqualCanonicalizing(['senior.'.StageParamKey::StartsAtMonths->value, 'senior.'.StageParamKey::ArrivalAgeMonths->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value, 'all.'.StageParamKey::GroomingSessionsPerWeek->value]);
        foreach ($open->where('stage', 'senior') as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R10_PYRENEAN_MOUNTAIN_DOG_SENIOR)
                ->and($row['ref'])->toBe('proposed_game_parameters.pyrenean_mountain_dog.senior_boundary_months')
                ->and($row['value'])->toBe(pmdData('proposed_game_parameters.pyrenean_mountain_dog.senior_boundary_months.value'));
        }
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (VCA S605).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the digestion warning (PetMD S604).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R19 / R20: the breed's sourced weekly nail trim and ear cleaning (the Border Collie has none).
            StageParamKey::NailTrimFrequency, StageParamKey::EarCleaningFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(PMD_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pmdPet($arrival);
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
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(PMD_SLUG, 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(PMD_SLUG, 90))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren rank 64, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = pmdPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => PMD_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pmdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pmdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::PyreneanMountainDog)
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
        foreach (['border_collie', PMD_KEY] as $breed) {
            $pin = pmdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pmdLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[PMD_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(PMD_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pmdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Pyrenean Mountain Dog after the Miniature Pinscher with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, PMD_KEY, 'miniature_pinscher');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => PMD_KEY, 'slug' => PMD_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.pyrenean_mountain_dog',
            'search_keywords' => seededBreedConfig(PMD_KEY)['search_keywords'], 'sort_order' => 720,
            'suitability' => [
                'suits' => ['children', 'large_home', 'other_pets'],
                'consider' => ['not_for_small_children', 'sheds', 'frequent_grooming', 'shorter_lifespan'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S599 white, or white with badger / wolf-grey / pale yellow / orange patches)', function () {
    it('samples DNA in the five colours of the appearance block only, never black, brindle or merle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(PMD_KEY)
            ->and(PetDnaService::hasAppearance(PMD_KEY))->toBeTrue()
            ->and(config('breed_appearance.pyrenean_mountain_dog.verified'))->toBeFalse()
            ->and(config('breed_appearance.pyrenean_mountain_dog.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample(PMD_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('undercoat')
                ->and($traits['ear_carriage'])->toContain('triangular')
                ->and($traits['muzzle'])->toContain('black nose')
                ->and($traits['coat_color'])->toMatch('/^(pure white|white with)\b/');

            $prompt = $prompts->imagePrompt(PMD_KEY, $traits);
            expect($prompt)->toContain('Pyrenean Mountain Dog')
                ->not->toMatch('/\b(merle|brindle|docked|cropped)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'pure white',
            'white with badger (grey-brown) patches on the head and ears',
            'white with wolf-grey patches on the head and ears',
            'white with pale yellow patches on the head and ears',
            'white with orange patches on the head and ears',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.pyrenean_mountain_dog'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.pyrenean_mountain_dog'), 'key'))->toBe(['white', 'white-badger', 'white-grey', 'white-yellow', 'white-orange']);
    });

    it('draws the register portrait pure white', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::PyreneanMountainDog);

        expect($traits['coat_color'])->toBe('pure white')
            ->and($portrait->prompt(BreedType::PyreneanMountainDog))->toContain('Pyrenean Mountain Dog')->toContain('pure white')
            ->and(BreedPortraitService::relativeFile(BreedType::PyreneanMountainDog))->toBe('dog/pyrenean-mountain-dog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::PyreneanMountainDog);
        expect($v1['prompt_anchor'])->toContain('Pyrenean Mountain Dog')->toContain('pure white coat')->toContain('triangular ears')
            ->not->toMatch('/\b(cropped|merle|docked|brindle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['pure white', 'white with badger (grey-brown) patches on the head and ears', 'white with pale yellow patches on the head and ears'])
            ->and($v1['visual_traits']['eye_color'])->toBe('amber-brown');

        $pet = pmdPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Pyrenean Mountain Dog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; lifespan chip, no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the lifespan chip but no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.pyrenean_mountain_dog'),
            config('breed_suitability.breeds.pyrenean_mountain_dog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', PMD_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::PyreneanMountainDog);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC / PDSA "Under 10 years" → shorter_lifespan; the RKC Breed Watch pages disagree (Category 1 vs 2) → no welfare chip (open for David).
            ->and($tags['consider'])->toContain('shorter_lifespan')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin']))->toBe([])
            ->and(pmdData('proposed_game_parameters.pyrenean_mountain_dog.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (98 McMillan via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', PMD_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(98)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children, a large home and other pets; consider small children, shedding, grooming and the shorter lifespan', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::PyreneanMountainDog);
        expect($tags['suits'])->toBe(['children', 'large_home', 'other_pets'])
            ->and($tags['consider'])->toBe(['not_for_small_children', 'sheds', 'frequent_grooming', 'shorter_lifespan'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('apartment')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('hips_hind_legs');

        expect(pmdData('pyrenean_mountain_dog.behaviour.children.quote'))->toContain('without smaller children')
            ->and(pmdData('pyrenean_mountain_dog.behaviour.other_pets.quote'))->toContain('live well with other pets')
            ->and(pmdData('pyrenean_mountain_dog.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house');
    });
});
