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
| M5-R10-37 — American Cocker Spaniel (appended after the Brittany: AKC 2024 No. 32 "Cocker Spaniel")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `american_cocker_spaniel` (S303–S311) and the runbook's
| standing rules (confirmed by David 2026-10-10) in `proposed_game_parameters.american_cocker_spaniel`
| (exercise 120 min — RKC "More than 2 hours per day"; AKC / PetMD conflict, RKC preferred —, senior 90 min,
| puppy 10 min × age capped at 120, stages 9 / 36 / 90 — 0.75 × RKC lower bound 10 y, provisional until
| McMillan 2024 is checked —, arrival 2 / 9 / 36 / 90, learning × 1.5 (Coren rank 20, Excellent), paid
| breed, Border Collie care rates, brushing every day (RKC "Every day" → 7, M5-R13), puppy socialisation
| with the PetMD quote (M5-R17), no meal / walk warning (no source); suitability: suits family_pet /
| children / other_pets, consider frequent_grooming / food_motivated_weight / sheds (no exercise chip —
| the sources conflict); buff (portrait), never merle, never blue eyes, natural tail; Breed Watch
| Category 2 without a named point → no welfare chip, in marketing).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function acsData(string $path): mixed
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

/** @return array<string, mixed> the American Cocker Spaniel seeder row of (stage, from, key) */
function acsRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'american-cocker-spaniel'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("american_cocker_spaniel.{$stage}.{$from}.{$key->value}");

    return $row;
}

function acsPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->americanCockerSpaniel()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function acsPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function acsLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::AmericanCockerSpaniel;
        $config = BreedConfig::where('breed_slug', 'american-cocker-spaniel')->sole();

        expect($breed->value)->toBe('american_cocker_spaniel')
            ->and($breed->slug())->toBe('american-cocker-spaniel')
            ->and(BreedType::fromSlug('american-cocker-spaniel'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 330, 'breeds.american_cocker_spaniel'])
            ->and($config->search_keywords)->toBe(['american cocker spaniel', 'ameriški koker španjel', 'ameriski koker spanjel', 'american cocker', 'ameriški koker', 'ameriski koker'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) acsData('proposed_game_parameters.american_cocker_spaniel.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = acsData('proposed_game_parameters.american_cocker_spaniel.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts an American Cocker Spaniel pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = acsPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('american_cocker_spaniel');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'cocker_spaniel']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_12_15_120000_add_american_cocker_spaniel_breed.php');
        // down() refuses while an American Cocker Spaniel exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'american_cocker_spaniel']))->toThrow(QueryException::class);
        // The Brittany (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'brittany']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'american_cocker_spaniel']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::AmericanCockerSpaniel);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'american-cocker-spaniel')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')->count());

        BreedConfig::where('breed_slug', 'american-cocker-spaniel')->sole()->update(['daily_steps_required' => 5500]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'american-cocker-spaniel')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'american-cocker-spaniel')->value('daily_steps_required'))->toBe(5500);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'american-cocker-spaniel');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'american-cocker-spaniel'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_COCKER_SPANIEL)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the American Cocker Spaniel's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_AMERICAN_COCKER_SPANIEL');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.american_cocker_spaniel';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(acsRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(acsData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(acsRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(acsData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(acsRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(acsData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 10 y (RKC lower bound "Over 10 years", S305) = 90 — provisional until McMillan 2024 is checked.
            ->and(acsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(acsData('american_cocker_spaniel.lifespan.senior_from.months'))
            ->and(acsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * acsRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(acsData('american_cocker_spaniel.lifespan.senior_from.derived_from'))->toBe(['S11', 'S305'])
            ->and(acsRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S305')
            ->and(acsRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(acsRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(acsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(acsData("{$pgp}.exercise_minutes_adult.value"))
            ->and(acsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(acsRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(acsData('american_cocker_spaniel.exercise.adult.quote'))
            ->and(acsRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90.
            ->and(acsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(acsData("{$pgp}.exercise_minutes_senior.value"))
            ->and(acsRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(acsRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(acsData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(acsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(acsData("{$pgp}.learning_multiplier.value"))
            ->and(acsRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every American Cocker Spaniel decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_AMERICAN_COCKER_SPANIEL) {
                $decided++;
                expect($row['breed_slug'])->toBe('american-cocker-spaniel')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.american_cocker_spaniel.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) acsData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No "proposal, not built" left in the research; every sourced need with a game mechanic is built
        // (brushing M5-R13, socialisation M5-R17); the groomer waits for ROADMAP M5-R18, ear care for M5-R20.
        expect(json_encode([acsData('american_cocker_spaniel'), acsData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(acsData("{$pgp}.grooming.value"))->toBe(7)
            ->and(acsData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(acsData("{$pgp}.professional_grooming.decision"))->toContain('ROADMAP M5-R18')
            ->and(acsData("{$pgp}.ear_care_bathing.decision"))->toContain('ROADMAP M5-R20')
            ->and(acsData("{$pgp}.socialisation.quote"))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['american_cocker_spaniel'][1])
            ->and(acsData('american_cocker_spaniel.behaviour.socialisation.quote'))->toBe(BreedStageParamsSeeder::DOG_SOCIALISATION['american_cocker_spaniel'][1])
            // No source quotes a gap after meals → no digestion warning.
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('american_cocker_spaniel');
    });

    it('takes the breed-level numbers from the sourced american_cocker_spaniel entries', function () {
        // AKC article 20–30 lb (S308) ≈ 9.1–13.6 kg; the FCI / AKC standards give no weight.
        expect(acsRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.1, 13.6])
            ->and(acsData('american_cocker_spaniel.adult_weight.akc.value'))->toEqual(['male' => [9.1, 13.6], 'female' => [9.1, 13.6]])
            ->and(acsRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(acsData('american_cocker_spaniel.adult_weight.akc.source_id'))
            ->and(acsRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(acsData('american_cocker_spaniel.adult_weight.akc.quote'))
            ->and(acsRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(acsData('american_cocker_spaniel.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            // Coren rank 20 (S35), Excellent tier → learning 1.5.
            ->and(acsRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(20)
            ->and(acsData('american_cocker_spaniel.trainability.coren_rank.value'))->toBe(20)
            ->and(acsRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('american_cocker_spaniel.lifespan.rkc')
            ->and(acsRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S305')
            ->and(acsRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(acsRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(acsData('american_cocker_spaniel.lifespan.rkc.quote'));

        // Brushing every day (RKC "Grooming: Every day" → 7; VCA 2–3× / PetMD at least weekly are alternatives).
        expect(acsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['value'])->toBe(7)
            ->and(acsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['source_id'])->toBe('S305')
            ->and(acsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(acsData('american_cocker_spaniel.suitability.rkc_grooming.quote'))
            ->and(acsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['quote'])->toBe(acsData('proposed_game_parameters.american_cocker_spaniel.grooming.quote'))
            ->and(acsRow('all', 0, StageParamKey::GroomingSessionsPerWeek)['verified'])->toBeTrue()
            // The puppy's weekly "new experience" (M5-R17) with the breed's own PetMD quote.
            ->and(acsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(acsRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S309');
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')
            ->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        $open = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')->where('verified', false);
        expect($open->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" with the breed's own PetMD quote (S309).
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

        expect($general('american-cocker-spaniel'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = acsPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 89 months' => [89, LifeStage::Adult, 120, 12000],
        'senior 90 months' => [90, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 90 months, like the Brittany', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('american-cocker-spaniel', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('american-cocker-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('brittany', 90))->toBe(LifeStage::Senior);
    });

    it('learns faster than a mixed breed (Coren Excellent tier, × 1.5) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = acsPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'american_cocker_spaniel', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = acsPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(acsLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::AmericanCockerSpaniel)
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
        foreach (['border_collie', 'american_cocker_spaniel'] as $breed) {
            $pin = acsPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(acsLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['american_cocker_spaniel'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the American Cocker Spaniel appearance data.
            ->and($dog->pet_dna['breed'])->toBe('american_cocker_spaniel')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = acsPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the American Cocker Spaniel after the Brittany with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'american_cocker_spaniel', 'brittany');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'american_cocker_spaniel', 'slug' => 'american-cocker-spaniel', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.american_cocker_spaniel',
            'search_keywords' => seededBreedConfig('american_cocker_spaniel')['search_keywords'], 'sort_order' => 330,
            'suitability' => [
                'suits' => ['family_pet', 'children', 'other_pets'],
                'consider' => ['frequent_grooming', 'food_motivated_weight', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only)', function () {
    it('samples American Cocker Spaniel DNA inside the AKC / FCI varieties (buff, black, black and tan, red, chocolate, parti-colours; never merle)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('american_cocker_spaniel')
            ->and(PetDnaService::hasAppearance('american_cocker_spaniel'))->toBeTrue()
            ->and(config('breed_appearance.american_cocker_spaniel.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('american_cocker_spaniel', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['ear_carriage'])->toContain('lobular')
                ->and($traits['eye_color'])->toContain('dark brown')
                ->and($traits['tail'])->toContain('natural undocked')
                ->and($traits['muzzle'])->toContain('broad, deep muzzle');

            $prompt = $prompts->imagePrompt('american_cocker_spaniel', $traits);
            expect($prompt)->toContain('American Cocker Spaniel')
                ->not->toMatch('/\b(merle|brindle|blue eyes|docked tail|floor-length show skirt)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe(['black (solid black)', 'black and tan (black with small tan points over the eyes, on the muzzle, chest and legs)', 'black and white parti-colour', 'buff (solid light golden cream)', 'chocolate (solid brown)', 'red (solid deep red)', 'red and white parti-colour', 'tricolour (black and white with tan points)']);
    });

    it('draws the register portrait buff', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::AmericanCockerSpaniel);

        expect($traits['coat_color'])->toStartWith('buff')
            ->and($traits['tail'])->toContain('natural undocked')
            ->and($portrait->prompt(BreedType::AmericanCockerSpaniel))->toContain('American Cocker Spaniel')->toContain('buff')
            ->and(BreedPortraitService::relativeFile(BreedType::AmericanCockerSpaniel))->toBe('dog/american-cocker-spaniel.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::AmericanCockerSpaniel);
        expect($v1['prompt_anchor'])->toContain('American Cocker Spaniel')->toContain('buff coat')->toContain('natural undocked tail')
            ->not->toContain('merle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['buff', 'black', 'red and white parti-colour'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = acsPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('American Cocker Spaniel')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip (Breed Watch Category 2 without a named point), in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.american_cocker_spaniel'),
            config('breed_suitability.breeds.american_cocker_spaniel'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $consider = app(BreedSuitability::class)->for(BreedType::AmericanCockerSpaniel)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->toBe(['frequent_grooming', 'food_motivated_weight', 'sheds'])
            ->and(array_intersect($consider, ['brachycephalic_breathing', 'short_muzzle_breathing', 'hips_hind_legs', 'back_spine', 'heart_and_spine', 'sensitive_skin', 'eyelid_problems', 'shorter_lifespan']))->toBe([])
            ->and(acsData('american_cocker_spaniel.suitability.rkc_breed_watch.value'))->toBe('Category 2')
            ->and(acsData('proposed_game_parameters.american_cocker_spaniel.marketing.value'))->toContain('in marketing: yes')
            // McMillan 2024 via Wikipedia (13.3 y, tier C) and the VCA lifespan (12–15 y) are research only — never seeded values.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'american-cocker-spaniel')->pluck('value')->all())->not->toContain(13.3)->not->toContain(120.0)->not->toContain(108);
    });
});
