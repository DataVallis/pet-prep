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
| M5-R10-88 — Brussels Griffon (appended after the Shar Pei: AKC 2024 No. 84 "Brussels Griffon")
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `brussels_griffon` (S669–S674) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.brussels_griffon` (exercise 30 min — RKC "Up to 30 minutes per day" —, senior
| 23 min, puppy 10 min × age capped at 30, stages 9 / 36 / 108 (0.75 × RKC lower bound 12 y), arrival 2 / 9 / 36 / 108, learning
| × 0.7 (Coren rank 59, Fair), paid breed, Border Collie care rates, puppy socialisation with the PetMD quote (M5-R17), care tasks
| with a PetMD frequency (face folds once a week M5-R14, teeth every day M5-R15, groomer every 4 weeks M5-R18) and the summer
| walk warning (M5-R14). Open for David: brushing 2× a week (RKC "More than once a week" has no number — Claude's proposal).
| Flat-faced welfare breed (PetMD brachycephalic, RKC Breed Watch Category 2): suits apartment / children / other_pets; consider
| brachycephalic_breathing / needs_company / sheds / frequent_grooming; red rough-coated portrait; not in marketing (BVA S92).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function brgData(string $path): mixed
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

/** @return array<string, mixed> the Brussels Griffon seeder row of (stage, from, key) */
function brgRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'brussels-griffon'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("brussels-griffon.{$stage}.{$from}.{$key->value}");

    return $row;
}

function brgPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->brusselsGriffon()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function brgPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function brgLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BrusselsGriffon;
        $config = BreedConfig::where('breed_slug', 'brussels-griffon')->sole();

        expect($breed->value)->toBe('brussels_griffon')
            ->and($breed->slug())->toBe('brussels-griffon')
            ->and(BreedType::fromSlug('brussels-griffon'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 820, 'breeds.brussels_griffon'])
            ->and($config->search_keywords)->toBe(['brussels griffon', 'griffon bruxellois', 'griffon', 'belgium griffon', 'bruseljski grifon', 'grifon', 'grifon bruxellois'])
            // Legacy fallback = the adult step goal (30 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) brgData('proposed_game_parameters.brussels_griffon.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(3000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = brgData('proposed_game_parameters.brussels_griffon.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Brussels Griffon pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = brgPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('brussels_griffon');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            // Other names are no breed keys (only search keywords).
            ->and($refused(['breed_type' => 'griffon_bruxellois']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'brussels-griffon']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_03_04_120000_add_brussels_griffon_breed.php');
        // down() refuses while a Brussels Griffon exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'brussels_griffon']))->toThrow(QueryException::class);
        // The Shar Pei (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'shar_pei']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'brussels_griffon']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BrusselsGriffon);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'brussels-griffon')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brussels-griffon')->count());

        BreedConfig::where('breed_slug', 'brussels-griffon')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'brussels-griffon')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'brussels-griffon')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'brussels-griffon');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brussels-griffon');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'brussels-griffon'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BRUSSELS_GRIFFON)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from this breed's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_BRUSSELS_GRIFFON');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.brussels_griffon';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(brgRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(brgData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(brgRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(brgData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(brgRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(brgData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S670) = 108 — the runbook's third lifespan source.
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(brgData('brussels_griffon.lifespan.senior_from.months'))
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(brgData("{$pgp}.senior_boundary_months.value"))
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * brgRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(brgData('brussels_griffon.lifespan.senior_from.derived_from'))->toBe(['S11', 'S670'])
            ->and(brgData('brussels_griffon.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe('S11,S10,S670')
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['verified'])->toBeTrue()
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BRUSSELS_GRIFFON)
            ->and(brgRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(brgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brgData("{$pgp}.exercise_minutes_adult.value"))
            ->and(brgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(brgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(brgData('brussels_griffon.exercise.adult.quote'))
            ->and(brgRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S670')
            ->and(brgRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(30)
            ->and(brgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(brgData("{$pgp}.exercise_minutes_senior.value"))
            ->and(brgRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(23)
            ->and(brgRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(brgData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(30)
            ->and(brgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(brgData("{$pgp}.learning_multiplier.value"))
            ->and(brgRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.7);

        // Every decision row of this breed cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BRUSSELS_GRIFFON) {
                $decided++;
                expect($row['breed_slug'])->toBe('brussels-griffon')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.brussels_griffon.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) brgData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need is built: brushing (M5-R13, Claude's proposal 2 — open for David), socialisation (M5-R17),
        // face folds / teeth / groomer (M5-R14 / R15 / R18) and the summer walk warning (M5-R14). No meal gap (no source).
        expect(json_encode([brgData('brussels_griffon'), brgData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(brgData("{$pgp}.grooming.value"))->toBe(2)
            ->and(brgData("{$pgp}.grooming.decision"))->toContain('M5-R13')->toContain('čaka Davida')
            ->and(brgData("{$pgp}.socialisation.decision"))->toContain('M5-R17')
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('brussels_griffon')
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['brussels_griffon'])->toBe(['S672', brgData('brussels_griffon.behaviour.socialisation.quote')])
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['brussels_griffon'][1])->toBe(brgData("{$pgp}.socialisation.quote"))
            ->and(array_keys(BreedStageParamsSeeder::DOG_CARE_TASKS['brussels_griffon']))->toBe(['fold_cleaning', 'tooth_brushing', 'groomer_visit'])
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT['brussels_griffon'])->toBe(['S672', brgData("{$pgp}.no_exercise_in_heat.quote"), "{$pgp}.no_exercise_in_heat"]);

        // The care tasks quote their data.json entries verbatim.
        foreach (BreedStageParamsSeeder::DOG_CARE_TASKS['brussels_griffon'] as $task => [$times, $period, $source, $quote, $ref]) {
            expect(brgData($ref)['quote'])->toBe($quote, $task)
                ->and(brgData($ref)['source_id'])->toBe($source, $task);
        }
        expect(array_map(fn (array $t) => [$t[0], $t[1]], BreedStageParamsSeeder::DOG_CARE_TASKS['brussels_griffon']))
            ->toBe(['fold_cleaning' => [1, 'week'], 'tooth_brushing' => [7, 'day'], 'groomer_visit' => [1, 'month']]);
    });

    it('takes the breed-level numbers from the sourced brussels_griffon entries', function () {
        // FCI-Standard N° 80 (S669) 3.5–6 kg, no split by sex.
        expect(brgRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([3.5, 6])
            ->and(brgData('brussels_griffon.adult_weight.fci.value'))->toEqual(['male' => [3.5, 6], 'female' => [3.5, 6]])
            ->and(brgRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(brgData('brussels_griffon.adult_weight.fci.source_id'))
            ->and(brgRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(brgData('brussels_griffon.adult_weight.fci.quote'))
            ->and(brgRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([8, 12])
            ->and(brgData('brussels_griffon.growth.adult_weight_reached.value'))->toStartWith('8–12 months')
            // Coren rank 59 (Fair) → learning 0.7.
            ->and(brgRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(59)
            ->and(brgData('brussels_griffon.trainability.coren_rank.value'))->toBe(59)
            ->and(brgRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('brussels_griffon.lifespan.rkc')
            ->and(brgRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S670')
            ->and(brgRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12)
            ->and(brgRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(brgData('brussels_griffon.lifespan.rkc.quote'));

        // Brushing 2× a week: RKC "More than once a week" has no number → Claude's proposal, open for David (verified = false).
        $grooming = brgRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(2)
            ->and($grooming['source_id'])->toBe('S670')
            ->and($grooming['quote'])->toBe(brgData('brussels_griffon.suitability.rkc_grooming.quote'))
            ->and($grooming['quote'])->toBe(brgData('proposed_game_parameters.brussels_griffon.grooming.quote'))
            ->and($grooming['verified'])->toBeFalse()
            ->and(BreedStageParamsSeeder::DOG_GROOMING['brussels_griffon'][5])->toBe(BreedStageParamsSeeder::PROPOSED_R13_BRUSSELS_GRIFFON)
            ->and(brgRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['value'])->toBe(1)
            ->and(brgRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S672')
            ->and(brgRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R17_SOCIALISATION);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brussels-griffon');
        expect($mine->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->count())->toBe(1)
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: the general teething chewing chance (as for every dog) and the brushing (Claude's proposal).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->sort()->values()->all())
            ->toBe(['all.'.StageParamKey::GroomingSessionsPerWeek->value, 'puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the breed's own socialisation quote (PetMD S672).
            StageParamKey::SocialisationTasksPerWeek,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            // M5-R14 / R15 / R18: the breed's care tasks and the summer walk warning (the Border Collie has none).
            ->reject(fn (array $r) => str_ends_with($r['key'], '_frequency') || $r['key'] === StageParamKey::NoWalkInHeat->value)
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('brussels-griffon'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 30 minutes = 3,000 steps and 23 minutes = 2,300 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = brgPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (capped)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 8 months (capped)' => [8, LifeStage::Puppy, 30, 3000],
        'young 9 months (capped)' => [9, LifeStage::Young, 30, 3000],
        'adult 36 months' => [36, LifeStage::Adult, 30, 3000],
        'adult 107 months' => [107, LifeStage::Adult, 30, 3000],
        'senior 108 months' => [108, LifeStage::Senior, 23, 2300],
    ]);

    it('becomes senior at 108 months, later than the Shar Pei', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('brussels-griffon', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('brussels-griffon', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('shar-pei', 107))->toBe(LifeStage::Senior);
    });

    it('learns slower than the mixed breed (Coren rank 59, × 0.7) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = brgPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(0.7);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'brussels_griffon', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = brgPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(brgLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BrusselsGriffon)
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
        foreach (['border_collie', 'brussels_griffon'] as $breed) {
            $pin = brgPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(brgLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['brussels_griffon'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('brussels_griffon')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = brgPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Brussels Griffon after the Shar Pei with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'brussels_griffon', 'shar_pei');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'brussels_griffon', 'slug' => 'brussels-griffon', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.brussels_griffon',
            'search_keywords' => seededBreedConfig('brussels_griffon')['search_keywords'], 'sort_order' => 820,
            'suitability' => [
                'suits' => ['apartment', 'children', 'other_pets'],
                'consider' => ['brachycephalic_breathing', 'needs_company', 'sheds', 'frequent_grooming'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — KC breed standard S671)', function () {
    it('samples DNA in the three KC colours only, never white markings, merle or brindle, with a moderate face', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('brussels_griffon')
            ->and(PetDnaService::hasAppearance('brussels_griffon'))->toBeTrue()
            ->and(config('breed_appearance.brussels_griffon.verified'))->toBeFalse()
            ->and(config('breed_appearance.brussels_griffon.prompt_order'))->toContain('muzzle')->toContain('tail')->toContain('head');

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('brussels_griffon', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['muzzle'])->toContain('clearly open');

            $prompt = $prompts->imagePrompt('brussels_griffon', $traits);
            expect($prompt)->toContain('Brussels Griffon')
                ->not->toMatch('/\b(merle|brindle|belge|white blaze|white patches|cropped|docked)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        // The three colours of the appearance block (= the colour picker, BreedColourSharedMediaTest).
        $standard = array_column(config('breed_appearance.brussels_griffon.traits.coat_color'), 'value');
        expect($standard)->toHaveCount(3)
            ->and(array_keys($colours))->toEqualCanonicalizing($standard)
            ->and(array_column(config('breed_colours.brussels_griffon'), 'value'))->toBe($standard)
            ->and(array_column(config('breed_colours.brussels_griffon'), 'key'))->toBe(['red', 'black-tan', 'black'])
            ->and(brgData('brussels_griffon.appearance.colours.value'))->toBe(['red', 'black and tan', 'black']);
    });

    it('draws the register portrait clear red', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BrusselsGriffon);

        expect($traits['coat_color'])->toBe('clear red')
            ->and($portrait->prompt(BreedType::BrusselsGriffon))->toContain('Brussels Griffon')->toContain('red')->toContain('clearly open')
            ->and(BreedPortraitService::relativeFile(BreedType::BrusselsGriffon))->toBe('dog/brussels-griffon.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BrusselsGriffon);
        expect($v1['prompt_anchor'])->toContain('Brussels Griffon')->toContain('clearly open nostrils')
            ->not->toMatch('/\b(merle|brindle|cropped|docked)\b/i')
            ->and($v1['visual_traits']['eye_color'])->toBe('very dark brown')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['clear red', 'black and rich tan']);

        $pet = brgPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Brussels Griffon')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, shows the flat-face chip and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.brussels_griffon'),
            config('breed_suitability.breeds.brussels_griffon'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brussels-griffon')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence', '61.7'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BrusselsGriffon);
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // PetMD (S672) flat face + hot, humid weather → the flat-face chip (French Bulldog / Pug precedent).
            ->and($tags['consider'])->toContain('brachycephalic_breathing')
            ->and($tags['consider'])->not->toContain('short_muzzle_breathing')->not->toContain('shorter_lifespan')
            ->and(brgData('proposed_game_parameters.brussels_griffon.marketing.source_id'))->toBe('S92')
            ->and(brgData('proposed_game_parameters.brussels_griffon.marketing.value'))->toContain('in marketing / store screenshots / social: no')
            ->and($tags['suits'])->not->toContain('low_shedding')
            // The alternatives (120 via Wikipedia, 84 Dogs Trust) are never seeded.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'brussels-griffon')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())->not->toContain(120)->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits a flat, children (supervised) and other pets; consider the flat face, company, shedding and grooming', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BrusselsGriffon);
        expect($tags['suits'])->toBe(['apartment', 'children', 'other_pets'])
            ->and($tags['consider'])->toBe(['brachycephalic_breathing', 'needs_company', 'sheds', 'frequent_grooming'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('family_pet')->not->toContain('often_alone');

        expect(brgData('brussels_griffon.exercise.adult.quote'))->toBe('Up to 30 minutes per day')
            ->and(brgData('brussels_griffon.behaviour.children.notes'))->toContain('supervision')
            ->and(brgData('brussels_griffon.behaviour.time_alone.quote'))->toContain('Velcro dogs')
            ->and(brgData('brussels_griffon.suitability.rkc_size_of_home.quote'))->toBe('Flat/ Apartment');
    });
});
