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
| M5-R10-64 — Cardigan Welsh Corgi (appended after the Akita: AKC 2024 No. 60)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `cardigan_welsh_corgi` (S513–S518 + PDSA S206, one page for
| both corgis) and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.cardigan_welsh_corgi` (exercise 60 min — RKC "Up to 1 hour per day" —, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12 years"), arrival
| 2 / 9 / 36 / 108, learning × 1.5 (Coren rank 26, Excellent), paid breed, Border Collie care rates, brushing 3× a
| week (PDSA "two-three times per week", M5-R13), puppy socialisation with the PDSA quote (M5-R17); no meal gap,
| no care task). Suitability as the Pembroke: consider may_herd_children, sheds, frequent_grooming. Weight from the
| AKC standard (FCI / RKC give no number). Red with white markings portrait, blue merle a standard colour; in
| marketing (RKC Breed Watch Category 1).
*/

const CWC_KEY = 'cardigan_welsh_corgi';
const CWC_SLUG = 'cardigan-welsh-corgi';

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function cwcData(string $path): mixed
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

/** @return array<string, mixed> the Cardigan seeder row of (stage, from, key) */
function cwcRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === CWC_SLUG
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull(CWC_SLUG.".{$stage}.{$from}.{$key->value}");

    return $row;
}

function cwcPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->cardiganWelshCorgi()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function cwcPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function cwcLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::CardiganWelshCorgi;
        $config = BreedConfig::where('breed_slug', CWC_SLUG)->sole();

        expect($breed->value)->toBe(CWC_KEY)
            ->and($breed->slug())->toBe(CWC_SLUG)
            ->and(BreedType::fromSlug(CWC_SLUG))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 600, 'breeds.cardigan_welsh_corgi'])
            ->and($config->search_keywords)->toBe(['cardigan welsh corgi', 'welsh corgi cardigan', 'cardigan', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi', 'cardi'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) cwcData('proposed_game_parameters.cardigan_welsh_corgi.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = cwcData('proposed_game_parameters.cardigan_welsh_corgi.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Cardigan pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = cwcPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe(CWC_KEY);

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'welsh_corgi']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'cardigan']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_01_27_120000_add_cardigan_welsh_corgi_breed.php');
        // down() refuses while a Cardigan exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => CWC_KEY]))->toThrow(QueryException::class);
        // The Akita (previous dog migration) and the Pembroke stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'akita']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pembroke_welsh_corgi']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => CWC_KEY]);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::CardiganWelshCorgi);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', CWC_SLUG)->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CWC_SLUG)->count());

        BreedConfig::where('breed_slug', CWC_SLUG)->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', CWC_SLUG)->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', CWC_SLUG)->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', CWC_SLUG);
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CWC_SLUG);

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, CWC_SLUG))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CARDIGAN_WELSH_CORGI)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_CARDIGAN_WELSH_CORGI');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.cardigan_welsh_corgi';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(cwcRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(cwcData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(cwcRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(cwcData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(cwcRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(cwcData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S515) = 108 — the runbook rule, verified.
            ->and(cwcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(cwcData('cardigan_welsh_corgi.lifespan.senior_from.months'))
            ->and(cwcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * cwcRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(cwcData('cardigan_welsh_corgi.lifespan.senior_from.derived_from'))->toBe(['S11', 'S515'])
            ->and(cwcData('cardigan_welsh_corgi.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(cwcRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S515')
            ->and(cwcRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(cwcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(cwcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(cwcData("{$pgp}.exercise_minutes_adult.value"))
            ->and(cwcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(cwcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(cwcData('cardigan_welsh_corgi.exercise.adult.quote'))
            ->and(cwcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S515')
            ->and(cwcRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(cwcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(cwcData("{$pgp}.exercise_minutes_senior.value"))
            ->and(cwcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(cwcRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(cwcData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(cwcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(cwcData("{$pgp}.learning_multiplier.value"))
            ->and(cwcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CARDIGAN_WELSH_CORGI) {
                $decided++;
                expect($row['breed_slug'])->toBe(CWC_SLUG)
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.cardigan_welsh_corgi.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) cwcData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need is built (brushing M5-R13,
        // socialisation M5-R17); no meal gap quote, no care-task frequency.
        expect(json_encode([cwcData(CWC_KEY), cwcData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')->not->toContain('needs-david')
            ->and(cwcData("{$pgp}.grooming.value"))->toBe(3)
            ->and(cwcData("{$pgp}.grooming.decision"))->toContain('M5-R13')->not->toContain('čaka Davida')
            ->and(cwcData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION[CWC_KEY][1])
            ->and(cwcData('cardigan_welsh_corgi.behaviour.herding_children.notes'))->toContain(BreedStageParamsSeeder::DOG_SOCIALISATION[CWC_KEY][1])
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey(CWC_KEY)
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey(CWC_KEY);
    });

    it('takes the breed-level numbers from the sourced cardigan_welsh_corgi entries', function () {
        // AKC standard (S517): dogs 30–38 lb, bitches 25–34 lb → 11.3–17.2 kg (FCI / RKC give no number).
        expect(cwcRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([11.3, 17.2])
            ->and(cwcRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(cwcData('cardigan_welsh_corgi.adult_weight.akc.source_id'))
            ->and(cwcRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(cwcData('cardigan_welsh_corgi.adult_weight.akc.quote'))
            ->and(cwcData('cardigan_welsh_corgi.adult_weight.akc.value'))->toEqual(['male' => [13.6, 17.2], 'female' => [11.3, 15.4]])
            ->and(cwcRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(cwcData('cardigan_welsh_corgi.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            // Coren rank 26 (S35), Excellent tier → learning 1.5.
            ->and(cwcRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(26)
            ->and(cwcData('cardigan_welsh_corgi.trainability.coren_rank.value'))->toBe(26)
            ->and(cwcRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('cardigan_welsh_corgi.lifespan.rkc')
            ->and(cwcRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S515')
            ->and(cwcRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(cwcRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(cwcData('cardigan_welsh_corgi.lifespan.rkc.quote'));

        // Brushing 3× a week (PDSA "two-three times per week" → upper bound 3) — the rule, verified.
        $grooming = cwcRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S206')
            ->and($grooming['quote'])->toBe(cwcData('cardigan_welsh_corgi.suitability.pdsa_grooming.quote'))
            ->and($grooming['quote'])->toBe(cwcData('proposed_game_parameters.cardigan_welsh_corgi.grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            // The puppy's weekly "new experience" (M5-R17) with the PDSA quote.
            ->and(cwcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(cwcRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S206');

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', CWC_SLUG);
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
            // M5-R17: the puppy's weekly "new experience" (PDSA S206).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general(CWC_SLUG))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = cwcPet($arrival);
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

    it('becomes senior at 108 months, like the Pembroke Welsh Corgi', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge(CWC_SLUG, 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge(CWC_SLUG, 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('pembroke-welsh-corgi', 108))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = cwcPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => CWC_KEY, 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = cwcPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(cwcLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::CardiganWelshCorgi)
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
        foreach (['border_collie', CWC_KEY] as $breed) {
            $pin = cwcPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(cwcLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets[CWC_KEY];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe(CWC_KEY)
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = cwcPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Cardigan Welsh Corgi after the Akita with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, CWC_KEY, 'akita');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => CWC_KEY, 'slug' => CWC_SLUG, 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.cardigan_welsh_corgi',
            'search_keywords' => seededBreedConfig(CWC_KEY)['search_keywords'], 'sort_order' => 600,
            'suitability' => [
                'suits' => [],
                'consider' => ['may_herd_children', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI S514, blue merle included, white never predominating)', function () {
    it('samples DNA in the six colours of the appearance block only, with erect ears and a fox-brush tail', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain(CWC_KEY)
            ->and(PetDnaService::hasAppearance(CWC_KEY))->toBeTrue()
            ->and(config('breed_appearance.cardigan_welsh_corgi.verified'))->toBeFalse()
            ->and(config('breed_appearance.cardigan_welsh_corgi.prompt_order'))->toContain('ear_carriage')->toContain('tail')->toContain('body');

        $colours = [];
        foreach (range(1, 400) as $seed) {
            $traits = $dna->sample(CWC_KEY, $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('erect')
                ->and($traits['tail'])->toContain('fox')
                ->and($traits['body'])->toContain('ground clearance');

            $prompt = $prompts->imagePrompt(CWC_KEY, $traits);
            expect($prompt)->toContain('Cardigan Welsh Corgi')
                ->not->toMatch('/\b(docked|bobtail|liver|predominantly white|drop ears|floppy ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = ['red with white markings on neck, chest and legs', 'brindle with white markings', 'sable with white markings', 'blue merle with white markings', 'tricolour with brindle points', 'tricolour with red points'];
        expect(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.cardigan_welsh_corgi'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.cardigan_welsh_corgi'), 'key'))->toBe(['red-white', 'brindle-white', 'sable-white', 'blue-merle', 'tricolour-brindle', 'tricolour-red']);
    });

    it('draws the register portrait red with white markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::CardiganWelshCorgi);

        expect($traits['coat_color'])->toBe('red with white markings on neck, chest and legs')
            ->and($portrait->prompt(BreedType::CardiganWelshCorgi))->toContain('Cardigan Welsh Corgi')->toContain('red with white markings')
            ->and(BreedPortraitService::relativeFile(BreedType::CardiganWelshCorgi))->toBe('dog/cardigan-welsh-corgi.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::CardiganWelshCorgi);
        expect($v1['prompt_anchor'])->toContain('Cardigan Welsh Corgi')->toContain('red coat and white markings')->toContain('fox')
            ->not->toMatch('/\b(docked|bobtail|liver)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red', 'brindle', 'sable'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark');

        $pet = cwcPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Cardigan Welsh Corgi')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.cardigan_welsh_corgi'),
            config('breed_suitability.breeds.cardigan_welsh_corgi'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', CWC_SLUG)->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'times more', '%)', 'prevalence', '13.1'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::CardiganWelshCorgi);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // RKC Breed Watch Category 1, not flat-faced → no welfare chip; RKC "Over 12 years" → no shorter_lifespan.
            ->and(array_intersect($tags['consider'], ['brachycephalic_breathing', 'short_muzzle_breathing', 'back_spine', 'heart_and_spine', 'eyelid_problems', 'hips_hind_legs', 'sensitive_skin', 'shorter_lifespan']))->toBe([])
            ->and(cwcData('proposed_game_parameters.cardigan_welsh_corgi.marketing.value'))->toContain('in marketing: yes')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (118 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', CWC_SLUG)->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(118)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('gives no suits chip and the Pembroke\'s three consider chips; never family_pet, children or apartment', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::CardiganWelshCorgi);
        expect($tags['suits'])->toBe([])
            ->and($tags['consider'])->toBe(['may_herd_children', 'sheds', 'frequent_grooming'])
            ->and($tags['consider'])->toBe(app(BreedSuitability::class)->for(BreedType::PembrokeWelshCorgi)['consider'])
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('not_for_small_children');

        expect(cwcData('cardigan_welsh_corgi.suitability.rkc_shedding.quote'))->toBe('Sheds: Yes')
            ->and(cwcData('cardigan_welsh_corgi.behaviour.herding_children.quote'))->toBe('They may nip at the heels of running children')
            ->and(cwcData('cardigan_welsh_corgi.suitability.rkc_grooming.quote'))->toBe('Grooming: More than once a week');
    });
});
