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
| M5-R10-19 — Rottweiler (first breed appended after the 20: AKC 2024 No. 8)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `rottweiler` (S166–S174)
| and the runbook's standing rules (confirmed by David 2026-10-10) in
| `proposed_game_parameters.rottweiler` (exercise 120 min, senior 90 min,
| puppy 10 min × age capped at 120, stages 9 / 36 / 81 — senior from the
| VetCompass median 9.0 y —, arrival 2 / 9 / 36 / 81, learning × 1.8, paid
| breed, Border Collie care rates, suitability active_family / family_pet /
| large_home + long_daily_exercise / experienced_owner (new key) /
| hips_hind_legs / shorter_lifespan / sheds; black and tan only, natural tail,
| moderate muzzle).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function rotData(string $path): mixed
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

/** @return array<string, mixed> the Rottweiler seeder row of (stage, from, key) */
function rotRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'rottweiler'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("rottweiler.{$stage}.{$from}.{$key->value}");

    return $row;
}

function rotPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->rottweiler()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function rotPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function rotLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Rottweiler;
        $config = BreedConfig::where('breed_slug', 'rottweiler')->sole();

        expect($breed->value)->toBe('rottweiler')
            ->and($breed->slug())->toBe('rottweiler')
            ->and(BreedType::fromSlug('rottweiler'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 150, 'breeds.rottweiler'])
            ->and($config->search_keywords)->toBe(['rottweiler', 'rottie', 'rotty', 'rott', 'rotvajler', 'rotvajlerka', 'rotweiler', 'rotvailer'])
            // Legacy fallback = the adult step goal (120 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) rotData('proposed_game_parameters.rottweiler.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(12000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = rotData('proposed_game_parameters.rottweiler.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Rottweiler pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = rotPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('rottweiler');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'rottie']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_21_120000_add_rottweiler_breed.php');
        // down() refuses while a Rottweiler exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'rottweiler']))->toThrow(QueryException::class);
        // The Siberian Husky (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_husky']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'rottweiler']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Rottweiler);
        // The cat list of pets_species_breed_check is untouched: a Bengal is still a cat.
        expect($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'rottweiler')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rottweiler')->count());

        BreedConfig::where('breed_slug', 'rottweiler')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'rottweiler')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'rottweiler')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'rottweiler');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rottweiler');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'cavalier-king-charles-spaniel', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier', 'bernese-mountain-dog', 'siberian-husky', 'bulldog', 'german-shorthaired-pointer', 'yorkshire-terrier', 'cane-corso', 'pembroke-welsh-corgi', 'dobermann', 'boxer', 'miniature-schnauzer', 'shih-tzu'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_ROTTWEILER)->all())->toBe([]);
        foreach (['CONFIRMED_R10', 'CONFIRMED_R10_CAVALIER', 'CONFIRMED_R10_BEAGLE', 'CONFIRMED_R10_STANDARD_POODLE', 'CONFIRMED_R10_DACHSHUND', 'CONFIRMED_R10_AUSTRALIAN_SHEPHERD', 'CONFIRMED_R10_BERNESE_MOUNTAIN_DOG', 'PROPOSED_R10_BERNESE_SENIOR', 'CONFIRMED_R10_SIBERIAN_HUSKY'] as $constant) {
            expect($mine->where('decision', constant(BreedStageParamsSeeder::class.'::'.$constant))->all())->toBe([], $constant);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.rottweiler';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(rotRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(rotData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(rotRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rotData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(rotRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(rotData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.0 y (VetCompass median, S171) = 81.
            ->and(rotRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(rotData('rottweiler.lifespan.senior_from.months'))
            ->and(rotRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * rotRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(rotData('rottweiler.lifespan.senior_from.derived_from'))->toBe(['S11', 'S171'])
            ->and(rotRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(81);

        expect(rotRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rotData("{$pgp}.exercise_minutes_adult.value"))
            ->and(rotRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            ->and(rotRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(120)
            // 75 % of 120 = 90 exactly.
            ->and(rotRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(rotData("{$pgp}.exercise_minutes_senior.value"))
            ->and(rotRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(90)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(rotRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(rotData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(120)
            ->and(rotRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(rotData("{$pgp}.learning_multiplier.value"))
            ->and(rotRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.8);

        // Every Rottweiler decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_ROTTWEILER) {
                $decided++;
                expect($row['breed_slug'])->toBe('rottweiler')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.rottweiler.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) rotData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note and no "proposal, not built" left in the Rottweiler research;
        // the one sourced need without a mechanic (weekly brushing) points at its ROADMAP task.
        expect(json_encode([rotData('rottweiler'), rotData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(rotData("{$pgp}.grooming.value"))->toBe(1)
            ->and(rotData("{$pgp}.grooming.decision"))->toContain('ROADMAP M5-R13');
    });

    it('takes the breed-level numbers from the sourced rottweiler entries', function () {
        $fci = rotData('rottweiler.adult_weight.fci.value');

        // FCI standard (S166): dogs 50 kg, bitches approximately 42 kg → 42–50 kg.
        expect(rotRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([(float) min($fci['female'][0], $fci['male'][0]), (float) max($fci['female'][1], $fci['male'][1])])
            ->and(rotRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([42.0, 50.0])
            ->and(rotRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(rotData('rottweiler.adult_weight.fci.source_id'))
            ->and(rotRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([18, 24])
            ->and(rotData('rottweiler.growth.adult_weight_reached.value'))->toStartWith('18–24 months')
            ->and(rotRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(rotData('rottweiler.trainability.coren_rank.value'))
            ->and(rotRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(9)
            ->and(rotRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(rotData('rottweiler.lifespan.median_uk.value'))
            ->and(rotRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('rottweiler.lifespan.median_uk')
            ->and(rotRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S171')
            ->and(rotRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(9.0)
            // No McMillan 2024 value was reachable — nothing unsourced at runtime; PDSA's band is an alternative only.
            ->and(rotData('rottweiler.lifespan.mcmillan_2024.value'))->toBeNull()
            ->and(rotData('rottweiler.lifespan.rkc.value'))->toBe('< 10');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rottweiler')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rottweiler')->where('verified', false)->pluck('key')->all())
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

        expect($general('rottweiler'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 120 minutes = 12,000 steps and 90 minutes = 9,000 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = rotPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps)
            ->and($rules->verified())->toBeTrue()
            ->and($rules->unverifiedKeys())->toBe([]);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 6 months' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (10 min × age, below the cap)' => [9, LifeStage::Young, 90, 9000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 120, 12000],
        'adult 36 months' => [36, LifeStage::Adult, 120, 12000],
        'adult 80 months' => [80, LifeStage::Adult, 120, 12000],
        'senior 81 months' => [81, LifeStage::Senior, 90, 9000],
    ]);

    it('becomes senior at 81 months, after the Bernese Mountain Dog and before the Siberian Husky', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('rottweiler', 80))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('rottweiler', 81))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('bernese-mountain-dog', 76))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('siberian-husky', 81))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('border-collie', 81))->toBe(LifeStage::Adult);
    });

    it('learns at 1.8 × the mixed-breed speed (Coren Brightest tier) with no individual factor', function () {
        $training = app(TrainingService::class);
        $rottweiler = rotPet(36);
        $date = $rottweiler->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($rottweiler))->toBe(1.0)
            ->and($training->gainPerSuccess($rottweiler, $date))->toBe(1.8);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'rottweiler', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = rotPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(rotLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Rottweiler)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 81 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'rottweiler'] as $breed) {
            $pin = rotPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(rotLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['rottweiler']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['rottweiler']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['rottweiler']->arrival_age_months)->toBe(81)
            ->and($pets['rottweiler']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Rottweiler appearance data.
            ->and($pets['rottweiler']->pet_dna['breed'])->toBe('rottweiler')
            ->and((int) $pets['rottweiler']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = rotPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Rottweiler after the Siberian Husky with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier', 'bernese_mountain_dog', 'siberian_husky', 'rottweiler', 'bulldog', 'german_shorthaired_pointer', 'yorkshire_terrier', 'cane_corso', 'pembroke_welsh_corgi', 'dobermann', 'boxer', 'miniature_schnauzer', 'shih_tzu'])
            ->and($breeds[15])->toBe([
                'breed' => 'rottweiler', 'slug' => 'rottweiler', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.rottweiler',
                'search_keywords' => BreedConfigsSeeder::configs()[15]['search_keywords'], 'sort_order' => 150,
                'suitability' => [
                    'suits' => ['active_family', 'family_pet', 'large_home'],
                    'consider' => ['long_daily_exercise', 'experienced_owner', 'hips_hind_legs', 'shorter_lifespan', 'sheds'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules: black and tan only, natural tail, moderate muzzle, sound hind legs)', function () {
    it('samples Rottweiler DNA inside the standard (black with tan markings, dark brown eyes)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('rottweiler')
            ->and(PetDnaService::hasAppearance('rottweiler'))->toBeTrue()
            ->and(config('breed_appearance.rottweiler.verified'))->toBeFalse();

        $colours = [];
        foreach (range(1, 200) as $seed) {
            $traits = $dna->sample('rottweiler', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            expect($traits['tail'])->toContain('undocked')
                ->and($traits['muzzle'])->toContain('never short')
                ->and($traits['eye_color'])->toBe('dark brown, almond-shaped');

            $prompt = $prompts->imagePrompt('rottweiler', $traits);
            expect($prompt)->toContain('Rottweiler')
                ->toContain('black with clearly defined')
                ->not->toContain('white markings')
                ->not->toMatch('/\bdocked tail/')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        expect(count($colours))->toBe(2);
        foreach (array_keys($colours) as $colour) {
            expect($colour)->toStartWith('black with clearly defined');
        }
    });

    it('draws the register portrait black with rich tan markings', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Rottweiler);

        expect($traits['coat_color'])->toStartWith('black with clearly defined rich tan markings')
            ->and($traits['muzzle'])->toContain('moderate muzzle')
            ->and($portrait->prompt(BreedType::Rottweiler))->toContain('Rottweiler')->toContain('rich tan')->toContain('undocked')
            ->and(BreedPortraitService::relativeFile(BreedType::Rottweiler))->toBe('dog/rottweiler.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Rottweiler);
        expect($v1['prompt_anchor'])->toContain('Rottweiler')->toContain('rich tan')->toContain('natural undocked tail')->toContain('moderate muzzle')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['black with rich tan markings', 'black with rust markings', 'black with mahogany markings'])
            ->and($v1['visual_traits']['eye_color'])->toBe('dark brown');

        $pet = rotPet(81, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Rottweiler')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; welfare chip, in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, carries the hind-leg chip and may appear in marketing', function () {
        $app = json_encode([
            config('breed_appearance.rottweiler'),
            config('breed_suitability.breeds.rottweiler'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'rottweiler')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '33.0%', '7.46', '%)'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        expect((string) json_encode(config('breed_appearance.rottweiler.traits')))->not->toContain('white')->not->toMatch('/\bdocked tail/');
        $consider = app(BreedSuitability::class)->for(BreedType::Rottweiler)['consider'];
        expect(array_keys(config('breed_suitability.vocabulary')))->not->toContain('hypoallergenic')
            // Welfare rule: RKC Breed Watch names hind conformation (S173); not flat-faced.
            ->and($consider)->toContain('hips_hind_legs')
            ->and($consider)->not->toContain('brachycephalic_breathing')
            ->and(rotData('rottweiler.health.breed_watch.quote'))->toBe('Incorrect hind conformation and/or poor rear movement')
            ->and(rotData('proposed_game_parameters.rottweiler.marketing.decision'))->toContain('in marketing yes');
    });
});
