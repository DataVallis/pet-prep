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
| M5-R10-70 — Russell Terrier = FCI / RKC Jack Russell Terrier (appended after the Boykin Spaniel: AKC 2024 No. 66)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `russell_terrier` (S557–S564) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.russell_terrier` (exercise 60 min — RKC "Up to 1 hour
| per day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 120 (0.75 × McMillan 2024 median 13.3 y,
| Dogs Trust table S123), arrival 2 / 9 / 36 / 120, learning × 1.0 (Coren rank 46, Average), paid breed, Border Collie
| care rates, brushing 1× a week (RKC "Once a week", M5-R13), puppy socialisation with the PDSA quote (M5-R17), tooth
| brushing 3× a week (PetMD, M5-R15); no meal gap). Suitability: suits family_pet / first_time_owner / apartment;
| consider older_children, sheds, chases_small_pets. Weight from the FCI standard. White-and-tan portrait; RKC Breed
| Watch Category 1 → no welfare chip; in marketing.
*/

const RUS_KEY = 'russell_terrier';
const RUS_SLUG = 'jack-russell-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function rusData(string $path): mixed
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

/** @return array<string, mixed> the Russell Terrier seeder row of (stage, from, key) */
function rusRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === RUS_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(RUS_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function rusPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->russellTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function rusPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function rusLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::RussellTerrier;
        $config = BreedConfig::where('breed_slug', RUS_SLUG)->sole();

        expect($breed->value)->toBe(RUS_KEY)
            ->and($breed->slug())->toBe(RUS_SLUG)
            ->and(BreedType::fromSlug(RUS_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 650, 'breeds.russell_terrier'])
            ->and($config->search_keywords)->toBe(['jack russell terrier', 'jack russell', 'russell terrier', 'jrt', 'jack', 'jack russell terier', 'jack rasel terier', 'džek rasel'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) rusData('proposed_game_parameters.russell_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = rusData('proposed_game_parameters.russell_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Russell Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = rusPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(RUS_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'jack_russell_terrier']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'parson_russell_terrier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_01_120000_add_russell_terrier_breed.php');
        // down() refuses while a Russell Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => RUS_KEY]))->toThrow(QueryException::class);
        // The Airedale Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'airedale_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => RUS_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::RussellTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', RUS_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', RUS_SLUG)->count());

        BreedConfig::where('breed_slug', RUS_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', RUS_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', RUS_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', RUS_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', RUS_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, RUS_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_RUSSELL_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_RUSSELL_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.russell_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(rusRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(rusData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(rusRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rusData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(rusRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rusData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.3 y (McMillan 2024 median via the Dogs Trust table, S123) = 119.7 → 120 — the runbook rule, verified.
            ->and(rusRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rusData('russell_terrier.lifespan.senior_from.months'))
            ->and(rusRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * rusRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(rusData('russell_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(rusRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(rusRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(rusRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(120);

        expect(rusRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rusData("{$pgp}.exercise_minutes_adult.value"))
            ->and(rusRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(rusRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(rusData('russell_terrier.exercise.adult.quote'))
            ->and(rusRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S559')
            ->and(rusRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(rusRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rusData("{$pgp}.exercise_minutes_senior.value"))
            ->and(rusRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(rusRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(rusData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(rusRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(rusData("{$pgp}.learning_multiplier.value"))
            ->and(rusRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_RUSSELL_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(RUS_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.russell_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) rusData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13,
        // socialisation M5-R17, tooth brushing M5-R15).
        expect(json_encode([rusData(RUS_KEY), rusData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(rusData("{$pgp}.grooming.value"))->toBe(1)
            ->and(rusData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(rusData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[RUS_KEY][1])
            ->and(rusData('russell_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[RUS_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(RUS_KEY)
            ->and(rusData("{$pgp}.tooth_brushing.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[RUS_KEY]['tooth_brushing'][3])
            ->and(rusData('russell_terrier.suitability.petmd_teeth.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[RUS_KEY]['tooth_brushing'][3])
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[RUS_KEY]))->toBe(['tooth_brushing']);
    });

    it('takes the breed-level numbers from the sourced russell_terrier entries', function () {
        // FCI-Standard N° 345 (S558): 1 kg to each 5 cm → 5–6 kg at 25–30 cm.
        expect(rusRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([5, 6])
            ->and(rusRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(rusData('russell_terrier.adult_weight.fci.source_id'))
            ->and(rusRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(rusData('russell_terrier.adult_weight.fci.quote'))
            ->and(rusData('russell_terrier.adult_weight.fci.value'))->toEqual(['male' => [5, 6], 'female' => [5, 6]])
            ->and(rusRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(rusData('russell_terrier.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 46 (S35), Average tier → learning 1.0.
            ->and(rusRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(46)
            ->and(rusData('russell_terrier.trainability.coren_rank.value'))->toBe(46)
            ->and(rusRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('russell_terrier.lifespan.median_uk')
            ->and(rusRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(rusRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.3)
            ->and(rusRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(rusData('russell_terrier.lifespan.median_uk.quote'));

        // Brushing 1× a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = rusRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S559')
            ->and($grooming['quote'])->toBe(rusData('russell_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(rusData('proposed_game_parameters.russell_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PDSA quote.
            ->and(rusRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(rusRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S560');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', RUS_SLUG);
        // No meal-gap quote → no digestion warning row.
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);
        // M5-R15 tooth brushing 3× every 7 program days (PetMD S561); no ear / nail / bath / groomer frequency, not
        // flat-faced → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::ToothBrushingFrequency->value])
            ->and($care->first()['value'])->toBe([3, 7])
            ->and($care->first()['source_id'])->toBe('S561')
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
            // M5-R17: the puppy's weekly "new experience" (PDSA S560).
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

        expect($general(RUS_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = rusPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months (10 min × age)' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 119 months' => [119, LifeStage::Adult, 60, 6000],
        'senior 120 months' => [120, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 120 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(RUS_SLUG, 119))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(RUS_SLUG, 120))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = rusPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => RUS_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = rusPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(rusLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::RussellTerrier)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 120 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', RUS_KEY] as $breed) {
            $pin = rusPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(rusLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[RUS_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(120)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(RUS_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = rusPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Russell Terrier after the Airedale Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, RUS_KEY, 'airedale_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => RUS_KEY, 'slug' => RUS_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.russell_terrier',
            'search_keywords' => seededBreedConfig(RUS_KEY)['search_keywords'], 'sort_order' => 650,
            'suitability' => [
                'suits' => ['family_pet', 'first_time_owner', 'apartment'],
                'consider' => ['older_children', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S558, white predominant with black and/or tan)', function () {
    it('samples DNA in the three colours of the appearance block only, with button / dropped ears and a natural tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(RUS_KEY)
            ->and(PetDnaService::hasAppearance(RUS_KEY))->toBeTrue()
            ->and(config('breed_appearance.russell_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.russell_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(RUS_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('button or dropped')
                ->and($traits['tail'])->toContain('natural undocked')
                ->and($traits['coat_color'])->toStartWith('white with');

            $prompt = $prompts->imagePrompt(RUS_KEY, $traits);
            expect($prompt)->toContain('Jack Russell Terrier')
                ->not->toMatch('/\b(docked tail|cropped|merle|brindle|solid black|liver)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['white with tan markings', 'white with black and tan markings', 'white with black markings'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.russell_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.russell_terrier'), 'key'))->toBe(['white-tan', 'tricolour', 'white-black']);
    });

    it('draws the register portrait white with tan markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::RussellTerrier);

        expect($traits['coat_color'])->toBe('white with tan markings')
            ->and($portrait->prompt(BreedType::RussellTerrier))->toContain('Jack Russell Terrier')->toContain('white with tan markings')
            ->and(BreedPortraitService::relativeFile(BreedType::RussellTerrier))->toBe('dog/jack-russell-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::RussellTerrier);
        expect($v1['prompt_anchor'])->toContain('Jack Russell')->toContain('predominantly white')->toContain('undocked tail')
            ->not->toMatch('/\b(cropped|merle|brindle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['white with tan markings', 'white with black and tan markings', 'white with black markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = rusPet(120, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Jack Russell Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.russell_terrier'),
            config('breed_suitability.breeds.russell_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', RUS_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::RussellTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, not flat-faced → no welfare chip; McMillan 13.3 y → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(rusData('proposed_game_parameters.russell_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')
            // The alternatives (114 VetCompass, 90 RKC, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', RUS_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84)->not->toContain(90)->not->toContain(114);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family, a first-time owner and a flat; consider older children, shedding and the prey drive', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::RussellTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'first_time_owner', 'apartment'])
            ->and($tags['consider'])->toBe(['older_children', 'sheds', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('children')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('needs_company')->not->toContain('long_daily_exercise')->not->toContain('frequent_grooming');

        expect(rusData('russell_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(rusData('russell_terrier.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(rusData('russell_terrier.behaviour.other_pets.quote'))->toContain('very high prey drive')
            ->and(rusData('russell_terrier.suitability.pdsa_first_time_owner.quote'))->toContain('first-time owners');
    });
});
