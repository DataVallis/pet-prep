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
| M5-R10-71 — Bullmastiff (appended after the Russell Terrier: AKC 2024 No. 67)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bullmastiff` (S565–S569) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bullmastiff` (exercise 120 min — RKC "More than 2 hours
| per day" —, senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 92 (0.75 × McMillan 2024 median 10.2 y,
| Dogs Trust table S123), arrival 2 / 9 / 36 / 92, learning × 0.7 (Coren rank 69, Fair), paid breed, Border Collie
| care rates, brushing 1× a week (RKC "Once a week", M5-R13), meal / walk gap 60 min (PetMD, M5-R16), puppy
| socialisation with the PDSA quote (M5-R17), face cleaning every day (PDSA "might need daily attention", M5-R14)).
| Suitability: suits active_family / large_home; consider long_daily_exercise, short_muzzle_breathing,
| not_for_small_children, experienced_owner, needs_company, shorter_lifespan, drools, chases_small_pets. Weight from the
| FCI standard. Fawn portrait with a black mask and a moderate muzzle; PDSA BOAS "caused by their shorter faces" →
| short_muzzle_breathing and not in marketing (BVA S92, open for David).
*/

const BMF_KEY = 'bullmastiff';
const BMF_SLUG = 'bullmastiff';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bmfData(string $path): mixed
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

