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
| M5-R10-72 — Chinese Crested (appended after the Bullmastiff: AKC 2024 No. 68)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `chinese_crested` (S570–S575) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.chinese_crested` (exercise 30 min — RKC "Up to 30 minutes
| per day" —, senior 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 121 (0.75 × McMillan 2024 median 13.4 y,
| Dogs Trust table S123), arrival 2 / 9 / 36 / 121, learning × 0.7 (Coren rank 61, Fair), paid breed, Border Collie
| care rates, brushing 1× a week (RKC "Once a week", M5-R13), puppy socialisation with the PetMD quote (M5-R17), nail
| trimming once a week (PetMD "Check your dog's toenails once a week", M5-R19)). No meal gap (no source).
| Suitability: suits family_pet / children / apartment / other_pets; consider sun_protection (new key) and cold_sensitive
| (bare skin, PetMD). Weight from PetMD (the FCI standard gives none). Hairless variety, pink skin with dark spots portrait;
| not a flat-faced breed → in marketing.
*/

const CCR_KEY = 'chinese_crested';
const CCR_SLUG = 'chinese-crested';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ccrData(string $path): mixed
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

/** @return array<string, mixed> the Chinese Crested seeder row of (stage, from, key) */
function ccrRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === CCR_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(CCR_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function ccrPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->chineseCrested()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ccrPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ccrLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::ChineseCrested;
        $config = BreedConfig::where('breed_slug', CCR_SLUG)->sole();

        expect($breed->value)->toBe(CCR_KEY)
            ->and($breed->slug())->toBe(CCR_SLUG)
            ->and(BreedType::fromSlug(CCR_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 670, 'breeds.chinese_crested'])
            ->and($config->search_keywords)->toBe(['chinese crested', 'chinese crested dog', 'crested', 'hairless dog', 'powder puff', 'powderpuff', 'kitajski goli pes', 'kitajski copasti pes', 'kitajski čopasti pes', 'goli pes'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ccrData('proposed_game_parameters.chinese_crested.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ccrData('proposed_game_parameters.chinese_crested.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Chinese Crested pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ccrPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(CCR_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'chinese_crested_dog']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'kitajski_goli_pes']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_02_03_120000_add_chinese_crested_breed.php');
        // down() refuses while a Chinese Crested exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => CCR_KEY]))->toThrow(QueryException::class);
        // The Bullmastiff (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bullmastiff']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => CCR_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::ChineseCrested);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', CCR_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CCR_SLUG)->count());

        BreedConfig::where('breed_slug', CCR_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', CCR_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', CCR_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', CCR_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CCR_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, CCR_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CHINESE_CRESTED)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_CHINESE_CRESTED');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.chinese_crested';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ccrRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ccrData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ccrRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ccrData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ccrRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ccrData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 13.4 y (McMillan 2024 median via the Dogs Trust table, S123) = 120.6 → 121 — the runbook rule, verified.
            ->and(ccrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ccrData('chinese_crested.lifespan.senior_from.months'))
            ->and(ccrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ccrRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(ccrData('chinese_crested.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(ccrRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S123')
            ->and(ccrRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(ccrRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(121);

        expect(ccrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ccrData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ccrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(ccrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(ccrData('chinese_crested.exercise.adult.quote'))
            ->and(ccrRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S572')
            ->and(ccrRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            // 75 % of 30 = 22.5 → 23 (half up).
            ->and(ccrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ccrData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ccrRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ccrRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ccrData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(ccrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ccrData("{$pgp}.learning_multiplier.value"))
            ->and(ccrRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CHINESE_CRESTED) {
                $decided++;
                expect($row['breed_slug'])->toBe(CCR_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.chinese_crested.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ccrData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; brushing (M5-R13), socialisation (M5-R17) and nail trimming
        // (M5-R19) are built; sun / cold care has no mechanic yet (needs a new ROADMAP task — David, listed in the breed doc).
        expect(json_encode([ccrData(CCR_KEY), ccrData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(ccrData("{$pgp}.grooming.value"))->toBe(1)
            ->and(ccrData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(ccrData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[CCR_KEY][1])
            ->and(ccrData('chinese_crested.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[CCR_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(CCR_KEY)
            ->and(ccrData("{$pgp}.nail_trimming.quote"))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[CCR_KEY]['nail_trim'][3])
            ->and(ccrData('chinese_crested.suitability.petmd_nails.quote'))->toBe(BreedStageParamsSeeder::DOG_CARE_TASKS[CCR_KEY]['nail_trim'][3])
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS[CCR_KEY]))->toBe(['nail_trim'])
            // Not flat-faced and no heat advice in any source → no no-walk-in-heat row.
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey(CCR_KEY);
    });

    it('takes the breed-level numbers from the sourced chinese_crested entries', function () {
        // PetMD (S574): 8–12 lb → 3.6–5.4 kg (the FCI standard gives no weight).
        expect(ccrRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.6, 5.4])
            ->and(ccrRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ccrData('chinese_crested.adult_weight.petmd.source_id'))
            ->and(ccrRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(ccrData('chinese_crested.adult_weight.petmd.quote'))
            ->and(ccrData('chinese_crested.adult_weight.petmd.value'))->toEqual(['male' => [3.6, 5.4], 'female' => [3.6, 5.4]])
            ->and(ccrRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(ccrData('chinese_crested.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 61 (S35), Fair tier → learning 0.7.
            ->and(ccrRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(61)
            ->and(ccrData('chinese_crested.trainability.coren_rank.value'))->toBe(61)
            ->and(ccrRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('chinese_crested.lifespan.median_uk')
            ->and(ccrRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S123')
            ->and(ccrRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.4)
            ->and(ccrRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(ccrData('chinese_crested.lifespan.median_uk.quote'));

        // Brushing 1× a week (RKC "Once a week" → 1) — the rule, verified.
        $grooming = ccrRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S572')
            ->and($grooming['quote'])->toBe(ccrData('chinese_crested.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(ccrData('proposed_game_parameters.chinese_crested.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PetMD quote.
            ->and(ccrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(ccrRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S574');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CCR_SLUG);
        // No meal / walk gap (no source).
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);
        // M5-R19 nail trimming once a week (PetMD S574); no tooth / fold / ear / bath / groomer frequency, no heat rule
        // → no other care rows.
        $care = $mine->filter(fn (array $r) => in_array($r['key'], [StageParamKey::ToothBrushingFrequency->value, StageParamKey::FoldCleaningFrequency->value,
            StageParamKey::EarCleaningFrequency->value, StageParamKey::NailTrimFrequency->value, StageParamKey::GroomerVisitFrequency->value,
            StageParamKey::BathFrequency->value, StageParamKey::NoWalkInHeat->value], true))->values();
        expect($care->pluck('key')->all())->toBe([StageParamKey::NailTrimFrequency->value])
            ->and($care->first()['value'])->toBe([1, 7])
            ->and($care->first()['source_id'])->toBe('S574')
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
            // M5-R17: the puppy's weekly "new experience" (PetMD S574).
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

        expect($general(CCR_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ccrPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 120 months' => [120, LifeStage::Adult, 30, 3000],
        'senior 121 months' => [121, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 121 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(CCR_SLUG, 120))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(CCR_SLUG, 121))->toBe(LifeStage::Senior);
    });

    it('learns slower than a mixed breed (Coren Fair tier, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = ccrPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => CCR_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ccrPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ccrLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::ChineseCrested)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 121 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', CCR_KEY] as $breed) {
            $pin = ccrPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ccrLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[CCR_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(121)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(CCR_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ccrPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Chinese Crested after the Bullmastiff with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, CCR_KEY, 'bullmastiff');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => CCR_KEY, 'slug' => CCR_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.chinese_crested',
            'search_keywords' => seededBreedConfig(CCR_KEY)['search_keywords'], 'sort_order' => 670,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'apartment', 'other_pets'],
                'consider' => ['sun_protection', 'cold_sensitive'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S571, any colour except merle; Hairless variety)', function () {
    it('samples DNA in the three colours of the appearance block only, hairless with crest, socks and plume', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(CCR_KEY)
            ->and(PetDnaService::hasAppearance(CCR_KEY))->toBeTrue()
            ->and(config('breed_appearance.chinese_crested.verified'))->toBeFalse()
            ->and(config('breed_appearance.chinese_crested.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('muzzle');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample(CCR_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['coat_length'])->toContain('hairless body')->toContain('crest')->toContain('plume')
                ->and($traits['ear_carriage'])->toContain('erect')
                ->and($traits['tail'])->toContain('plume')
                ->and($traits['coat_color'])->toContain('skin');

            $prompt = $prompts->imagePrompt(CCR_KEY, $traits);
            expect($prompt)->toContain('Chinese Crested')
                ->not->toMatch('/\b(merle|docked|cropped|powder ?puff|sunburn\w*|shaved)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = [
            'pink skin with dark grey spots and white crest, socks and plume',
            'solid dark slate-grey skin with white crest, socks and plume',
            'solid pink skin with cream crest, socks and plume',
        ];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.chinese_crested'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.chinese_crested'), 'key'))->toBe(['pink-spotted', 'slate', 'pink']);
    });

    it('draws the register portrait pink with dark spots', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::ChineseCrested);

        expect($traits['coat_color'])->toBe('pink skin with dark grey spots and white crest, socks and plume')
            ->and($portrait->prompt(BreedType::ChineseCrested))->toContain('Chinese Crested')->toContain('pink skin with dark grey spots')->toContain('crest')
            ->and(BreedPortraitService::relativeFile(BreedType::ChineseCrested))->toBe('dog/chinese-crested.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::ChineseCrested);
        expect($v1['prompt_anchor'])->toContain('Chinese Crested')->toContain('Hairless variety')->toContain('crest')
            ->not->toMatch('/\b(cropped|merle|docked|powder ?puff)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['pink skin with dark grey spots, white crest, socks and plume', 'solid dark slate-grey skin, white crest, socks and plume', 'solid pink skin, cream crest, socks and plume'])
            ->and($v1['visual_traits']['eye_color'])->toBe('very dark, almost black');

        $pet = ccrPet(121, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Chinese Crested')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the bare-skin chips and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.chinese_crested'),
            config('breed_suitability.breeds.chinese_crested'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', CCR_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '95% CI'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::ChineseCrested);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($tags['consider'])->toContain('sun_protection')->toContain('cold_sensitive')
            // Not flat-faced, no Breed Watch point returned → no welfare chip.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(ccrData('proposed_game_parameters.chinese_crested.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('hypoallergenic')->not->toContain('low_shedding')
            // The alternatives (108 RKC lower bound, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CCR_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(108)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits families, children (supervised), flats and other pets; consider sun protection and the cold', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::ChineseCrested);
        expect($tags['suits'])->toBe(['family_pet', 'children', 'apartment', 'other_pets'])
            ->and($tags['consider'])->toBe(['sun_protection', 'cold_sensitive'])
            ->and($tags['suits'])->not->toContain('small_children')
            // RKC "Sheds: Yes" covers both varieties; the game shows the Hairless → no shedding chip either way.
            ->and($tags['consider'])->not->toContain('sheds')->not->toContain('frequent_grooming')->not->toContain('chases_small_pets')
            ->and(config('breed_suitability.vocabulary.sun_protection'))->toBe('consider');

        expect(ccrData('chinese_crested.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(ccrData('chinese_crested.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Flat/ Apartment')
            ->and(ccrData('chinese_crested.behaviour.children.quote'))->toContain('should be supervised')
            ->and(ccrData('chinese_crested.health.bare_skin.quote'))->toContain('protection from the sun and cold');
    });
});
