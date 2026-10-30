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
| M5-R10-79 — Soft Coated Wheaten Terrier (appended after the Pyrenean Mountain Dog: AKC 2024 No. 75)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `soft_coated_wheaten_terrier` (S608–S614) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.soft_coated_wheaten_terrier` (exercise 60 min — RKC "Exercise:
| Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 123 (0.75 × McMillan 2024 median
| 13.7 y, Dogs Trust table S123), arrival 2 / 9 / 36 / 123, learning × 1.0 (Coren rank 40, Average), paid breed, Border Collie
| care rates, brushing 7× a week (RKC "Grooming: Every day", M5-R13), puppy socialisation with the PetMD quote (M5-R17), ear
| cleaning once a week (PetMD "every one or two weeks", M5-R20), groomer and bath reminders every 4 weeks (PetMD "every four
| to six weeks" / "about monthly", M5-R18 / R19)). No meal gap, no tooth / nail task. Suitability: suits family_pet / children /
| low_shedding; consider frequent_grooming / needs_company. PDSA weight 16–20.5 kg; one standard colour, clear wheaten;
| Breed Watch Category 1 → in marketing.
*/

const SCW_KEY = 'soft_coated_wheaten_terrier';
const SCW_SLUG = 'soft-coated-wheaten-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function scwData(string $path): mixed
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

/** @return array<string, mixed> the Soft Coated Wheaten Terrier seeder row of (stage, from, key) */
function scwRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === SCW_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(SCW_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function scwPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->softCoatedWheatenTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function scwPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function scwLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::SoftCoatedWheatenTerrier;
        $config = BreedConfig::where('breed_slug', SCW_SLUG)->sole();

        expect($breed->value)->toBe(SCW_KEY)
            ->and($breed->slug())->toBe(SCW_SLUG)
            ->and(BreedType::fromSlug(SCW_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 730, 'breeds.soft_coated_wheaten_terrier'])
            ->and($config->search_keywords)->toBe(['soft coated wheaten terrier', 'wheaten terrier', 'wheaten', 'scwt', 'irish soft coated wheaten terrier', 'irski mehkodlaki pšenični terier', 'irski mehkodlaki psenicni terier', 'pšenični terier', 'psenicni terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) scwData('proposed_game_parameters.soft_coated_wheaten_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = scwData('proposed_game_parameters.soft_coated_wheaten_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Soft Coated Wheaten Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = scwPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(SCW_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'wheaten_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'irish_soft_coated_wheaten_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_13_120000_add_soft_coated_wheaten_terrier_breed.php');
        // down() refuses while a Soft Coated Wheaten Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => SCW_KEY]))->toThrow(QueryException::class);
        // The Pyrenean Mountain Dog (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pyrenean_mountain_dog']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => SCW_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SoftCoatedWheatenTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', SCW_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', SCW_SLUG)->count());

        BreedConfig::where('breed_slug', SCW_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', SCW_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', SCW_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', SCW_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', SCW_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, SCW_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_SOFT_COATED_WHEATEN_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_SOFT_COATED_WHEATEN_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.soft_coated_wheaten_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(scwRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(scwData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(scwRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(scwData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(scwRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(scwData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.7 y (McMillan 2024 median via the Dogs Trust table, S123) = 123 — the runbook rule, verified.
            ->and(scwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(scwData('soft_coated_wheaten_terrier.lifespan.senior_from.months'))
            ->and(scwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * scwRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(scwData('soft_coated_wheaten_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(scwRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(scwRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(scwRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(123);

        expect(scwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(scwData("{$pgp}.exercise_minutes_adult.value"))
            ->and(scwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(scwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(scwData('soft_coated_wheaten_terrier.exercise.adult.quote'))
            ->and(scwRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S610')
            ->and(scwRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(scwRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(scwData("{$pgp}.exercise_minutes_senior.value"))
            ->and(scwRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(scwRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(scwData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(scwRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(scwData("{$pgp}.learning_multiplier.value"))
            ->and(scwRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_SOFT_COATED_WHEATEN_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(SCW_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.soft_coated_wheaten_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) scwData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13), socialisation (M5-R17) and the care tasks (M5-R18 / R19 / R20) are built.
        expect(json_encode([scwData(SCW_KEY), scwData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(scwData("{$pgp}.grooming.value"))->toBe(7)
            ->and(scwData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(scwData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[SCW_KEY][1])
            ->and(scwData('soft_coated_wheaten_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[SCW_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(SCW_KEY)
            // PetMD (S613): ears every one or two weeks, groomer every four to six weeks, bath about monthly; no tooth / nail frequency.
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[SCW_KEY]))->toBe(['ear_cleaning', 'groomer_visit', 'bath'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[SCW_KEY]['ear_cleaning'][3])->toBe(scwData("{$pgp}.ear_care.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[SCW_KEY]['groomer_visit'][3])->toBe(scwData("{$pgp}.professional_grooming.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[SCW_KEY]['bath'][3])->toBe(scwData("{$pgp}.bathing.quote"))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(SCW_KEY);
    });

    it('takes the breed-level numbers from the sourced soft_coated_wheaten_terrier entries', function () {
        // PDSA key facts (S612): "Average weight: 16-20.5 kg" (both sexes).
        expect(scwRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([16, 20.5])
            ->and(scwRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(scwData('soft_coated_wheaten_terrier.adult_weight.pdsa.source_id'))
            ->and(scwRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(scwData('soft_coated_wheaten_terrier.adult_weight.pdsa.quote'))
            ->and(scwData('soft_coated_wheaten_terrier.adult_weight.pdsa.value'))->toEqual(['male' => [16, 20.5], 'female' => [16, 20.5]])
            ->and(scwRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(scwData('soft_coated_wheaten_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 40 (S35) → Average → 1.0.
            ->and(scwRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(40)
            ->and(scwData('soft_coated_wheaten_terrier.trainability.coren_rank.value'))->toBe(40)
            ->and(scwRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('soft_coated_wheaten_terrier.lifespan.median_uk')
            ->and(scwRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(scwRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.7)
            ->and(scwRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(scwData('soft_coated_wheaten_terrier.lifespan.median_uk.quote'));

        // Brushing 7× a week (RKC "Grooming: Every day" → 7) — the rule, verified.
        $grooming = scwRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S610')
            ->and($grooming['quote'])->toBe(scwData('soft_coated_wheaten_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(scwData('proposed_game_parameters.soft_coated_wheaten_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(scwRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(scwRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S613');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', SCW_SLUG);
        // No meal / walk gap (no source).
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);
        // PetMD (S613): ear cleaning once a week (M5-R20), groomer and bath reminders every 4 weeks (M5-R18 / R19); no tooth /
        // fold / nail frequency, not flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('value', 'key')->all())->toEqual([
            StageParamKey::EarCleaningFrequency->value => [1, 7],
            StageParamKey::GroomerVisitFrequency->value => [1, 28],
            StageParamKey::BathFrequency->value => [1, 28],
        ]);
        foreach ($care as $row) {
            expect($row['source_id'])->toBe('S613')
                ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
                ->and($row['verified'])->toBeTrue();
        }
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposal: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (PetMD S613).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R18 / R19 / R20: the breed's sourced ear cleaning, groomer and bath reminders (the Border Collie has none).
            StageParamKey::EarCleaningFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(SCW_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = scwPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (capped)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 122 months' => [122, LifeStage::Adult, 60, 6000],
        'senior 123 months' => [123, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 123 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(SCW_SLUG, 122))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(SCW_SLUG, 123))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren rank 40, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = scwPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => SCW_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = scwPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(scwLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SoftCoatedWheatenTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 123 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', SCW_KEY] as $breed) {
            $pin = scwPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(scwLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[SCW_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(123)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(SCW_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = scwPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Soft Coated Wheaten Terrier after the Pyrenean Mountain Dog with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, SCW_KEY, 'pyrenean_mountain_dog');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => SCW_KEY, 'slug' => SCW_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.soft_coated_wheaten_terrier',
            'search_keywords' => seededBreedConfig(SCW_KEY)['search_keywords'], 'sort_order' => 730,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — RKC S611 "A good clear wheaten.")', function () {
    it('samples DNA in the one standard colour only, never white, red, black or black and tan', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(SCW_KEY)
            ->and(PetDnaService::hasAppearance(SCW_KEY))->toBeTrue()
            ->and(config('breed_appearance.soft_coated_wheaten_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.soft_coated_wheaten_terrier.prompt_order'))->toContain('head')->toContain('ear_carriage')->toContain('tail');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample(SCW_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('soft, silky single coat')
                ->and($traits['ear_carriage'])->toContain('V-shaped')
                ->and($traits['tail'])->toContain('undocked');

            $prompt = $prompts->imagePrompt(SCW_KEY, $traits);
            expect($prompt)->toContain('Soft Coated Wheaten Terrier')
                ->not->toMatch('/\b(merle|docked tail|cropped|black and tan|brindle)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The one colour of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        expect(array_keys($colours))->toBe(['clear wheaten'])
            ->and(array_column(config('breed_colours.soft_coated_wheaten_terrier'), 'value'))->toBe(['clear wheaten'])
            ->and(array_column(config('breed_colours.soft_coated_wheaten_terrier'), 'key'))->toBe(['wheaten']);
    });

    it('draws the register portrait clear wheaten', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::SoftCoatedWheatenTerrier);

        expect($traits['coat_color'])->toBe('clear wheaten')
            ->and($portrait->prompt(BreedType::SoftCoatedWheatenTerrier))->toContain('Soft Coated Wheaten Terrier')->toContain('clear wheaten')
            ->and(BreedPortraitService::relativeFile(BreedType::SoftCoatedWheatenTerrier))->toBe('dog/soft-coated-wheaten-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::SoftCoatedWheatenTerrier);
        expect($v1['prompt_anchor'])->toContain('Soft Coated Wheaten Terrier')->toContain('clear wheaten single coat')
            ->not->toMatch('/\b(cropped|merle|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['clear wheaten', 'light clear wheaten', 'golden clear wheaten'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark hazel');

        $pet = scwPet(123, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Soft Coated Wheaten Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.soft_coated_wheaten_terrier'),
            config('breed_suitability.breeds.soft_coated_wheaten_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', SCW_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::SoftCoatedWheatenTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1; median 13.7 y → no welfare chip, no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(scwData('proposed_game_parameters.soft_coated_wheaten_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (108 RKC / PDSA lower bound, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', SCW_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families, children and low shedding; consider daily grooming and company — no other-pets or prey-drive chip (sources disagree)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::SoftCoatedWheatenTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'low_shedding'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('often_alone')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('sheds');

        expect(scwData('soft_coated_wheaten_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(scwData('soft_coated_wheaten_terrier.suitability.rkc_grooming.quote'))->toBe('Grooming: Every day')
            ->and(scwData('soft_coated_wheaten_terrier.behaviour.time_alone.quote'))->toContain('dislike being left alone for long periods')
            ->and(scwData('soft_coated_wheaten_terrier.behaviour.other_pets.quote'))->not->toContain('prey drive')
            ->and(scwData('soft_coated_wheaten_terrier.behaviour.children.notes'))->toContain('supervised');
    });
});
