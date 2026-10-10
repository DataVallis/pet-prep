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
| M5-R10-05 — Cavalier King Charles Spaniel (fifth breed of the 20-breed expansion)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `cavalier_king_charles_spaniel`
| (S103–S110) and the runbook's standing rules (confirmed by David 2026-10-10)
| in `proposed_game_parameters.cavalier_king_charles_spaniel` (exercise 60 min,
| senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90, arrival
| 2 / 9 / 36 / 90, learning × 1.0, paid breed, Border Collie care rates,
| suitability incl. the new heart_and_spine; visible muzzle in the AI art,
| standard colours only).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function ckData(string $path): mixed
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

/** @return array<string, mixed> the Cavalier seeder row of (stage, from, key) */
function ckRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'cavalier-king-charles-spaniel'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("cavalier-king-charles-spaniel.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ckPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->cavalierKingCharlesSpaniel()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ckPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function ckLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}

describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::CavalierKingCharlesSpaniel;
        $config = BreedConfig::where('breed_slug', 'cavalier-king-charles-spaniel')->sole();

        expect($breed->value)->toBe('cavalier_king_charles_spaniel')
            ->and($breed->slug())->toBe('cavalier-king-charles-spaniel')
            ->and(BreedType::fromSlug('cavalier-king-charles-spaniel'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 60, 'breeds.cavalier_king_charles_spaniel'])
            ->and($config->search_keywords)->toBe(['cavalier king charles spaniel', 'cavalier', 'king charles', 'ckcs', 'spaniel', 'kavalir king charles španjel', 'kavalir king charles spanjel', 'kavalir', 'španjel', 'spanjel'])
            // Legacy fallback = the adult step goal David chose (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) ckData('proposed_game_parameters.cavalier_king_charles_spaniel.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        // No per-breed hunger / thirst / water data → the Border Collie's paid-breed rhythm.
        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
        $care = ckData('proposed_game_parameters.cavalier_king_charles_spaniel.care_rates.value');
        expect([$config->hunger_decay_rate, $config->thirst_decay_rate, $config->poops_per_day, $config->water_times_per_day, $config->water_min_gap_minutes])
            ->toBe([(float) $care['hunger_decay_per_hour'], (float) $care['thirst_decay_per_hour'], $care['poops_per_day'], $care['water_per_day'], $care['water_min_gap_minutes']]);
    });

    it('accepts a Cavalier pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ckPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('cavalier_king_charles_spaniel');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'alsatian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_05_120000_add_cavalier_king_charles_spaniel_breed.php');
        // down() refuses while a Cavalier exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'cavalier_king_charles_spaniel']))->toThrow(QueryException::class);
        // The German Shepherd (previous migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'german_shepherd']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'cavalier_king_charles_spaniel']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::CavalierKingCharlesSpaniel);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'cavalier-king-charles-spaniel')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cavalier-king-charles-spaniel')->count());

        BreedConfig::where('breed_slug', 'cavalier-king-charles-spaniel')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'cavalier-king-charles-spaniel')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'cavalier-king-charles-spaniel')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'cavalier-king-charles-spaniel');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(['mutt', 'border-collie', 'labrador-retriever', 'golden-retriever', 'french-bulldog', 'german-shepherd-dog', 'beagle', 'poodle-standard', 'dachshund', 'australian-shepherd', 'havanese', 'west-highland-white-terrier'])
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER)->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cavalier-king-charles-spaniel')->where('decision', BreedStageParamsSeeder::CONFIRMED_R10)->all())->toBe([]);
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.cavalier_king_charles_spaniel';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(ckRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(ckData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(ckRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ckData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(ckRow('puppy', 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(ckData("{$pgp}.arrival_age_months.value.puppy"))
            // Senior = 0.75 × 9.99 y (S71, VetCompass) = 89.91 → 90.
            ->and(ckRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(ckData('cavalier_king_charles_spaniel.lifespan.senior_from.months'))
            ->and(ckRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * ckData('cavalier_king_charles_spaniel.lifespan.median_uk.value') * 12))
            ->and(ckRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(ckRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ckData("{$pgp}.exercise_minutes_adult.value"))
            ->and(ckRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(ckRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            // 75 % of 60 = 45 exactly.
            ->and(ckRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(ckData("{$pgp}.exercise_minutes_senior.value"))
            ->and(ckRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            // Puppy rule 10 min × age, capped at the adult minutes.
            ->and(ckRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(ckData("{$pgp}.walk_minutes_puppy_cap.value"))->toBe(60)
            ->and(ckRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(ckData("{$pgp}.learning_multiplier.value"))
            ->and(ckRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        // Every Cavalier decision row cites the data.json entry that records it.
        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_CAVALIER) {
                $decided++;
                expect($row['breed_slug'])->toBe('cavalier-king-charles-spaniel')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.cavalier_king_charles_spaniel.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) ckData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // No open "awaiting David" note is left in the Cavalier research.
        expect(json_encode([ckData('cavalier_king_charles_spaniel'), ckData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('awaiting David')->not->toContain('PROPOSAL');
    });

    it('takes the breed-level numbers from the sourced cavalier_king_charles_spaniel entries', function () {
        $fci = ckData('cavalier_king_charles_spaniel.adult_weight.fci.value');

        // FCI gives one range for both sexes.
        expect(ckRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual($fci)
            ->and(ckRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe(ckData('cavalier_king_charles_spaniel.adult_weight.fci.source_id'))
            ->and(ckRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([9, 12])
            ->and(ckData('cavalier_king_charles_spaniel.growth.adult_weight_reached.value'))->toStartWith('9–12 months')
            ->and(ckRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(ckData('cavalier_king_charles_spaniel.trainability.coren_rank.value'))
            ->and(ckRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(44)
            ->and(ckRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(ckData('cavalier_king_charles_spaniel.lifespan.median_uk.value'))
            ->and(ckRow('all', 0, StageParamKey::LifespanYears)['source_id'])->toBe('S71');

        // Like the other pedigree breeds: no individual variation row (factor 1.0).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cavalier-king-charles-spaniel')
            ->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // The only open proposal is the general teething chewing chance (as for every dog).
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cavalier-king-charles-spaniel')->where('verified', false)->pluck('key')->all())
            ->toBe([StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('cavalier-king-charles-spaniel'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = ckPet($arrival);
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
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months, between the French Bulldog and the German Shepherd', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('cavalier-king-charles-spaniel', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('cavalier-king-charles-spaniel', 90))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('german-shepherd-dog', 92))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('french-bulldog', 88))->toBe(LifeStage::Senior)
            ->and($service->stageForAge('mutt', 93))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('golden-retriever', 118))->toBe(LifeStage::Adult);
    });

    it('learns at the mixed-breed speed (1.0) with no individual factor', function () {
        $training = app(TrainingService::class);
        $cavalier = ckPet(36);
        $date = $cavalier->localDate(now());

        expect($training->learningFactor($cavalier))->toBe(1.0)
            ->and($training->gainPerSuccess($cavalier, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'cavalier_king_charles_spaniel', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ckPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ckLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::CavalierKingCharlesSpaniel)
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
        foreach (['border_collie', 'cavalier_king_charles_spaniel'] as $breed) {
            $pin = ckPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(ckLogin($pin)->assertOk()->json('pet.id'));
        }

        expect($pets['cavalier_king_charles_spaniel']->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($pets['cavalier_king_charles_spaniel']->plan)->toBe(PetPlan::Challenge)
            ->and($pets['cavalier_king_charles_spaniel']->arrival_age_months)->toBe(90)
            ->and($pets['cavalier_king_charles_spaniel']->origin)->toBe(PetOrigin::Adopted)
            // DNA v2 from the Cavalier appearance data.
            ->and($pets['cavalier_king_charles_spaniel']->pet_dna['breed'])->toBe('cavalier_king_charles_spaniel')
            ->and((int) $pets['cavalier_king_charles_spaniel']->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = ckPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL)
            ->and($entitlement->videoStatesFor($pet))->toBe(array_values(array_filter(
                MediaEntitlementService::statesOfTier(MediaEntitlementService::TIER_FULL),
                fn ($state) => $entitlement->behaviourApplies($pet, $state),
            )));
    });
});

describe('GET /api/breeds', function () {
    it('lists the Cavalier after the German Shepherd with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie', 'labrador_retriever', 'golden_retriever', 'french_bulldog', 'german_shepherd', 'cavalier_king_charles_spaniel', 'beagle', 'standard_poodle', 'dachshund', 'australian_shepherd', 'havanese', 'west_highland_white_terrier'])
            ->and($breeds[6])->toBe([
                'breed' => 'cavalier_king_charles_spaniel', 'slug' => 'cavalier-king-charles-spaniel', 'species' => 'dog', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.cavalier_king_charles_spaniel',
                'search_keywords' => BreedConfigsSeeder::configs()[6]['search_keywords'], 'sort_order' => 60,
                'suitability' => [
                    'suits' => ['apartment', 'family_pet', 'children'],
                    'consider' => ['sheds', 'frequent_grooming', 'heart_and_spine'],
                ],
            ]);
    });
});

describe('AI appearance (runbook rules 2026-10-10: visible muzzle, standard colours)', function () {
    it('samples Cavalier DNA inside the standard (Blenheim / tricolour / ruby / black and tan, never chocolate, visible muzzle)', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('cavalier_king_charles_spaniel')
            ->and(PetDnaService::hasAppearance('cavalier_king_charles_spaniel'))->toBeTrue()
            ->and(config('breed_appearance.cavalier_king_charles_spaniel.verified'))->toBeFalse();

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $traits = $dna->sample('cavalier_king_charles_spaniel', $seed)['traits'];
            $colours[$traits['coat_color']] = true;
            $patterns[$traits['coat_pattern']] = true;
            if ($traits['coat_pattern'] === 'solid, without white') {
                expect($traits['coat_color'])->toBe('ruby (whole rich red)');
            }
            if ($traits['coat_pattern'] === 'chestnut patches well broken up on a white ground') {
                expect($traits['coat_color'])->toBe('Blenheim (rich chestnut and pearly white)');
            }

            $prompt = $prompts->imagePrompt('cavalier_king_charles_spaniel', $traits);
            expect($prompt)->toContain('Cavalier King Charles Spaniel')
                ->toContain('feather')
                ->toContain('visible, well-tapered muzzle')
                ->toContain('not protruding')
                ->not->toContain('chocolate')
                ->not->toContain('flat face')
                ->not->toContain('merle')
                ->not->toContain('cat')
                ->not->toContain('hypoallergenic');
        }
        expect(array_keys($colours))->toEqualCanonicalizing(['Blenheim (rich chestnut and pearly white)', 'tricolour (black, white and tan)', 'ruby (whole rich red)', 'black and tan'])
            ->and(count($patterns))->toBe(4);
    });

    it('draws the register portrait Blenheim with a visible muzzle', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::CavalierKingCharlesSpaniel);

        expect($traits['coat_color'])->toBe('Blenheim (rich chestnut and pearly white)')
            ->and($traits['coat_pattern'])->toBe('chestnut patches well broken up on a white ground')
            ->and($portrait->prompt(BreedType::CavalierKingCharlesSpaniel))->toContain('Cavalier King Charles Spaniel')->toContain('visible, well-tapered muzzle')->not->toContain('chocolate')
            ->and(BreedPortraitService::relativeFile(BreedType::CavalierKingCharlesSpaniel))->toBe('dog/cavalier-king-charles-spaniel.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::CavalierKingCharlesSpaniel);
        expect($v1['prompt_anchor'])->toContain('Cavalier King Charles Spaniel')->toContain('muzzle')->toContain('not protruding')
            ->and($v1['visual_traits']['color_scheme'])->toBeIn(['Blenheim (rich chestnut on pearly white)', 'tricolour (black and white with tan)', 'ruby (whole rich red)']);

        $pet = ckPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Cavalier King Charles Spaniel')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app (runbook §3)', function () {
    it('keeps the percentages in the research only and records David\'s marketing decision', function () {
        $app = json_encode([
            config('breed_appearance.cavalier_king_charles_spaniel'),
            config('breed_suitability.breeds.cavalier_king_charles_spaniel'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'cavalier-king-charles-spaniel')->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['30.9', '541', '11.0 %', 'odds ratio', '10.5 kg'] as $statistic) {
            expect((string) $app)->not->toContain($statistic);
        }
        expect(ckData('cavalier_king_charles_spaniel.health._note'))->toContain('never shown in the app')
            ->and(ckData('proposed_game_parameters.cavalier_king_charles_spaniel.marketing.decision'))->toContain('in marketing YES');
    });
});
