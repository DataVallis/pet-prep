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
| M5-R10-144 — Bearded Collie (appended after the Bearded Collie: AKC 2024 No. 140)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `bearded_collie` (S1076–S1083, S123) and the runbook's standing rules
| (confirmed by David 2026-10-10) in `proposed_game_parameters.bearded_collie` (exercise 60 min — RKC "Up to 1 hour per
| day" —, senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 125 (0.75 × McMillan 2024 median 13.9 y, S123),
| arrival 2 / 9 / 36 / 125, learning × 1.2 (Coren rank 34, Above average), paid breed, Border Collie care rates, brushing
| every day (RKC "Every day", M5-R13), puppy socialisation with the PetMD quote (S1081, M5-R17); no tooth / ear / nail /
| bath / groomer task (no breed frequency), no meal gap, no heat walk ban. No welfare chip (Breed Watch Category 1), in
| marketing; suits active_family, family_pet, children, large_home; consider needs_mental_stimulation, may_herd_children,
| needs_company, frequent_grooming, sheds; slate grey with white markings portrait (six standard colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function bdclData(string $path): mixed
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

/** @return array<string, mixed> the Bearded Collie seeder row of (stage, from, key) */
function bdclRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'bearded-collie'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("bearded-collie.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bdclPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->beardedCollie()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bdclPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function bdclLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::BeardedCollie;
        $config = BreedConfig::where('breed_slug', 'bearded-collie')->sole();

        expect($breed->value)->toBe('bearded_collie')
            ->and($breed->slug())->toBe('bearded-collie')
            ->and(BreedType::fromSlug('bearded-collie'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1350, 'breeds.bearded_collie'])
            ->and($config->search_keywords)->toBe(['bearded collie', 'beardie', 'highland collie', 'bradati koli', 'bradati collie'])
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) bdclData('proposed_game_parameters.bearded_collie.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Bearded Collie pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bdclPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('bearded_collie');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'bearded_collier']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_15_120000_add_bearded_collie_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'bearded_collie']))->toThrow(QueryException::class);
        // The Smooth Fox Terrier (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'smooth_fox_terrier']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'bearded_collie']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BeardedCollie)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'bearded-collie')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie')->count());

        BreedConfig::where('breed_slug', 'bearded-collie')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'bearded-collie')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'bearded-collie')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'bearded-collie');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'bearded-collie'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BEARDED_COLLIE)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_BEARDED_COLLIE');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.bearded_collie';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(bdclRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdclData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(bdclRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(bdclData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(bdclRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(bdclData('bearded_collie.lifespan.senior_from.months'))
            ->and(bdclRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * bdclRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(bdclData('bearded_collie.lifespan.senior_from.derived_from'))->toBe(['S11', 'S123'])
            ->and(bdclData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(bdclRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', bdclData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(bdclRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BEARDED_COLLIE)
            ->and(bdclRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(125);

        expect(bdclRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdclData("{$pgp}.exercise_minutes_adult.value"))
            ->and(bdclRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bdclRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(bdclData('bearded_collie.exercise.adult.quote'))
            ->and(bdclRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1078')
            ->and(bdclRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(bdclRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(bdclData("{$pgp}.exercise_minutes_senior.value"))
            ->and(bdclRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(bdclRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(bdclRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.2);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_BEARDED_COLLIE) {
                $decided++;
                expect($row['breed_slug'])->toBe('bearded-collie')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.bearded_collie.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) bdclData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing every day (RKC "Every day", M5-R13), puppy socialisation (PetMD,
        // M5-R17). No tooth / ear / nail / bath task (no breed frequency: WebMD's daily tooth brushing is a sentence about dogs in
        // general, ears / nails "regularly", bath "routine"), no groomer task (no source), no meal gap.
        expect(json_encode([bdclData('bearded_collie'), bdclData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(bdclData("{$pgp}.grooming.value"))->toBe(7)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('bearded_collie')
            ->and(bdclData('bearded_collie.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['bearded_collie'])->toBe(['S1081', bdclData("{$pgp}.socialisation.quote")])
            ->and(bdclData("{$pgp}.socialisation.quote"))->toBe(bdclData('bearded_collie.behaviour.socialisation.quote'))
            ->and(BreedStageParamsSeeder::DOG_CARE_TASKS)->not->toHaveKey('bearded_collie')
            ->and(bdclData('bearded_collie.suitability.webmd_teeth.quote'))->toStartWith('There’s a lot that goes into caring for a dog')
            ->and(bdclData('bearded_collie.suitability.webmd_nails.quote'))->toContain('on a regular basis')
            ->and(bdclData('bearded_collie.suitability.petmd_ears.quote'))->toContain('regularly')
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('bearded_collie');
    });

    it('takes the breed-level numbers from the sourced bearded_collie entries', function () {
        expect(bdclRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([20.0, 25.0])
            ->and(bdclData('bearded_collie.adult_weight.pdsa.value'))->toEqual(['male' => [20, 25], 'female' => [20, 25]])
            ->and(bdclRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(bdclData('bearded_collie.adult_weight.pdsa.quote'))
            ->and(bdclRow('all', 0, StageParamKey::AdultWeightKg)['source_id'])->toBe('S1079')
            ->and(bdclRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(bdclRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(bdclData('bearded_collie.trainability.coren_rank.value'))
            ->and(bdclRow('all', 0, StageParamKey::CorenRank)['value'])->toBe(34)
            ->and(bdclRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('bearded_collie.lifespan.median_uk')
            ->and(bdclRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(13.9)
            ->and(bdclRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(bdclData('bearded_collie.lifespan.median_uk.quote'));

        $grooming = bdclRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(7)
            ->and($grooming['source_id'])->toBe('S1078')
            ->and($grooming['quote'])->toBe(bdclData('bearded_collie.suitability.rkc_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(bdclRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1081')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->all())->toBe([])
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
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('bearded-collie'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = bdclPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'young 12 months (capped at adult)' => [12, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 124 months' => [124, LifeStage::Adult, 60, 6000],
        'senior 125 months' => [125, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 125 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('bearded-collie', 124))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('bearded-collie', 125))->toBe(LifeStage::Senior);
    });

    it('learns 1.2× faster than the mixed breed (Coren rank 34, Above average) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = bdclPet(36);
        $date = $dog->localDate(now());

        // learningFactor() is the individual factor (none for a pedigree breed); the breed multiplier is in the gain.
        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.2);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'bearded_collie', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bdclPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bdclLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BeardedCollie)
            ->and($pet->species)->toBe(Species::Dog)
            ->and($pet->arrival_age_months)->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('starts the challenge exactly like a Border Collie challenge, arriving as a senior at 125 months', function () {
        $parent = User::factory()->parent()->create();
        $pets = [];
        foreach (['border_collie', 'bearded_collie'] as $breed) {
            $pin = bdclPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(bdclLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['bearded_collie'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(125)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('bearded_collie')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = bdclPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Bearded Collie after the Smooth Fox Terrier with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'bearded_collie', 'smooth_fox_terrier');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'bearded_collie', 'slug' => 'bearded-collie', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.bearded_collie',
            'search_keywords' => seededBreedConfig('bearded_collie')['search_keywords'], 'sort_order' => 1350,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children', 'large_home'],
                'consider' => ['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'frequent_grooming', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 271 S1076)', function () {
    it('samples DNA only in the colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('bearded_collie')
            ->and(PetDnaService::hasAppearance('bearded_collie'))->toBeTrue()
            ->and(config('breed_appearance.bearded_collie.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.bearded_collie.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('bearded_collie', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('bearded_collie', $traits);
            expect($prompt)->toContain('Bearded Collie')->toContain('drooping ears')
                ->not->toMatch('/\b(merle|dapple|brindle|cropped|docked|clipped|flat-faced|erect ears)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.bearded_collie');
        expect($standard)->toBe(['slate grey with white markings', 'black with white markings', 'blue with white markings', 'brown with white markings', 'reddish-fawn with white markings', 'sandy with white markings'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['grey-white', 'black-white', 'blue-white', 'brown-white', 'fawn-white', 'sandy-white'])
            ->and(bdclData('bearded_collie.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait slate grey with white markings (the highest-weighted standard colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::BeardedCollie);

        expect($traits['coat_color'])->toBe('slate grey with white markings')
            ->and($portrait->prompt(BreedType::BeardedCollie))->toContain('Bearded Collie')->toContain('slate grey with white markings')
            ->and(BreedPortraitService::relativeFile(BreedType::BeardedCollie))->toBe('dog/bearded-collie.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::BeardedCollie);
        expect($v1['prompt_anchor'])->toContain('Bearded Collie')->toContain('slate grey with white markings')->toContain('drooping ears')
            ->not->toMatch('/\b(merle|dapple|cropped|docked|clipped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('slate grey with white markings');

        $pet = bdclPet(125, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Bearded Collie')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.bearded_collie'),
            config('breed_suitability.breeds.bearded_collie'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::BeardedCollie);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(bdclData('proposed_game_parameters.bearded_collie.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'bearded-collie')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(108);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families, families, children and large homes; consider stimulation, herding, company, daily grooming and shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::BeardedCollie);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children', 'large_home'])
            ->and($tags['consider'])->toBe(['needs_mental_stimulation', 'may_herd_children', 'needs_company', 'frequent_grooming', 'sheds'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('long_daily_exercise')->not->toContain('chews_when_bored');

        expect(bdclData('bearded_collie.suitability.rkc_size_of_home.quote'))->toBe('Size of home: Large house')
            ->and(bdclData('bearded_collie.behaviour.herding_children.quote'))->toContain('herd at home')
            ->and(bdclData('bearded_collie.behaviour.time_alone.quote'))->toContain('left alone')
            ->and(bdclData('bearded_collie.behaviour.children.quote'))->toContain('gentle around children')
            ->and(bdclData('bearded_collie.suitability.webmd_hypoallergenic.quote'))->toContain('not a hypoallergenic breed');
    });
});