/** @return array<string, mixed> the Bullmastiff seeder row of (stage, from, key) */
function bmfRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === BMF_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(BMF_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function bmfPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bullmastiff()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bmfPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bmfLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Bullmastiff;
        $config = BreedConfig::where('breed_slug', BMF_SLUG)->sole();

        expect($breed->value)->toBe(BMF_KEY)
            ->and($breed->slug())->toBe(BMF_SLUG)
            ->and(BreedType::fromSlug(BMF_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 660, 'breeds.bullmastiff'])
            ->and($config->search_keywords)->toBe(['bullmastiff', 'bull mastiff', 'bulmastif', 'bul mastif', 'bulmastiff', "gamekeeper's night dog"])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bmfData('proposed_game_parameters.bullmastiff.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bmfData('proposed_game_parameters.bullmastiff.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bullmastiff pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bmfPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(BMF_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'bull_mastiff']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bulmastif']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_02_120000_add_bullmastiff_breed.php');
        // down() refuses while a Bullmastiff exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => BMF_KEY]))->toThrow(QueryException::class);
        // The Russell Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'russell_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => BMF_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Bullmastiff);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', BMF_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', BMF_SLUG)->count());

        BreedConfig::where('breed_slug', BMF_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', BMF_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', BMF_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', BMF_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', BMF_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, BMF_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BULLMASTIFF)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BULLMASTIFF');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bullmastiff';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bmfRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmfData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bmfRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmfData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bmfRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bmfData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10.2 y (McMillan 2024 median via the Dogs Trust table, S123) = 91.8 → 92 — the runbook rule, verified.
            ->and(bmfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bmfData('bullmastiff.lifespan.senior_from.months'))
            ->and(bmfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bmfRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bmfData('bullmastiff.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(bmfRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(bmfRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(bmfRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(92);

        expect(bmfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmfData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bmfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(bmfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bmfData('bullmastiff.exercise.adult.quote'))
            ->and(bmfRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S567')
            ->and(bmfRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(bmfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bmfData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bmfRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bmfRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bmfData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(bmfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bmfData("{$pgp}.learning_multiplier.value"))
            ->and(bmfRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BULLMASTIFF) {
                $decided++;
                expect($row['breed_slug'])->toBe(BMF_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bullmastiff.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bmfData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13,
        // meal gap M5-R16, socialisation M5-R17, face cleaning M5-R14).
        expect(json_encode([bmfData(BMF_KEY), bmfData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(bmfData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bmfData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(bmfData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[BMF_KEY][1])
            ->and(bmfData('bullmastiff.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[BMF_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[BMF_KEY])->toBe(['S569', bmfData("{$pgp}.meal_exercise_gap.quote")])
            ->and(bmfData('bullmastiff.exercise.after_meals.quote'))->toBe(bmfData("{$pgp}.meal_exercise_gap.quote"))
            ->and(bmfData("{$pgp}.face_fold_cleaning.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[BMF_KEY]['fold_cleaning'][3])
            ->and(bmfData('bullmastiff.suitability.pdsa_face_care.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[BMF_KEY]['fold_cleaning'][3])
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[BMF_KEY]))->toBe(['fold_cleaning'])
            // Not flat-faced and no heat advice in any source → no no-walk-in-heat row.
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(BMF_KEY);
    });

    it('takes the breed-level numbers from the sourced bullmastiff entries', function () {
        // FCI-Standard N° 157 (S566): males 50–59 kg, females 41–50 kg → 41–59 kg.
        expect(bmfRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([41, 59])
            ->and(bmfRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bmfData('bullmastiff.adult_weight.fci.source_id'))
            ->and(bmfRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bmfData('bullmastiff.adult_weight.fci.quote'))
            ->and(bmfData('bullmastiff.adult_weight.fci.value'))->toEqual(['male' => [50, 59], 'female' => [41, 50]])
            ->and(bmfRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(bmfData('bullmastiff.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            // Coren rank 69 (S35), Fair tier → learning 0.7.
            ->and(bmfRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(69)
            ->and(bmfData('bullmastiff.trainability.coren_rank.value'))->toBe(69)
            ->and(bmfRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bullmastiff.lifespan.median_uk')
            ->and(bmfRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(bmfRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.2)
            ->and(bmfRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bmfData('bullmastiff.lifespan.median_uk.quote'));

        // Brushing 1× a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = bmfRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S567')
            ->and($grooming['quote'])->toBe(bmfData('bullmastiff.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(bmfData('proposed_game_parameters.bullmastiff.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PDSA quote.
            ->and(bmfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(bmfRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S568');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', BMF_SLUG);
        // M5-R16: the 60-minute digestion warning (PetMD S569 "Avoid exercise from one hour before to one hour after a meal.").
        $gap = $mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->values();
        expect($gap->count())->toBe(1)
            ->and($gap->first()['value'])->toBe(60)
            ->and($gap->first()['source_id'])->toBe('S569')
            ->and($gap->first()['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);
        // M5-R14 face cleaning every day (PDSA S568); no tooth / ear / nail / bath / groomer frequency, no heat rule
        // → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::FoldCleaningFrequency->value])
            ->and($care->first()['value'])->toBe([7, 7])
            ->and($care->first()['source_id'])->toBe('S568')
            ->and($care->first()['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
            ->and($care->first()['verified'])->toBeTrue();
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
            // M5-R16: the meal / walk gap (PetMD S569; the Border Collie has none).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R17: the puppy's weekly "new experience" (PDSA S568).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R14 / R15 / R18 / R19 / R20: the breed's sourced care tasks / heat rule (the Border Collie has none).
            StageParamKey::ToothBrushingFrequency, StageParamKey::FoldCleaningFrequency, StageParamKey::EarCleaningFrequency,
            StageParamKey::NailTrimFrequency, StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency, StageParamKey::NoWalkInHeat,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(BMF_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bmfPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (10 min × age)' => [8, LifeStage::Puppy, 80, 8000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 91 months' => [91, LifeStage::Adult, 120, 12000],
        'senior 92 months' => [92, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 92 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(BMF_SLUG, 91))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(BMF_SLUG, 92))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bmfPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => BMF_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bmfPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bmfLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Bullmastiff)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 92 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', BMF_KEY] as $breed) {
            $pin = bmfPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bmfLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[BMF_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(92)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(BMF_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bmfPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bullmastiff after the Russell Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, BMF_KEY, 'russell_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => BMF_KEY, 'slug' => BMF_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bullmastiff',
            'search_keywords' => seededBreedConfig(BMF_KEY)['search_keywords'], 'sort_order' => 660,
            'suitability' => [
                'suits' => ['active_family', 'large_home'],
                'consider' => ['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'shorter_lifespan', 'drools', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S566, fawn, red or brindle with a black mask)', function () {
    it('samples DNA in the three colours of the appearance block only, with a moderate muzzle, folded ears and a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(BMF_KEY)
            ->and(PetDnaService::hasAppearance(BMF_KEY))->toBeTrue()
            ->and(config('breed_appearance.bullmastiff.verified'))->toBeFalse()
            ->and(config('breed_appearance.bullmastiff.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(BMF_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('folded back')
                ->and($traits['tail'])->toContain('natural tail')
                ->and($traits['muzzle'])->toContain('clearly open nostrils')->toContain('no heavy wrinkles')
                ->and($traits['coat_color'])->toEndWith('with a black mask');

            $prompt = $prompts->imagePrompt(BMF_KEY, $traits);
            expect($prompt)->toContain('Bullmastiff')
                ->not->toMatch('/\b(docked tail|cropped|merle|blue|liver|white patches|flat face)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['fawn with a black mask', 'red with a black mask', 'brindle with a black mask'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.bullmastiff'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.bullmastiff'), 'key'))->toBe(['fawn', 'red', 'brindle']);
    });

    it('draws the register portrait fawn with a black mask', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Bullmastiff);

        expect($traits['coat_color'])->toBe('fawn with a black mask')
            ->and($portrait->prompt(BreedType::Bullmastiff))->toContain('Bullmastiff')->toContain('fawn with a black mask')->toContain('open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::Bullmastiff))->toBe('dog/bullmastiff.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Bullmastiff);
        expect($v1['prompt_anchor'])->toContain('Bullmastiff')->toContain('black muzzle mask')->toContain('open nostrils')
            ->not->toMatch('/\b(cropped|merle|docked)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['fawn with a black mask', 'red with a black mask', 'brindle with a black mask'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark or hazel');

        $pet = bmfPet(92, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bullmastiff')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the shorter-muzzle chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.bullmastiff'),
            config('breed_suitability.breeds.bullmastiff'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', BMF_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Bullmastiff);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // PDSA BOAS "caused by their shorter faces" → the shorter-muzzle chip, never the flat-face chip; no Breed Watch
            // point returned → no hip / eyelid chip (open for David).
            ->and($tags['consider'])->toContain('short_muzzle_breathing')->toContain('shorter_lifespan')
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin']))->toBe([])
            ->and(bmfData('proposed_game_parameters.bullmastiff.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and(bmfData('proposed_game_parameters.bullmastiff.marketing.source_id'))->toBe('S92')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (84 Dogs Trust, 63 PetMD) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', BMF_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(63);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits an active family with a large home; consider exercise, the shorter muzzle, small children, experience, company, lifespan, drool and the prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Bullmastiff);
        expect($tags['suits'])->toBe(['active_family', 'large_home'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'short_muzzle_breathing', 'not_for_small_children', 'experienced_owner', 'needs_company', 'shorter_lifespan', 'drools', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('children')->not->toContain('other_pets')->not->toContain('family_pet')
            // RKC "Sheds: Yes" vs PDSA "they don't shed much" → neither shedding chip.
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('frequent_grooming')
            ->and($tags['suits'])->not->toContain('low_shedding');

        expect(bmfData('bullmastiff.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(bmfData('bullmastiff.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(bmfData('bullmastiff.behaviour.other_pets.quote'))->toContain('very strong prey drives')
            ->and(bmfData('bullmastiff.health.brachycephaly_boas.notes'))->toContain('caused by their shorter faces');
    });
});
