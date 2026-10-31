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
| M5-R10-82 — Lagotto Romagnolo (appended after the American Staffordshire Terrier: AKC 2024 No. 78)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `lagotto_romagnolo` (S630–S636) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.lagotto_romagnolo` (exercise 120 min — RKC "More than 2 hours per day" —,
| senior 90 min, puppy 10 min × age capped at 120, stages 9 / 36 / 108 (0.75 × RKC lower bound 12 y; no McMillan 2024 row),
| arrival 2 / 9 / 36 / 108, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, puppy socialisation
| with a PetMD quote (M5-R17), groomer reminder every 6 weeks (Woodgreen, M5-R18) and bath reminder every 4 weeks (PetMD,
| M5-R19)). Open for David: brushing 2× a week (RKC "More than once a week" without a number — M5-R13 fallback, verified=false).
| No meal gap, no ear / tooth / nail task. Suitability: suits family_pet / low_shedding; consider long_daily_exercise /
| needs_mental_stimulation / needs_company / frequent_grooming. FCI weight 11–16 kg; seven standard colours, never black;
| RKC Breed Watch Category 1 → no welfare chip, in marketing.
*/

const LR_KEY = 'lagotto_romagnolo';
const LR_SLUG = 'lagotto-romagnolo';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function lrData(string $path): mixed
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

/** @return array<string, mixed> the Lagotto Romagnolo seeder row of (stage, from, key) */
function lrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === LR_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(LR_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function lrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->lagottoRomagnolo()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function lrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function lrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::LagottoRomagnolo;
        $config = BreedConfig::where('breed_slug', LR_SLUG)->sole();

        expect($breed->value)->toBe(LR_KEY)
            ->and($breed->slug())->toBe(LR_SLUG)
            ->and(BreedType::fromSlug(LR_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 760, 'breeds.lagotto_romagnolo'])
            ->and($config->search_keywords)->toBe(['lagotto romagnolo', 'lagotto', 'lagotti', 'romagna water dog', 'truffle dog', 'pes za tartufe', 'tartufar'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) lrData('proposed_game_parameters.lagotto_romagnolo.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = lrData('proposed_game_parameters.lagotto_romagnolo.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Lagotto Romagnolo pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = lrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(LR_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'lagotto']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'romagna_water_dog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_22_120000_add_lagotto_romagnolo_breed.php');
        // down() refuses while a Lagotto Romagnolo exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => LR_KEY]))->toThrow(QueryException::class);
        // The American Staffordshire Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'american_staffordshire_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => LR_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::LagottoRomagnolo);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', LR_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', LR_SLUG)->count());

        BreedConfig::where('breed_slug', LR_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', LR_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', LR_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', LR_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', LR_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, LR_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_LAGOTTO_ROMAGNOLO)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_LAGOTTO_ROMAGNOLO');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.lagotto_romagnolo';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(lrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(lrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(lrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(lrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(lrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(lrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S632; no McMillan 2024 row) = 108 — the runbook rule, verified.
            ->and(lrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(lrData('lagotto_romagnolo.lifespan.senior_from.months'))
            ->and(lrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * lrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12, 0, PHP_ROUND_HALF_UP))
            ->and(lrData('lagotto_romagnolo.lifespan.senior_from.derived_from'))->toBe(['S11', 'S632'])
            ->and(lrData('lagotto_romagnolo.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(lrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S632')
            ->and(lrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(lrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(lrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(lrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(lrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(lrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(lrData('lagotto_romagnolo.exercise.adult.quote'))
            ->and(lrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S632')
            ->and(lrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(lrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(lrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(lrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(lrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(lrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(lrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(lrData("{$pgp}.learning_multiplier.value"))
            ->and(lrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_LAGOTTO_ROMAGNOLO) {
                $decided++;
                expect($row['breed_slug'])->toBe(LR_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.lagotto_romagnolo.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) lrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13, an open proposal for David), socialisation (M5-R17) and the
        // care tasks (M5-R18 / R19) are built.
        expect(json_encode([lrData(LR_KEY), lrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(lrData("{$pgp}.grooming.value"))->toBe(2)
            ->and(lrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->toContain('čaka Davida')
            ->and(lrData("{$pgp}.grooming.decision"))->toBe(BreedStageParamsSeeder::PROPOSED_R13_LAGOTTO_ROMAGNOLO)
            ->and(lrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[LR_KEY][1])
            ->and(lrData('lagotto_romagnolo.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[LR_KEY][1])
            // No source quotes a gap after eating.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(LR_KEY)
            // Woodgreen (S636) groomer every six to eight weeks; PetMD (S635) bath up to once a month; no ear / tooth / nail number.
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[LR_KEY]))->toBe(['groomer_visit', 'bath'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[LR_KEY]['groomer_visit'][3])->toBe(lrData("{$pgp}.professional_grooming.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[LR_KEY]['bath'][3])->toBe(lrData("{$pgp}.bathing.quote"))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(LR_KEY);
    });

    it('takes the breed-level numbers from the sourced lagotto_romagnolo entries', function () {
        // FCI-Standard N° 298 (S631): males 13–16 kg, females 11–14 kg → the game span 11–16 kg.
        expect(lrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([11.0, 16.0])
            ->and(lrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(lrData('lagotto_romagnolo.adult_weight.fci.source_id'))
            ->and(lrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(lrData('lagotto_romagnolo.adult_weight.fci.quote'))
            ->and(lrData('lagotto_romagnolo.adult_weight.fci.value'))->toEqual(['male' => [13, 16], 'female' => [11, 14]])
            ->and(lrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(lrData('lagotto_romagnolo.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Not ranked by Coren (S35) → 1.0.
            ->and(lrRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(lrData('lagotto_romagnolo.trainability.coren_rank.value'))->toBeNull()
            ->and(lrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('lagotto_romagnolo.lifespan.rkc')
            ->and(lrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S632')
            ->and(lrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(lrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(lrData('lagotto_romagnolo.lifespan.rkc.quote'));

        // Brushing 2× a week — Claude's proposal (RKC "More than once a week", no number anywhere), open for David.
        $grooming = lrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S632')
            ->and($grooming['quote'])->toBe(lrData('lagotto_romagnolo.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(lrData('proposed_game_parameters.lagotto_romagnolo.grooming.quote'))
            ->and($grooming['verified'])->toBeFalse()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::PROPOSED_R13_LAGOTTO_ROMAGNOLO)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(lrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(lrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S635');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', LR_SLUG);
        // No meal gap (no source).
        expect($mine->firstWhere('key', StageParamKey::MealExerciseGapMinutes->value))->toBeNull();
        // Groomer reminder every 6 weeks (Woodgreen S636, M5-R18) and bath reminder every 4 weeks (PetMD S635, M5-R19); no
        // tooth / fold / ear / nail frequency, not flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('value', 'key')->all())->toEqual([
            StageParamKey::GroomerVisitFrequency->value => [1, 42],
            StageParamKey::BathFrequency->value => [1, 28],
        ])
            ->and($care->pluck('source_id', 'key')->all())->toBe([
                StageParamKey::GroomerVisitFrequency->value => 'S636',
                StageParamKey::BathFrequency->value => 'S635',
            ]);
        foreach ($care as $row) {
            expect($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R14_R20_CARE)
                ->and($row['verified'])->toBeTrue();
        }
        // Like the other pedigree breeds: no individual variation row.
        expect($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the brushing (2× a week) and the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->sort()->values()->all())
            ->toBe(collect(['all.'.StageParamKey::GroomingSessionsPerWeek->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value])->sort()->values()->all());
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (PetMD S635).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R18 / R19: the breed's sourced groomer and bath reminders (the Border Collie has none).
            StageParamKey::GroomerVisitFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(LR_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = lrPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (10 min × age)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 107 months' => [107, LifeStage::Adult, 120, 12000],
        'senior 108 months' => [108, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 108 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(LR_SLUG, 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(LR_SLUG, 108))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (not ranked by Coren, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = lrPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => LR_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = lrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(lrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::LagottoRomagnolo)
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
        foreach (['border_collie', LR_KEY] as $breed) {
            $pin = lrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(lrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[LR_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(LR_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = lrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Lagotto Romagnolo after the American Staffordshire Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, LR_KEY, 'american_staffordshire_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => LR_KEY, 'slug' => LR_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.lagotto_romagnolo',
            'search_keywords' => seededBreedConfig(LR_KEY)['search_keywords'], 'sort_order' => 760,
            'suitability' => [
                'suits' => ['family_pet', 'low_shedding'],
                'consider' => ['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S631 / RKC S633, never black)', function () {
    it('samples DNA in the seven standard colours only, never black, with a curly clipped coat and a brown nose', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(LR_KEY)
            ->and(PetDnaService::hasAppearance(LR_KEY))->toBeTrue()
            ->and(config('breed_appearance.lagotto_romagnolo.verified'))->toBeFalse()
            ->and(config('breed_appearance.lagotto_romagnolo.prompt_order'))->toContain('nose')->toContain('ear_carriage')->toContain('tail');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(LR_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('ring-shaped curls')
                ->and($traits['nose'])->toContain('brown nose')
                ->and($traits['coat_color'])->not->toContain('black');

            $prompt = $prompts->imagePrompt(LR_KEY, $traits);
            expect($prompt)->toContain('Lagotto Romagnolo')
                ->not->toMatch('/\b(merle|docked tail|black coat|grey coat)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $standard = ['brown roan (white finely flecked with brown)', 'solid off-white', 'white with brown patches', 'white with orange patches', 'orange roan (white finely flecked with orange)', 'solid brown', 'solid orange'];
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.lagotto_romagnolo'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.lagotto_romagnolo'), 'key'))->toBe(['brown-roan', 'off-white', 'white-brown', 'white-orange', 'orange-roan', 'brown', 'orange'])
            ->and(lrData('lagotto_romagnolo.appearance.colours.value'))->toBe(['brown roan', 'off-white', 'white with brown patches', 'white with orange patches', 'orange roan', 'brown', 'orange']);
    });

    it('draws the register portrait brown roan', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::LagottoRomagnolo);

        expect($traits['coat_color'])->toBe('brown roan (white finely flecked with brown)')
            ->and($portrait->prompt(BreedType::LagottoRomagnolo))->toContain('Lagotto Romagnolo')->toContain('brown roan')
            ->and(BreedPortraitService::relativeFile(BreedType::LagottoRomagnolo))->toBe('dog/lagotto-romagnolo.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::LagottoRomagnolo);
        expect($v1['prompt_anchor'])->toContain('Lagotto Romagnolo')->toContain('ring-shaped curls')->toContain('brown nose')
            ->not->toMatch('/\b(merle|docked tail|black coat)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['brown roan (white finely flecked with brown)', 'solid off-white', 'white with brown patches'])
            ->and($v1['visual_traits']['eye_color'])->toBe('ochre to hazel');

        $pet = lrPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Lagotto Romagnolo')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.lagotto_romagnolo'),
            config('breed_suitability.breeds.lagotto_romagnolo'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', LR_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::LagottoRomagnolo);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1; RKC "Over 12 years" → no welfare chip, no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(lrData('proposed_game_parameters.lagotto_romagnolo.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            ->and(lrData('lagotto_romagnolo.health.breed_watch.quote'))->toStartWith('Currently no points of concern')
            // The alternatives (84 Dogs Trust, 135 from the PetMD / Woodgreen 15 y) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', LR_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(135);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family and sheds little; consider long exercise, a busy mind, company and frequent brushing — no children or prey-drive chip', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::LagottoRomagnolo);
        expect($tags['suits'])->toBe(['family_pet', 'low_shedding'])
            ->and($tags['consider'])->toBe(['long_daily_exercise', 'needs_mental_stimulation', 'needs_company', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('children')->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')->not->toContain('other_pets')->not->toContain('often_alone')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('chases_small_pets');

        expect(lrData('lagotto_romagnolo.suitability.rkc_shedding.quote'))->toBe('Sheds: No')
            ->and(lrData('lagotto_romagnolo.behaviour.time_alone.quote'))->toContain('separation-related issues')
            ->and(lrData('lagotto_romagnolo.behaviour.family.quote'))->toBe('An excellent and alert family companion.')
            ->and(lrData('lagotto_romagnolo.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Small house');
    });
});
