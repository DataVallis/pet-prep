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
| M5-R10-81 — American Staffordshire Terrier (appended after the Irish Wolfhound: AKC 2024 No. 77)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `american_staffordshire_terrier` (S625–S629) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.american_staffordshire_terrier` (exercise 60 min — PetMD "at least
| 60 minutes of exercise daily"; no RKC / PDSA page —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 113
| (0.75 × McMillan 2024 median 12.5 y, Dogs Trust table S123), arrival 2 / 9 / 36 / 113, learning × 1.2 (Coren rank 34, Above
| average), paid breed, Border Collie care rates, brushing 1× a week (VCA "Weekly brushing", M5-R13), puppy socialisation and
| the 60-min meal gap with PetMD quotes (M5-R17 / R16), ear cleaning and bath reminder every 4 weeks (PetMD "around every 4-6
| weeks", M5-R20 / R19)). No groomer, tooth or nail task. Suitability: suits children; consider needs_company /
| chases_small_pets. PetMD weight 40–70 lb (18.1–31.8 kg); colours brindle / blue / black; not RKC-recognised → no Breed
| Watch, in marketing.
*/

const AST_KEY = 'american_staffordshire_terrier';
const AST_SLUG = 'american-staffordshire-terrier';

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

/** @return array<string, mixed> the American Staffordshire Terrier seeder row of (stage, from, key) */
function astRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === AST_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(AST_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function astPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->americanStaffordshireTerrier()->create(array_merge([
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
        $breed = BreedType::AmericanStaffordshireTerrier;
        $config = BreedConfig::where('breed_slug', AST_SLUG)->sole();

        expect($breed->value)->toBe(AST_KEY)
            ->and($breed->slug())->toBe(AST_SLUG)
            ->and(BreedType::fromSlug(AST_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 750, 'breeds.american_staffordshire_terrier'])
            ->and($config->search_keywords)->toBe(['american staffordshire terrier', 'amstaff', 'am staff', 'staffordshire terrier', 'american stafford', 'ameriški staffordshirski terier', 'ameriski staffordshirski terier', 'staffordshirski terier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) astData('proposed_game_parameters.american_staffordshire_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = astData('proposed_game_parameters.american_staffordshire_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a American Staffordshire Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = astPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(AST_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'amstaff']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'staffordshire_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_15_120000_add_american_staffordshire_terrier_breed.php');
        // down() refuses while a American Staffordshire Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => AST_KEY]))->toThrow(QueryException::class);
        // The Irish Wolfhound (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'irish_wolfhound']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => AST_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AmericanStaffordshireTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', AST_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', AST_SLUG)->count());

        BreedConfig::where('breed_slug', AST_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', AST_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', AST_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', AST_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', AST_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, AST_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_STAFFORDSHIRE_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_AMERICAN_STAFFORDSHIRE_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.american_staffordshire_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(astRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(astData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(astRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(astData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(astRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(astData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12.5 y (McMillan 2024 median via the Dogs Trust table, S123) = 112.5 → 113 (half up) — the runbook rule, verified.
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(astData('american_staffordshire_terrier.lifespan.senior_from.months'))
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * astRow('all', 0, StageParamKey::LifespanYears)['value'] * 12, 0, PHP_ROUND_HALF_UP))
            ->and(astData('american_staffordshire_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(astRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(113);

        expect(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(astData("{$pgp}.exercise_minutes_adult.value"))
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(astData('american_staffordshire_terrier.exercise.adult.quote'))
            ->and(astRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S628')
            ->and(astRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(astRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(astData("{$pgp}.exercise_minutes_senior.value"))
            ->and(astRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(astRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(astData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(astRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(astData("{$pgp}.learning_multiplier.value"))
            ->and(astRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_STAFFORDSHIRE_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(AST_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.american_staffordshire_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) astData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13), socialisation (M5-R17), the meal gap (M5-R16) and the care tasks (M5-R19 / R20) are built.
        expect(json_encode([astData(AST_KEY), astData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(astData("{$pgp}.grooming.value"))->toBe(1)
            ->and(astData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(astData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[AST_KEY][1])
            ->and(astData('american_staffordshire_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[AST_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP[AST_KEY])->toBe(['S628', astData("{$pgp}.meal_exercise_gap.quote")])
            ->and(astData('american_staffordshire_terrier.exercise.after_meals.quote'))->toBe(astData("{$pgp}.meal_exercise_gap.quote"))
            // PetMD (S628): ear cleaning at bath times, around every 4–6 weeks; no groomer / tooth / nail frequency.
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[AST_KEY]))->toBe(['ear_cleaning', 'bath'])
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[AST_KEY]['ear_cleaning'][3])->toBe(astData("{$pgp}.ear_care.quote"))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS[AST_KEY]['bath'][3])->toBe(astData("{$pgp}.bathing.quote"))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(AST_KEY);
    });

    it('takes the breed-level numbers from the sourced american_staffordshire_terrier entries', function () {
        // PetMD (S628): 40–70 lb = 18.1–31.8 kg (both sexes; the standards give no weight).
        expect(astRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([18.1, 31.8])
            ->and(astRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(astData('american_staffordshire_terrier.adult_weight.petmd.source_id'))
            ->and(astRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(astData('american_staffordshire_terrier.adult_weight.petmd.quote'))
            ->and(astData('american_staffordshire_terrier.adult_weight.petmd.value'))->toEqual(['male' => [18.1, 31.8], 'female' => [18.1, 31.8]])
            ->and(astRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(astData('american_staffordshire_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 34 (S35) → Above average → 1.2.
            ->and(astRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(34)
            ->and(astData('american_staffordshire_terrier.trainability.coren_rank.value'))->toBe(34)
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('american_staffordshire_terrier.lifespan.median_uk')
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.5)
            ->and(astRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(astData('american_staffordshire_terrier.lifespan.median_uk.quote'));

        // Brushing 1× a week (VCA "Weekly brushing" → 1) — the rule, verified.
        $grooming = astRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S629')
            ->and($grooming['quote'])->toBe(astData('american_staffordshire_terrier.suitability.vca_grooming.quote'))
            ->and($grooming['quote'])->toBe(astData('proposed_game_parameters.american_staffordshire_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(astRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(astRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S628');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', AST_SLUG);
        // The 60-min meal gap (PetMD S628 "always avoid exercise immediately after feeding", M5-R16).
        $gap = $mine->firstWhere('key', StageParamKey::MealExerciseGapMinutes->value);
        expect($gap['value'])->toBe(60)
            ->and($gap['source_id'])->toBe('S628')
            ->and($gap['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R16_MEAL_EXERCISE_GAP);
        // PetMD (S628): ear cleaning and bath reminder every 4 weeks (M5-R20 / R19); no tooth / fold / nail / groomer
        // frequency, not flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('value', 'key')->all())->toEqual([
            StageParamKey::EarCleaningFrequency->value => [1, 28],
            StageParamKey::BathFrequency->value => [1, 28],
        ]);
        foreach ($care as $row) {
            expect($row['source_id'])->toBe('S628')
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
            // M5-R17: the puppy's weekly "new experience" (PetMD S628).
            StageParamKey::SocialisationTasksPerWeek,
            // M5-R16: the 60-min meal / walk gap (PetMD S628).
            StageParamKey::MealExerciseGapMinutes,
            // M5-R19 / R20: the breed's sourced ear cleaning and bath reminder (the Border Collie has none).
            StageParamKey::EarCleaningFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(AST_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = astPet($arrival);
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
        'adult 112 months' => [112, LifeStage::Adult, 60, 6000],
        'senior 113 months' => [113, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 113 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(AST_SLUG, 112))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(AST_SLUG, 113))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren rank 34, × 1.2) with no individual factor', function () {
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
        $body = ['breed' => AST_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
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
            ->and($pet->breed_type)->toBe(BreedType::AmericanStaffordshireTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 113 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', AST_KEY] as $breed) {
            $pin = astPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(astLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[AST_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(113)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(AST_KEY)
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
    it('lists the American Staffordshire Terrier after the Irish Wolfhound with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, AST_KEY, 'irish_wolfhound');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => AST_KEY, 'slug' => AST_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.american_staffordshire_terrier',
            'search_keywords' => seededBreedConfig(AST_KEY)['search_keywords'], 'sort_order' => 750,
            'suitability' => [
                'suits' => ['children'],
                'consider' => ['needs_company', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S626 "Any colour, solid, particolour, or patched is permissible;")', function () {
    it('samples DNA in brindle, blue or black only, never black and tan, liver, merle or mostly white, with natural ears and tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(AST_KEY)
            ->and(PetDnaService::hasAppearance(AST_KEY))->toBeTrue()
            ->and(config('breed_appearance.american_staffordshire_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.american_staffordshire_terrier.prompt_order'))->toContain('head')->toContain('ear_carriage')->toContain('tail');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample(AST_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('short, close')
                ->and($traits['ear_carriage'])->toContain('uncropped')
                ->and($traits['tail'])->toContain('natural tail');

            $prompt = $prompts->imagePrompt(AST_KEY, $traits);
            expect($prompt)->toContain('American Staffordshire Terrier')
                ->not->toMatch('/\b(merle|docked tail|black and tan|liver)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        ksort($colours);
        expect(array_keys($colours))->toBe(['black', 'blue', 'brindle'])
            ->and(array_column(config('breed_colours.american_staffordshire_terrier'), 'value'))->toBe(['brindle', 'blue', 'black'])
            ->and(array_column(config('breed_colours.american_staffordshire_terrier'), 'key'))->toBe(['brindle', 'blue', 'black'])
            ->and(astData('american_staffordshire_terrier.appearance.colours.value'))->toBe(['brindle', 'blue', 'black']);
    });

    it('draws the register portrait brindle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AmericanStaffordshireTerrier);

        expect($traits['coat_color'])->toBe('brindle')
            ->and($portrait->prompt(BreedType::AmericanStaffordshireTerrier))->toContain('American Staffordshire Terrier')->toContain('brindle')
            ->and(BreedPortraitService::relativeFile(BreedType::AmericanStaffordshireTerrier))->toBe('dog/american-staffordshire-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AmericanStaffordshireTerrier);
        expect($v1['prompt_anchor'])->toContain('American Staffordshire Terrier')->toContain('uncropped ears')
            ->not->toMatch('/\b(merle|docked tail|black and tan)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['brindle', 'blue', 'black'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = astPet(113, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('American Staffordshire Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries no welfare or lifespan chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.american_staffordshire_terrier'),
            config('breed_suitability.breeds.american_staffordshire_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', AST_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::AmericanStaffordshireTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Not RKC-recognised (no Breed Watch); median 12.5 y → no welfare chip, no lifespan chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(astData('proposed_game_parameters.american_staffordshire_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (108 PetMD / VCA lower bound, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', AST_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits children; consider company and a strong prey drive — no family-pet, other-pets or shedding chip', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::AmericanStaffordshireTerrier);
        expect($tags['suits'])->toBe(['children'])
            ->and($tags['consider'])->toBe(['needs_company', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('often_alone')->not->toContain('family_pet')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('frequent_grooming');

        expect(astData('american_staffordshire_terrier.behaviour.other_pets.quote'))->toContain('strong prey drive')
            ->and(astData('american_staffordshire_terrier.behaviour.time_alone.quote'))->toContain('separation anxiety')
            ->and(astData('american_staffordshire_terrier.behaviour.children.notes'))->toContain('supervision');
    });
});
