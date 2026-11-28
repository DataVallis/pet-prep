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
| M5-R10-146 — Barbet (appended after the Saluki: AKC 2024 No. 142)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `barbet` (S1094–S1099) and the runbook's standing rules (confirmed by
| David 2026-10-10) in `proposed_game_parameters.barbet` (exercise 60 min — PetMD "at least an hour", the RKC page was
| not reachable —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 90 (0.75 × WebMD lower bound "over 10
| years"), arrival 2 / 9 / 36 / 90, learning × 1.0 (not ranked by Coren), paid breed, Border Collie care rates, brushing
| 3× a week (PetMD / breed club "two to three times a week", M5-R13), tooth brushing every day (WebMD "every day or two",
| M5-R15), puppy socialisation with the PetMD quote (S1095, M5-R17); no ear / nail / bath / groomer task (no frequency), no
| meal gap, no heat walk ban. No welfare chip, in marketing; suits active_family, children, other_pets, low_shedding;
| consider frequent_grooming, needs_company, heat_sensitive; solid black portrait (eight FCI colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function barbData(string $path): mixed
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

/** @return array<string, mixed> the Barbet seeder row of (stage, from, key) */
function barbRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'barbet'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("barbet.{$stage}.{$from}.{$key->value}");

    return $row;
}

function barbPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->barbet()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function barbPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function barbLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::Barbet;
        $config = BreedConfig::where('breed_slug', 'barbet')->sole();

        expect($breed->value)->toBe('barbet')
            ->and($breed->slug())->toBe('barbet')
            ->and(BreedType::fromSlug('barbet'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1370, 'breeds.barbet'])
            ->and($config->search_keywords)->toBe(['barbet', 'french water dog'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) barbData('proposed_game_parameters.barbet.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Barbet pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = barbPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('barbet');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'barbets']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_17_120000_add_barbet_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'barbet']))->toThrow(QueryException::class);
        // The Saluki (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'saluki']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'barbet']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Barbet)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'barbet')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet')->count());

        BreedConfig::where('breed_slug', 'barbet')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'barbet')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'barbet')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'barbet');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'barbet'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BARBET)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BARBET');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.barbet';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(barbRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(barbData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(barbRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(barbData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(barbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(barbData('barbet.lifespan.senior_from.months'))
            ->and(barbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * barbRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(barbData('barbet.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1096'])
            ->and(barbData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(barbRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', barbData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(barbRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BARBET)
            ->and(barbRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(90);

        expect(barbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(barbData("{$pgp}.exercise_minutes_adult.value"))
            ->and(barbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(barbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(barbData('barbet.exercise.adult.quote'))
            ->and(barbRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1095')
            ->and(barbRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(barbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(barbData("{$pgp}.exercise_minutes_senior.value"))
            ->and(barbRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(barbRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(barbRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BARBET) {
                $decided++;
                expect($row['breed_slug'])->toBe('barbet')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.barbet.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) barbData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing 3× a week (PetMD / breed club "two to three times a week",
        // M5-R13), tooth brushing every day (WebMD "every day or two", M5-R15), puppy socialisation (PetMD, M5-R17). No ear /
        // nail / bath / groomer task (no frequency), no meal gap (no waiting quote).
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['barbet'];
        expect(json_encode([barbData('barbet'), barbData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(barbData("{$pgp}.grooming.value"))->toBe(3)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('barbet')
            ->and(barbData('barbet.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['barbet'])->toBe(['S1095', barbData("{$pgp}.socialisation.quote")])
            ->and(barbData("{$pgp}.socialisation.quote"))->toBe(barbData('barbet.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1096', barbData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(barbData("{$pgp}.tooth_brushing.quote"))->toBe(barbData('barbet.suitability.webmd_teeth.quote'))
            ->and(barbData('barbet.suitability.webmd_nails.quote'))->toContain('regularly')
            ->and(barbData('barbet.suitability.petmd_ears.quote'))->toContain('regular cleaning')
            ->and(barbData('barbet.suitability.petmd_bath.quote'))->toContain('Regular bathing')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('barbet');
    });

    it('takes the breed-level numbers from the sourced barbet entries', function () {
        expect(barbRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([13.5, 27.0])
            ->and(barbData('barbet.adult_weight.webmd.value'))->toEqual(['male' => [18, 27], 'female' => [13.5, 23]])
            ->and(barbRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(barbData('barbet.adult_weight.webmd.quote'))
            ->and(barbRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe('S1096')
            ->and(barbRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(barbRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(barbData('barbet.trainability.coren_rank.value'))
            ->and(barbRow('all', 0, StageParamKey::CorenRank)['value'])->toBeNull()
            ->and(barbData('barbet.trainability.coren_tier.value'))->toBeNull()
            ->and(barbRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('barbet.lifespan.webmd')
            ->and(barbRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(10.0)
            ->and(barbRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(barbData('barbet.lifespan.webmd.quote'));

        $grooming = barbRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(3)
            ->and($grooming['source_id'])->toBe('S1095')
            ->and($grooming['quote'])->toBe(barbData('barbet.suitability.petmd_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(barbRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1095')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::ToothBrushingFrequency->value => [7, 7],
        ])
            ->and($mine->where('key', StageParamKey::NoWalkInHeat->value)->all())->toBe([])
            ->and($mine->where('key', StageParamKey::TrainingIndividualVariation->value)->all())->toBe([]);

        // Open proposals: only the general teething chewing chance (as for every dog).
        expect($mine->where('verified', false)->map(fn (array $r) => $r['stage'].'.'.$r['key'])->values()->all())
            ->toBe(['puppy.'.StageParamKey::ChewingChancePerDay->value]);
    });

    it('shares the general dog rows with the Border Collie (only the breed profile differs)', function () {
        $profileKeys = [StageParamKey::StartsAtMonths, StageParamKey::ArrivalAgeMonths, StageParamKey::ExerciseMinutesPerDay,
            StageParamKey::ExerciseMinutesPerAgeMonth, StageParamKey::AdultWeightKg, StageParamKey::GrowthEndMonths,
            StageParamKey::CorenRank, StageParamKey::TrainingLearningMultiplier, StageParamKey::LifespanYears,
            StageParamKey::GroomingSessionsPerWeek, StageParamKey::SocialisationTasksPerWeek,
            StageParamKey::ToothBrushingFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('barbet'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = barbPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'puppy 6 months (reaches the adult 60)' => [6, LifeStage::Puppy, 60, 6000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 89 months' => [89, LifeStage::Adult, 60, 6000],
        'senior 90 months' => [90, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 90 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('barbet', 89))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('barbet', 90))->toBe(LifeStage::Senior);
    });

    it('learns at the mixed breed\'s speed (not ranked by Coren) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = barbPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'barbet', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = barbPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(barbLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Barbet)
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
        foreach (['border_collie', 'barbet'] as $breed) {
            $pin = barbPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(barbLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['barbet'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(90)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('barbet')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = barbPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Barbet after the Saluki with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'barbet', 'saluki');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'barbet', 'slug' => 'barbet', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.barbet',
            'search_keywords' => seededBreedConfig('barbet')['search_keywords'], 'sort_order' => 1370,
            'suitability' => [
                'suits' => ['active_family', 'children', 'other_pets', 'low_shedding'],
                'consider' => ['frequent_grooming', 'needs_company', 'heat_sensitive'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 105 S1094, AKC S1097)', function () {
    it('samples DNA only in the colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('barbet')
            ->and(PetDnaService::hasAppearance('barbet'))->toBeTrue()
            ->and(config('breed_appearance.barbet.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.barbet.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('barbet', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('barbet', $traits);
            expect($prompt)->toContain('Barbet')->toContain('curly')
                ->not->toMatch('/\b(merle|dapple|brindle|tan|cropped|docked|clipped|shaved to the skin|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.barbet');
        expect($standard)->toBe(['solid black with a black nose', 'solid brown with a brown nose', 'solid grey', 'fawn (red-fawn)', 'black with white markings (pied)', 'brown with white markings (pied)', 'pale fawn', 'white'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black', 'brown', 'grey', 'fawn', 'black-white', 'brown-white', 'pale-fawn', 'white'])
            ->and(count(barbData('barbet.appearance.colours.value')))->toBe(count($standard));
    });

    it('draws the register portrait solid black (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::Barbet);

        expect($traits['coat_color'])->toBe('solid black with a black nose')
            ->and($portrait->prompt(BreedType::Barbet))->toContain('Barbet')->toContain('solid black')
            ->and(BreedPortraitService::relativeFile(BreedType::Barbet))->toBe('dog/barbet.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::Barbet);
        expect($v1['prompt_anchor'])->toContain('Barbet')->toContain('solid black')->toContain('curly')
            ->not->toMatch('/\b(merle|dapple|brindle|cropped|docked|clipped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('solid black');

        $pet = barbPet(90, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Barbet')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.barbet'),
            config('breed_suitability.breeds.barbet'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::Barbet);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(barbData('proposed_game_parameters.barbet.marketing.value'))->toContain('in marketing: yes')
            // The PetMD span (12–15 years → 108) is an alternative only; the WebMD lower bound decides the senior boundary.
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'barbet')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families, children and other pets, sheds little; consider grooming, company and heat', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::Barbet);
        expect($tags['suits'])->toBe(['active_family', 'children', 'other_pets', 'low_shedding'])
            ->and($tags['consider'])->toBe(['frequent_grooming', 'needs_company', 'heat_sensitive'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('large_home')
            ->and($tags['consider'])->not->toContain('long_daily_exercise')->not->toContain('chases_small_pets')->not->toContain('sheds');

        expect(barbData('barbet.behaviour.family.quote'))->toContain('active families')
            ->and(barbData('barbet.behaviour.children.quote'))->toContain('gets along well with children')
            ->and(barbData('barbet.behaviour.time_alone.quote'))->toContain('left alone')
            ->and(barbData('barbet.exercise.heat.quote'))->toContain('overheat')
            ->and(barbData('barbet.suitability.petmd_shedding.quote'))->not->toContain('hypoallergenic');
    });
});
