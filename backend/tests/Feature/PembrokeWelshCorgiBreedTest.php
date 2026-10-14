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
| M5-R10-24 — Pembroke Welsh Corgi (appended after the Cane Corso: AKC 2024 No. 15)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `pembroke_welsh_corgi` (S202–S206)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.pembroke_welsh_corgi` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 108 — senior from the RKC lower
| bound "Over 12 years", provisional —, arrival 2 / 9 / 36 / 108, learning × 1.5, paid
| breed, Border Collie care rates, suitability: no suits tag, consider may_herd_children /
| sheds / frequent_grooming; FCI self colours with or without white markings, natural
| tail; no welfare chip).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function pcData(string $path): mixed
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

/** @return array<string, mixed> the Pembroke Welsh Corgi seeder row of (stage, from, key) */
function pcRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'pembroke-welsh-corgi'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("pembroke-welsh-corgi.{$stage}.{$from}.{$key->value}");

    return $row;
}

function pcPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->pembrokeWelshCorgi()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function pcPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function pcLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::PembrokeWelshCorgi;
        $config = BreedConfig::where('breed_slug', 'pembroke-welsh-corgi')->sole();

        expect($breed->value)->toBe('pembroke_welsh_corgi')
            ->and($breed->slug())->toBe('pembroke-welsh-corgi')
            ->and(BreedType::fromSlug('pembroke-welsh-corgi'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 200, 'breeds.pembroke_welsh_corgi'])
            ->and($config->search_keywords)->toBe(['pembroke welsh corgi', 'welsh corgi pembroke', 'pembroke', 'corgi', 'welsh corgi', 'korgi', 'valizanski korgi', 'valižanski korgi'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) pcData('proposed_game_parameters.pembroke_welsh_corgi.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = pcData('proposed_game_parameters.pembroke_welsh_corgi.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Pembroke Welsh Corgi pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = pcPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('pembroke_welsh_corgi');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'corgi']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_26_120000_add_pembroke_welsh_corgi_breed.php');
        // down() refuses while a Pembroke Welsh Corgi exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'pembroke_welsh_corgi']))->toThrow(QueryException::class);
        // The Cane Corso (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cane_corso']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'pembroke_welsh_corgi']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::PembrokeWelshCorgi);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'pembroke-welsh-corgi')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pembroke-welsh-corgi')->count());

        BreedConfig::where('breed_slug', 'pembroke-welsh-corgi')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'pembroke-welsh-corgi')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'pembroke-welsh-corgi')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'pembroke-welsh-corgi');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pembroke-welsh-corgi');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'belgian-malinois'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_PEMBROKE_WELSH_CORGI)->all())->toBe([]);
        // Every other R10 decision constant, found by reflection, is absent from the Pembroke Welsh Corgi's rows.
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10')) && $name !== 'CONFIRMED_R10_PEMBROKE_WELSH_CORGI');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.pembroke_welsh_corgi';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(pcRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(pcData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(pcRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pcData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(pcRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(pcData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 12 y (RKC lower bound "Over 12 years", S204) = 108 (provisional).
            ->and(pcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(pcData('pembroke_welsh_corgi.lifespan.senior_from.months'))
            ->and(pcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * pcRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(pcData('pembroke_welsh_corgi.lifespan.senior_from.derived_from'))->toBe(['S11', 'S204'])
            ->and(pcRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(pcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pcData("{$pgp}.exercise_minutes_adult.value"))
            ->and(pcRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(pcRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45.
            ->and(pcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(pcData("{$pgp}.exercise_minutes_senior.value"))
            ->and(pcRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(pcRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(pcData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(pcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(pcData("{$pgp}.learning_multiplier.value"))
            ->and(pcRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.5);

        // Every Pembroke Welsh Corgi decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_PEMBROKE_WELSH_CORGI) {
                $decided++;
                expect($row['breed_slug'])->toBe('pembroke-welsh-corgi')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.pembroke_welsh_corgi.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) pcData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the research; the sourced
        // needs without a mechanic (brushing 2–3× a week, socialisation) point at their ROADMAP tasks.
        expect(json_encode([pcData('pembroke_welsh_corgi'), pcData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(pcData("{$pgp}.grooming.value"))->toBe(3)
            ->and(pcData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(pcData("{$pgp}.socialisation.decision"))->toContain('ROADMAP M5-R17');
    });

    it('takes the breed-level numbers from the sourced pembroke_welsh_corgi entries', function () {
        // FCI males 10–12 kg, females 9–11 kg (S202) → game range 9–12 kg; RKC standard and PDSA agree.
        expect(pcRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([9.0, 12.0])
            ->and(pcData('pembroke_welsh_corgi.adult_weight.fci.value'))->toEqual(['male' => [10, 12], 'female' => [9, 11]])
            ->and(pcData('pembroke_welsh_corgi.adult_weight.pdsa.value'))->toEqual([9, 12])
            ->and(pcRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(pcData('pembroke_welsh_corgi.adult_weight.fci.source_id'))
            ->and(pcRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(pcData('pembroke_welsh_corgi.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(pcRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(pcData('pembroke_welsh_corgi.trainability.coren_rank.value'))
            ->and(pcRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(11)
            ->and(pcRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('pembroke_welsh_corgi.lifespan.rkc')
            ->and(pcRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S204')
            ->and(pcRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(pcData('pembroke_welsh_corgi.lifespan.rkc.quote'))->toBe('Lifespan: Over 12 years');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pembroke-welsh-corgi')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pembroke-welsh-corgi')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            // M5-R13: the breed's brushing routine (the Border Collie has none).
            StageParamKey::GroomingSessionsPerWeek,
            // M5-R17: the puppy's weekly "new experience" (sourced socialisation advice; the Border Collie has none).
            StageParamKey::SocialisationTasksPerWeek];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('pembroke-welsh-corgi'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = pcPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 3 months (10 min × age)' => [3, LifeStage::Puppy, 30, 3000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months, the same month as the Havanese', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('pembroke-welsh-corgi', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('pembroke-welsh-corgi', 108))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('havanese', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('havanese', 108))->toBe(LifeStage::Senior);
    });

    it('learns at 1.5 × the mixed-breed speed (Coren Excellent tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $corgi = pcPet(36);
        $date = $corgi->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($corgi))->toBe(1.0)
            ->and($training->gainPerSuccess($corgi, $date))->toBe(1.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'pembroke_welsh_corgi', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = pcPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(pcLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::PembrokeWelshCorgi)
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
        foreach (['border_collie', 'pembroke_welsh_corgi'] as $breed) {
            $pin = pcPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(pcLogin($pin)->assertOk()->json('pet.id'));
        }

        $corgi = $pets['pembroke_welsh_corgi'];
        expect($corgi->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($corgi->plan)->toBe(PetPlan::Challenge)
            ->and($corgi->arrival_age_months)->toBe(108)
            ->and($corgi->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Pembroke Welsh Corgi appearance data.
            ->and($corgi->pet_dna['breed'])->toBe('pembroke_welsh_corgi')
            ->and((int) $corgi->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = pcPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Pembroke Welsh Corgi after the Cane Corso with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois'])
            ->and(collect($breeds[20])->except('colours')->all())->toBe([
                'breed' => 'pembroke_welsh_corgi', 'slug' => 'pembroke-welsh-corgi', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.pembroke_welsh_corgi',
                'search_keywords' => BreedConfigsSeeder::configs()[20]['search_keywords'], 'sort_order' => 200,
                'suitability' => [
                    'suits' => [],
                    'consider' => ['may_herd_children', 'sheds', 'frequent_grooming'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rule: standard colours only; natural tail — a standard option; moderate body)', function () {
    it('samples Pembroke Welsh Corgi DNA inside the standard (self colours with white markings, undocked tail)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('pembroke_welsh_corgi')
            ->and(PetDnaService::hasAppearance('pembroke_welsh_corgi'))->toBeTrue()
            ->and(config('breed_appearance.pembroke_welsh_corgi.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('pembroke_welsh_corgi', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['ear_carriage'])->toContain('pricked')
                ->and($traits['body'])->toContain('moderate length');

            $prompt = $prompts->imagePrompt('pembroke_welsh_corgi', $traits);
            expect($prompt)->toContain('Pembroke Welsh Corgi')
                ->not->toMatch('/\b(merle|blue|brindle|chocolate|liver)\b/i')
                ->not->toMatch('/\bdocked tail/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        ksort($colours);
        expect(array_keys($colours))->toBe([
            'black and tan with white markings',
            'fawn with white markings',
            'red with white markings on legs, chest and neck',
            'sable with white markings',
        ]);
    });

    it('draws the register portrait red with white markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::PembrokeWelshCorgi);

        expect($traits['coat_color'])->toStartWith('red with white markings')
            ->and($portrait->prompt(BreedType::PembrokeWelshCorgi))->toContain('Pembroke Welsh Corgi')->toContain('red')->toContain('undocked')
            ->and(BreedPortraitService::relativeFile(BreedType::PembrokeWelshCorgi))->toBe('dog/pembroke-welsh-corgi.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::PembrokeWelshCorgi);
        expect($v1['prompt_anchor'])->toContain('Pembroke Welsh Corgi')->toContain('red coat and white markings')->toContain('natural undocked tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red', 'sable', 'fawn'])
            ->and($v1['visual_traits']['eye_color'])->toBe('brown');

        $pet = pcPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Pembroke Welsh Corgi')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; no welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.pembroke_welsh_corgi'),
            config('breed_suitability.breeds.pembroke_welsh_corgi'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'pembroke-welsh-corgi')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.pembroke_welsh_corgi.traits')))->not->toMatch('/\bdocked tail/')->not->toMatch('/\b(merle|blue|brindle)\b/');
        $consider = app(BreedSuitability::class)->for(BreedType::PembrokeWelshCorgi)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and($consider)->not->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('back_spine')
            ->and($consider)->not->toContain('shorter_lifespan')
            ->and(pcData('proposed_game_parameters.pembroke_welsh_corgi.marketing.decision'))->toContain('in marketing yes');
    });
});
