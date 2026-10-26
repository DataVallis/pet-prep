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
| M5-R10-67 — Staffordshire Bull Terrier (appended after the Saint Bernard: AKC 2024 No. 63)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `staffordshire_bull_terrier` (S536–S544) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.staffordshire_bull_terrier`
| (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min, puppy 10 min × age capped at 60, stages
| 9 / 36 / 108 (0.75 × RKC lower bound "Over 12 years"), arrival 2 / 9 / 36 / 108, learning × 1.0 (Coren rank 49,
| Average), paid breed, Border Collie care rates, brushing 1× a week (RKC "Once a week", M5-R13), puppy
| socialisation with the PetMD quote (M5-R17); no meal gap, no care task). Suitability: suits family_pet / children;
| consider chews_when_bored, needs_company, sheds, chases_small_pets (no heat_sensitive — its label names a thick coat). Weight from the FCI standard.
| Brindle portrait; RKC Breed Watch Category 2 without a returned point → no welfare chip; in marketing.
*/

const SBT_KEY = 'staffordshire_bull_terrier';
const SBT_SLUG = 'staffordshire-bull-terrier';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function sbtData(string $path): mixed
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

/** @return array<string, mixed> the Staffordshire Bull Terrier seeder row of (stage, from, key) */
function sbtRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === SBT_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(SBT_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function sbtPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->staffordshireBullTerrier()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function sbtPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function sbtLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::StaffordshireBullTerrier;
        $config = BreedConfig::where('breed_slug', SBT_SLUG)->sole();

        expect($breed->value)->toBe(SBT_KEY)
            ->and($breed->slug())->toBe(SBT_SLUG)
            ->and(BreedType::fromSlug(SBT_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 630, 'breeds.staffordshire_bull_terrier'])
            ->and($config->search_keywords)->toBe(['staffordshire bull terrier', 'staffordshire', 'staffie', 'staffy', 'stafford', 'sbt', 'stafordširski bulterier', 'stafordsirski bulterier', 'staffordshirski bulterier'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) sbtData('proposed_game_parameters.staffordshire_bull_terrier.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = sbtData('proposed_game_parameters.staffordshire_bull_terrier.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Staffordshire Bull Terrier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = sbtPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(SBT_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'staffie']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'staffordshire']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_30_120000_add_staffordshire_bull_terrier_breed.php');
        // down() refuses while a Staffordshire Bull Terrier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => SBT_KEY]))->toThrow(QueryException::class);
        // The Saint Bernard (previous dog migration) and the Bull-type neighbour breeds stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'saint_bernard']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'boston_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => SBT_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::StaffordshireBullTerrier);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', SBT_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', SBT_SLUG)->count());

        BreedConfig::where('breed_slug', SBT_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', SBT_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', SBT_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', SBT_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', SBT_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, SBT_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_STAFFORDSHIRE_BULL_TERRIER)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_STAFFORDSHIRE_BULL_TERRIER');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.staffordshire_bull_terrier';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(sbtRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(sbtData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(sbtRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(sbtData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(sbtRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(sbtData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S538) = 108 — the runbook rule, verified.
            ->and(sbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(sbtData('staffordshire_bull_terrier.lifespan.senior_from.months'))
            ->and(sbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * sbtRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(sbtData('staffordshire_bull_terrier.lifespan.senior_from.derived_from'))->toBe(['S11', 'S538'])
            ->and(sbtData('staffordshire_bull_terrier.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(sbtRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S538')
            ->and(sbtRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(sbtRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(sbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sbtData("{$pgp}.exercise_minutes_adult.value"))
            ->and(sbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(sbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(sbtData('staffordshire_bull_terrier.exercise.adult.quote'))
            ->and(sbtRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S538')
            ->and(sbtRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(sbtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(sbtData("{$pgp}.exercise_minutes_senior.value"))
            ->and(sbtRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(sbtRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(sbtData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(sbtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(sbtData("{$pgp}.learning_multiplier.value"))
            ->and(sbtRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_STAFFORDSHIRE_BULL_TERRIER) {
                $decided++;
                expect($row['breed_slug'])->toBe(SBT_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.staffordshire_bull_terrier.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) sbtData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13,
        // socialisation M5-R17); no meal gap quote, no care-task frequency.
        expect(json_encode([sbtData(SBT_KEY), sbtData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(sbtData("{$pgp}.grooming.value"))->toBe(1)
            ->and(sbtData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(sbtData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[SBT_KEY][1])
            ->and(sbtData('staffordshire_bull_terrier.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[SBT_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(SBT_KEY)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey(SBT_KEY);
    });

    it('takes the breed-level numbers from the sourced staffordshire_bull_terrier entries', function () {
        // FCI standard (S537): males 12.7–17 kg, females 11–15.4 kg → 11–17 kg.
        expect(sbtRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([11.0, 17.0])
            ->and(sbtRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(sbtData('staffordshire_bull_terrier.adult_weight.fci.source_id'))
            ->and(sbtRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(sbtData('staffordshire_bull_terrier.adult_weight.fci.quote'))
            ->and(sbtData('staffordshire_bull_terrier.adult_weight.fci.value'))->toEqual(['male' => [12.7, 17], 'female' => [11, 15.4]])
            ->and(sbtRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(sbtData('staffordshire_bull_terrier.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 49 (S35), Average tier → learning 1.0.
            ->and(sbtRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(49)
            ->and(sbtData('staffordshire_bull_terrier.trainability.coren_rank.value'))->toBe(49)
            ->and(sbtRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('staffordshire_bull_terrier.lifespan.rkc')
            ->and(sbtRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S538')
            ->and(sbtRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(sbtRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(sbtData('staffordshire_bull_terrier.lifespan.rkc.quote'));

        // Brushing 1× a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = sbtRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S538')
            ->and($grooming['quote'])->toBe(sbtData('staffordshire_bull_terrier.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(sbtData('proposed_game_parameters.staffordshire_bull_terrier.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(sbtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(sbtRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S541');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', SBT_SLUG);
        // No sourced frequency for teeth, folds, ears, nails, groomer or bath; not flat-faced → no care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value, StageParamKey::MealExerciseGapMinutes->value], true));
        expect($care->all())->toBe([])
            // Like the other pedigree breeds: no individual variation row.
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

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
            // M5-R17: the puppy's weekly "new experience" (PetMD S541).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(SBT_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = sbtPet($arrival);
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
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, like the Cardigan Welsh Corgi', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(SBT_SLUG, 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(SBT_SLUG, 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('cardigan-welsh-corgi', 108))->toBe(LifeStage::Senior);
    });

    it('learns like a mixed breed (Coren Average tier, × 1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = sbtPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => SBT_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = sbtPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(sbtLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::StaffordshireBullTerrier)
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
        foreach (['border_collie', SBT_KEY] as $breed) {
            $pin = sbtPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(sbtLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[SBT_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(SBT_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = sbtPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Staffordshire Bull Terrier after the Saint Bernard with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, SBT_KEY, 'saint_bernard');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => SBT_KEY, 'slug' => SBT_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.staffordshire_bull_terrier',
            'search_keywords' => seededBreedConfig(SBT_KEY)['search_keywords'], 'sort_order' => 630,
            'suitability' => [
                'suits' => ['family_pet', 'children'],
                'consider' => ['chews_when_bored', 'needs_company', 'sheds', 'chases_small_pets'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S537, never black and tan or liver)', function () {
    it('samples DNA in the eleven colours of the appearance block only, with rose ears, a natural tail and a visible muzzle', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(SBT_KEY)
            ->and(PetDnaService::hasAppearance(SBT_KEY))->toBeTrue()
            ->and(config('breed_appearance.staffordshire_bull_terrier.verified'))->toBeFalse()
            ->and(config('breed_appearance.staffordshire_bull_terrier.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 600) as $seed) {
            $traits = $dna->sample(SBT_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('rose')
                ->and($traits['tail'])->toContain('natural')
                ->and($traits['head'])->toContain('clearly visible muzzle');

            $prompt = $prompts->imagePrompt(SBT_KEY, $traits);
            expect($prompt)->toContain('Staffordshire Bull Terrier')
                ->not->toMatch('/\b(docked|cropped|liver|black and tan|merle|flat-faced|flat face)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['brindle', 'brindle and white', 'red', 'black and white', 'fawn', 'black', 'blue', 'red and white', 'blue and white', 'fawn and white', 'white'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.staffordshire_bull_terrier'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.staffordshire_bull_terrier'), 'key'))->toBe(['brindle', 'brindle-white', 'red', 'black-white', 'fawn', 'black', 'blue', 'red-white', 'blue-white', 'fawn-white', 'white']);
    });

    it('draws the register portrait brindle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::StaffordshireBullTerrier);

        expect($traits['coat_color'])->toBe('brindle')
            ->and($portrait->prompt(BreedType::StaffordshireBullTerrier))->toContain('Staffordshire Bull Terrier')->toContain('brindle')
            ->and(BreedPortraitService::relativeFile(BreedType::StaffordshireBullTerrier))->toBe('dog/staffordshire-bull-terrier.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::StaffordshireBullTerrier);
        expect($v1['prompt_anchor'])->toContain('Staffordshire Bull Terrier')->toContain('brindle coat')->toContain('visible muzzle')
            ->not->toMatch('/\b(docked|cropped|liver|merle)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['brindle', 'brindle and white', 'red'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = sbtPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Staffordshire Bull Terrier')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.staffordshire_bull_terrier'),
            config('breed_suitability.breeds.staffordshire_bull_terrier'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', SBT_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', 'x2.1', 'x1.9'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::StaffordshireBullTerrier);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 2 without a returned point, not flat-faced → no welfare chip (no sensitive_skin either); RKC "Over 12 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(sbtData('proposed_game_parameters.staffordshire_bull_terrier.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternative (84 Dogs Trust) is never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', SBT_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a family and children; consider chewing, company, shedding and a very strong prey drive (no thick-coat heat chip)', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::StaffordshireBullTerrier);
        expect($tags['suits'])->toBe(['family_pet', 'children'])
            ->and($tags['consider'])->toBe(['chews_when_bored', 'needs_company', 'sheds', 'chases_small_pets'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')
            ->and($tags['consider'])->not->toContain('not_for_small_children')->not->toContain('frequent_grooming')->not->toContain('heat_sensitive');

        expect(sbtData('staffordshire_bull_terrier.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(sbtData('staffordshire_bull_terrier.behaviour.other_pets.quote'))->toContain('very strong prey drive')
            ->and(sbtData('staffordshire_bull_terrier.behaviour.family.quote'))->toContain('wonderful family pet')
            ->and(sbtData('staffordshire_bull_terrier.exercise.heat.quote'))->toBe('Staffies are very averse to heat.')
            ->and(sbtData('staffordshire_bull_terrier.suitability.rkc_grooming.quote'))->toBe('Grooming: Once a week');
    });
});
