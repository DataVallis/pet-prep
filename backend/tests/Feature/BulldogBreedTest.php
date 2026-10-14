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
| M5-R10-20 — Bulldog (appended after the Rottweiler: AKC 2024 No. 9)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bulldog` (S175–S183)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.bulldog` (exercise 60 min, senior 45 min,
| puppy 10 min × age capped at 60, stages 9 / 36 / 88 — senior from the
| McMillan 2024 median 9.8 y —, arrival 2 / 9 / 36 / 88, learning × 0.5, paid
| breed, Border Collie care rates, suitability family_pet / other_pets +
| brachycephalic_breathing / sensitive_skin / shorter_lifespan / sheds;
| standard colours only (portrait red and white), moderate face with open
| nostrils, straight tail; flat-faced → not in marketing, BVA S92).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bdData(string $path): mixed
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

/** @return array<string, mixed> the Bulldog seeder row of (stage, from, key) */
function bdRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bulldog'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bulldog.{$stage}.{$from}.{$key->value}");

    return $row;
}
function bdPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->bulldog()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bdPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bdLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Bulldog;
        $config = BreedConfig::where('breed_slug', 'bulldog')->sole();

        expect($breed->value)->toBe('bulldog')
            ->and($breed->slug())->toBe('bulldog')
            ->and(BreedType::fromSlug('bulldog'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 160, 'breeds.bulldog'])
            ->and($config->search_keywords)->toBe(['bulldog', 'english bulldog', 'british bulldog', 'angleški buldog', 'angleski buldog', 'buldog'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bdData('proposed_game_parameters.bulldog.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = bdData('proposed_game_parameters.bulldog.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Bulldog pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bdPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bulldog');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'english_bulldog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_22_120000_add_bulldog_breed.php');
        // down() refuses while a Bulldog exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bulldog']))->toThrow(QueryException::class);
        // The Rottweiler (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rottweiler']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bulldog']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Bulldog);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bulldog')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bulldog')->count());

        BreedConfig::where('breed_slug', 'bulldog')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bulldog')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bulldog')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bulldog');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bulldog');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'rottweiler', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu', 'great-dane', 'pomeranian', 'boston-terrier', 'miniature-american-shepherd', 'chihuahua', 'english-springer-spaniel', 'shetland-sheepdog', 'brittany', 'american-cocker-spaniel', 'belgian-malinois'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BULLDOG)->all())->toBe([]);
        // No other breed's decision text (every CONFIRMED_R10* / PROPOSED_R10* constant except the Bulldog's).
        $constants = array_filter(
            (new ReflectionClass(BreedStageParamsSeeder::class))->getConstants(),
            fn (mixed $value, string $name) => preg_match('/^(CONFIRMED|PROPOSED)_R10/', $name) === 1 && $name !== 'CONFIRMED_R10_BULLDOG',
            ARRAY_FILTER_USE_BOTH,
        );
        expect($constants)->toHaveKeys(['CONFIRMED_R10', 'CONFIRMED_R10_FRENCH_BULLDOG', 'CONFIRMED_R10_ROTTWEILER']);
        foreach ($constants as $constant => $decision) {
            expect($mine->where('decision', $decision)->all())->toBe([], $constant);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bulldog';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bdRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bdRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bdData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bdRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bdData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.8 y (McMillan 2024, S54) = 88.2 → 88.
            ->and(bdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdData('bulldog.lifespan.senior_from.months'))
            ->and(bdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bdRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bdData('bulldog.lifespan.senior_from.derived_from'))->toBe(['S11', 'S54'])
            ->and(bdRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(88);

        expect(bdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bdRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bdRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(bdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bdRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(bdRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bdData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(bdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(bdData("{$pgp}.learning_multiplier.value"))
            ->and(bdRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(0.5);

        // Every Bulldog decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BULLDOG) {
                $decided++;
                expect($row['breed_slug'])->toBe('bulldog')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bulldog.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bdData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the Bulldog research;
        // the sourced needs without a mechanic point at their ROADMAP tasks.
        expect(json_encode([bdData('bulldog'), bdData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bdData("{$pgp}.grooming.value"))->toBe(1)
            ->and(bdData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13')
            ->and(bdData("{$pgp}.face_fold_cleaning.value"))->toBe('daily')
            ->and(bdData("{$pgp}.face_fold_cleaning.decision"))->toContain('ROADMAP M5-R14')
            ->and(bdData("{$pgp}.no_exercise_in_heat.decision"))->toContain('ROADMAP M5-R14');
    });

    it('takes the breed-level numbers from the sourced bulldog entries', function () {
        $fci = bdData('bulldog.adult_weight.fci.value');

        // FCI standard (S175): males 25 kg, females 23 kg → 23–25 kg (RKC S178 the same).
        expect(bdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) min($fci['female'][0], $fci['male'][0]), (float) max($fci['female'][1], $fci['male'][1])])
            ->and(bdRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([23.0, 25.0])
            ->and(bdRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(bdData('bulldog.adult_weight.fci.source_id'))
            ->and(bdData('bulldog.adult_weight.rkc.value'))->toBe($fci)
            ->and(bdRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(bdData('bulldog.growth.adult_weight_reached.value'))->toStartWith('12–15 months')
            ->and(bdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(bdData('bulldog.trainability.coren_rank.value'))
            ->and(bdRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(77)
            ->and(bdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(bdData('bulldog.lifespan.median_uk.value'))
            ->and(bdRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bulldog.lifespan.median_uk')
            ->and(bdRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S54')
            ->and(bdRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.8)
            // The VetCompass 2013 median is an alternative only (senior 65 not chosen).
            ->and(bdData('bulldog.lifespan.vetcompass_2013.value'))->toBe(7.2)
            ->and(bdData('bulldog.lifespan.senior_from.alternative.months'))->toBe(65)
            ->and(bdData('bulldog.lifespan.rkc.value'))->toBe('< 10');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bulldog')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bulldog')->where('verified', false)->pluck('key')->all())
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

        expect($general('bulldog'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bdPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months (reaches the cap)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 87 months' => [87, LifeStage::Adult, 60, 6000],
        'senior 88 months' => [88, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 88 months, like the French Bulldog and after the Rottweiler', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bulldog', 87))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('french-bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('rottweiler', 81))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('border-collie', 88))->toBe(LifeStage::Adult);
    });

    it('learns at 0.5 × the mixed-breed speed (Coren Lowest tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $bulldog = bdPet(36);
        $date = $bulldog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($bulldog))->toBe(1.0)
            ->and($training->gainPerSuccess($bulldog, $date))->toBe(0.5);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bulldog', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bdPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bdLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Bulldog)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 88 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bulldog'] as $breed) {
            $pin = bdPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bdLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['bulldog']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['bulldog']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['bulldog']->arrival_age_months)->toBe(88)
            ->and($pets['bulldog']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Bulldog appearance data.
            ->and($pets['bulldog']->pet_dna['breed'])->toBe('bulldog')
            ->and((int) $pets['bulldog']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bdPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bulldog after the Rottweiler with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu', 'great_dane', 'pomeranian', 'boston_terrier', 'miniature_american_shepherd', 'chihuahua', 'english_springer_spaniel', 'shetland_sheepdog', 'brittany', 'american_cocker_spaniel', 'belgian_malinois'])
            ->and(collect($breeds[16])->except('colours')->all())->toBe([
                'breed' => 'bulldog', 'slug' => 'bulldog', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bulldog',
                'search_keywords' => BreedConfigsSeeder::configs()[16]['search_keywords'], 'sort_order' => 160,
                'suitability' => [
                    'suits' => ['family_pet', 'other_pets'],
                    'consider' => ['brachycephalic_breathing', 'sensitive_skin', 'shorter_lifespan', 'sheds'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules: standard colours only, moderate face with open nostrils, straight tail)', function () {
    it('samples Bulldog DNA inside the standard (red, fawn, brindle, white markings or pied; very dark eyes)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bulldog')
            ->and(PetDnaService::hasAppearance('bulldog'))->toBeTrue()
            ->and(config('breed_appearance.bulldog.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('bulldog', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('straight')
                ->and($traits['muzzle'])->toContain('wide-open nostrils')
                ->and($traits['muzzle'])->toContain('not exaggerated')
                ->and($traits['eye_color'])->toBe('very dark, round, moderate size, no white showing');

            $prompt = $prompts->imagePrompt('bulldog', $traits);
            expect($prompt)->toContain('Bulldog')
                ->not->toContain('merle')
                ->not->toContain('black and tan')
                ->not->toContain('blue')
                ->not->toContain('screw tail')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['red', 'fawn', 'fawn brindle']);
    });

    it('draws the register portrait red with white markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Bulldog);

        expect($traits['coat_color'])->toBe('red')
            ->and($traits['coat_pattern'])->toBe('with white markings on the face, chest and legs')
            ->and($traits['muzzle'])->toContain('wide-open nostrils')
            ->and($portrait->prompt(BreedType::Bulldog))->toContain('Bulldog')->toContain('red')->toContain('wide-open nostrils')
            ->and(BreedPortraitService::relativeFile(BreedType::Bulldog))->toBe('dog/bulldog.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Bulldog);
        expect($v1['prompt_anchor'])->toContain('Bulldog')->toContain('red and white')->toContain('wide-open nostrils')->toContain('straight low-set tail')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['red and white', 'fawn brindle', 'pied (white with red patches)'])
            ->and($v1['visual_traits']['eye_color'])->toBe('very dark brown');

        $pet = bdPet(88, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bulldog')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chips, not in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the flat-face and skin chips and stays out of marketing', function () {
        $app = json_encode([
            config('breed_appearance.bulldog'),
            config('breed_suitability.breeds.bulldog'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bulldog')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', 'x14', 'HR 1.8', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.bulldog.traits')))->not->toContain('merle')->not->toContain('black and tan')->not->toContain('heavy');
        $consider = app(BreedSuitability::class)->for(BreedType::Bulldog)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Welfare rule: severely flat-faced (S180); Breed Watch skin-fold dermatitis (S182).
            ->and($consider)->toContain('brachycephalic_breathing')
            ->and($consider)->toContain('sensitive_skin')
            ->and(bdData('bulldog.health.brachycephaly_boas.quote'))->toBe('Bulldogs are a severely brachycephalic (flat-faced) breed.')
            // BVA (S92): no brachycephalic imagery in advertising → not in marketing (as the French Bulldog).
            ->and(bdData('proposed_game_parameters.bulldog.marketing.source_id'))->toBe('S92')
            ->and(bdData('proposed_game_parameters.bulldog.marketing.decision'))->toContain('NOT in marketing');
    });
});
