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
| M5-R10-133 — Finnish Lapphund (appended after the Neapolitan Mastiff: AKC 2024 No. 129)
|--------------------------------------------------------------------------
| Sourced data: docs/research/dog-data/data.json `finnish_lapphund` (S1002–S1008) and the runbook's standing rules (confirmed
| by David 2026-10-10) in `proposed_game_parameters.finnish_lapphund` (exercise 60 min — RKC "Up to 1 hour per day" —,
| senior 45 min, puppy 10 min × age capped at 60, stages 9 / 36 / 108 (0.75 × RKC lower bound "Over 12 years"), arrival
| 2 / 9 / 36 / 108, learning × 1.0 (unranked), paid breed, Border Collie care rates, brushing once a week (RKC "Once a week",
| M5-R13), puppy socialisation with the PetMD quote (S1006, M5-R17), tooth brushing every day + nail trim every 4 weeks (WebMD
| S1007) and a bath reminder every 4 weeks (PetMD S1006, M5-R15 / R19 / R20); no ear / groomer task (no frequency), no meal
| gap, no heat walk ban (not flat-faced). No welfare chip, in marketing; suits active_family, family_pet, children; consider
| needs_company, sheds; black and tan portrait (one of six colours).
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the dog data.json. */
function flData(string $path): mixed
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

/** @return array<string, mixed> the Finnish Lapphund seeder row of (stage, from, key) */
function flRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(BreedStageParamsSeeder::rows())->first(fn (array $r) => $r['breed_slug'] === 'finnish-lapphund'
        && $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("finnish-lapphund.{$stage}.{$from}.{$key->value}");

    return $row;
}

function flPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->finnishLapphund()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function flPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function flLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => []]);
}
describe('breed, config and database', function () {
    it('is a paid dog breed with its own slug and a seeded breed config', function () {
        $breed = BreedType::FinnishLapphund;
        $config = BreedConfig::where('breed_slug', 'finnish-lapphund')->sole();

        expect($breed->value)->toBe('finnish_lapphund')
            ->and($breed->slug())->toBe('finnish-lapphund')
            ->and(BreedType::fromSlug('finnish-lapphund'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Dog)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Dog, true, 1240, 'breeds.finnish_lapphund'])
            ->and($config->search_keywords)->toContain('finnish lapphund')->toContain('lapinkoira')->toContain('finski laponski pes')
            // Legacy fallback = the adult step goal (60 min × 100 steps).
            ->and($config->daily_steps_required)->toBe((int) flData('proposed_game_parameters.finnish_lapphund.step_goal_adult.value'))
            ->and($config->daily_steps_required)->toBe(6000);

        $collie = BreedConfig::where('breed_slug', 'border-collie')->sole();
        foreach (['hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($collie->{$field}, $field);
        }
    });

    it('accepts a Finnish Lapphund pet only as a dog (breed / species CHECKs) and the migration is reversible', function () {
        $pet = flPet(36);
        expect($pet->species)->toBe(Species::Dog)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('finnish_lapphund');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'cat']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'finnish_lapponian_dog']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2027_05_02_120000_add_finnish_lapphund_breed.php');
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'border_collie']);
        $migration->down();
        expect($refused(['breed_type' => 'finnish_lapphund']))->toThrow(QueryException::class);
        // The Neapolitan Mastiff (previous dog migration) stays allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'neapolitan_mastiff']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'finnish_lapphund']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::FinnishLapphund)
            ->and($refused(['breed_type' => 'bengal']))->toThrow(QueryException::class);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'finnish-lapphund')->count();
        expect($count)->toBe(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund')->count());

        BreedConfig::where('breed_slug', 'finnish-lapphund')->sole()->update(['daily_steps_required' => 5000]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'finnish-lapphund')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'finnish-lapphund')->value('daily_steps_required'))->toBe(5000);
    });

    it('leaves every other breed\'s seeded rows untouched', function () {
        $others = collect(BreedStageParamsSeeder::rows())->where('breed_slug', '!=', 'finnish-lapphund');
        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund');

        expect($others->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Dog, 'finnish-lapphund'))
            ->and($others->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_FINNISH_LAPPHUND)->all())->toBe([]);
        $constants = collect((new ReflectionClass(BreedStageParamsSeeder::class))->getConstants())
            ->filter(fn ($value, string $name) => is_string($value) && (str_starts_with($name, 'CONFIRMED_R10') || str_starts_with($name, 'PROPOSED_R10'))
                && $name !== 'CONFIRMED_R10_FINNISH_LAPPHUND');
        expect($constants->count())->toBeGreaterThan(5);
        foreach ($constants as $name => $value) {
            expect($mine->where('decision', $value)->all())->toBe([], $name);
        }
    });
});

describe('life-stage data against data.json', function () {
    it('takes the stage boundaries, arrival ages, exercise minutes and learning multiplier from the runbook rules confirmed by David 2026-10-10', function () {
        $pgp = 'proposed_game_parameters.finnish_lapphund';

        foreach (['young', 'adult', 'senior'] as $stage) {
            expect(flRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe(flData("{$pgp}.stage_boundaries_months.value.{$stage}"))
                ->and(flRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe(flData("{$pgp}.arrival_age_months.value.{$stage}"));
        }
        expect(flRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(flData('finnish_lapphund.lifespan.senior_from.months'))
            ->and(flRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe((int) round(0.75 * flRow('all', 0, StageParamKey::LifespanYears)['value'] * 12))
            ->and(flData('finnish_lapphund.lifespan.senior_from.derived_from'))->toBe(['S11', 'S1004'])
            ->and(flData("{$pgp}.stage_boundaries_months.alternatives.senior_dogs_trust_7_y"))->toBe(84)
            ->and(flRow('senior', 0, StageParamKey::StartsAtMonths)['source_id'])->toBe(implode(',', flData("{$pgp}.stage_boundaries_months.derived_from")))
            ->and(flRow('senior', 0, StageParamKey::StartsAtMonths)['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_FINNISH_LAPPHUND)
            ->and(flRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(108);

        expect(flRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(flData("{$pgp}.exercise_minutes_adult.value"))
            ->and(flRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(flRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['quote'])->toBe(flData('finnish_lapphund.exercise.adult.quote'))
            ->and(flRow('adult', 0, StageParamKey::ExerciseMinutesPerDay)['source_id'])->toBe('S1004')
            ->and(flRow('young', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(60)
            ->and(flRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(flData("{$pgp}.exercise_minutes_senior.value"))
            ->and(flRow('senior', 0, StageParamKey::ExerciseMinutesPerDay)['value'])->toBe(45)
            ->and(flRow('puppy', 0, StageParamKey::ExerciseMinutesPerAgeMonth)['value'])->toBe(10)
            ->and(flRow('all', 0, StageParamKey::TrainingLearningMultiplier)['value'])->toBe(1.0);

        $decided = 0;
        foreach (BreedStageParamsSeeder::rows() as $row) {
            if ($row['decision'] === BreedStageParamsSeeder::CONFIRMED_R10_FINNISH_LAPPHUND) {
                $decided++;
                expect($row['breed_slug'])->toBe('finnish-lapphund')
                    ->and($row['ref'])->toStartWith('proposed_game_parameters.finnish_lapphund.')
                    ->and($row['verified'])->toBeTrue()
                    ->and((string) flData($row['ref'])['decision'])->toStartWith('potrdil David 2026-10-10 (pravilo runbooka)');
            }
        }
        expect($decided)->toBeGreaterThan(0);

        // Every sourced need with a mechanic is built: brushing once a week (RKC, M5-R13), puppy socialisation (PetMD, M5-R17),
        // tooth brushing every day + nail trim every 4 weeks (WebMD) and a bath reminder every 4 weeks (PetMD). No meal gap.
        $tasks = BreedStageParamsSeeder::DOG_CARE_TASKS['finnish_lapphund'];
        expect(json_encode([flData('finnish_lapphund'), flData($pgp)], JSON_UNESCAPED_UNICODE))->not->toContain('PROPOSAL')->not->toContain('not built"')
            ->and(flData("{$pgp}.grooming.value"))->toBe(1)
            ->and(BreedStageParamsSeeder::MEAL_EXERCISE_GAP)->not->toHaveKey('finnish_lapphund')
            ->and(flData('finnish_lapphund.exercise.after_meals.quote'))->toBeNull()
            ->and(BreedStageParamsSeeder::DOG_SOCIALISATION['finnish_lapphund'])->toBe(['S1006', flData("{$pgp}.socialisation.quote")])
            ->and(flData("{$pgp}.socialisation.quote"))->toBe(flData('finnish_lapphund.behaviour.socialisation.quote'))
            ->and(array_keys($tasks))->toBe(['tooth_brushing', 'nail_trim', 'bath'])
            ->and(array_slice($tasks['tooth_brushing'], 0, 5))->toBe([7, 'day', 'S1007', flData("{$pgp}.tooth_brushing.quote"), "{$pgp}.tooth_brushing"])
            ->and(flData("{$pgp}.tooth_brushing.quote"))->toBe(flData('finnish_lapphund.suitability.webmd_teeth.quote'))
            ->and(array_slice($tasks['nail_trim'], 0, 5))->toBe([1, 'month', 'S1007', flData("{$pgp}.nail_trimming.quote"), "{$pgp}.nail_trimming"])
            ->and(flData("{$pgp}.nail_trimming.quote"))->toBe(flData('finnish_lapphund.suitability.webmd_nails.quote'))
            ->and(array_slice($tasks['bath'], 0, 5))->toBe([1, 'month', 'S1006', flData("{$pgp}.bathing.quote"), "{$pgp}.bathing"])
            ->and(flData("{$pgp}.bathing.quote"))->toBe(flData('finnish_lapphund.suitability.petmd_bath.quote'))
            ->and(BreedStageParamsSeeder::DOG_NO_WALK_IN_HEAT)->not->toHaveKey('finnish_lapphund');
    });

    it('takes the breed-level numbers from the sourced finnish_lapphund entries', function () {
        expect(flRow('all', 0, StageParamKey::AdultWeightKg)['value'])->toEqual([15, 24])
            ->and(flData('finnish_lapphund.adult_weight.petmd.value'))->toEqual(['male' => [15, 24], 'female' => [15, 24]])
            ->and(flRow('all', 0, StageParamKey::AdultWeightKg)['quote'])->toBe(flData('finnish_lapphund.adult_weight.petmd.quote'))
            ->and(flRow('all', 0, StageParamKey::GrowthEndMonths)['value'])->toBe([12, 15])
            ->and(flRow('all', 0, StageParamKey::LifespanYears)['ref'])->toBe('finnish_lapphund.lifespan.rkc')
            ->and(flRow('all', 0, StageParamKey::LifespanYears)['value'])->toBe(12.0)
            ->and(flRow('all', 0, StageParamKey::LifespanYears)['quote'])->toBe(flData('finnish_lapphund.lifespan.rkc.quote'));

        $grooming = flRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($grooming['value'])->toBe(1)
            ->and($grooming['source_id'])->toBe('S1004')
            ->and($grooming['quote'])->toBe(flData('finnish_lapphund.suitability.rkc_grooming.quote'))
            ->and($grooming['verified'])->toBeTrue()
            ->and($grooming['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R13_DOG_GROOMING)
            ->and(flRow('puppy', 0, StageParamKey::SocialisationTasksPerWeek)['source_id'])->toBe('S1006')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund')->where('key', StageParamKey::MealExerciseGapMinutes->value)->all())->toBe([]);

        $mine = collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund');
        $tasks = $mine->filter(fn (array $r) => str_ends_with($r['key'], '_frequency'));
        expect($tasks->pluck('value', 'key')->sortKeys()->all())->toBe([
            StageParamKey::BathFrequency->value => [1, 28],
            StageParamKey::NailTrimFrequency->value => [1, 28],
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
            StageParamKey::ToothBrushingFrequency, StageParamKey::NailTrimFrequency, StageParamKey::BathFrequency,
        ];
        $general = fn (string $slug) => collect(BreedStageParamsSeeder::rows())
            ->where('breed_slug', $slug)
            ->reject(fn (array $r) => in_array(StageParamKey::from($r['key']), $profileKeys, true))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('finnish-lapphund'))->toBe($general('border-collie'));
    });
});

describe('stage rules', function () {
    it('walks 10 min × age up to 60 minutes = 6,000 steps and 45 minutes = 4,500 steps as senior', function (int $arrival, LifeStage $stage, int $minutes, int $steps) {
        $pet = flPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->exerciseMinutes)->toBe($minutes)
            ->and($rules->stepGoal)->toBe($steps);
    })->with([
        'puppy 2 months (10 min × age)' => [2, LifeStage::Puppy, 20, 2000],
        'puppy 5 months' => [5, LifeStage::Puppy, 50, 5000],
        'young 9 months (capped at adult)' => [9, LifeStage::Young, 60, 6000],
        'adult 36 months' => [36, LifeStage::Adult, 60, 6000],
        'adult 107 months' => [107, LifeStage::Adult, 60, 6000],
        'senior 108 months' => [108, LifeStage::Senior, 45, 4500],
    ]);

    it('becomes senior at 108 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageForAge('finnish-lapphund', 107))->toBe(LifeStage::Adult)
            ->and($service->stageForAge('finnish-lapphund', 108))->toBe(LifeStage::Senior);
    });

    it('learns like the mixed breed (unranked) with no individual factor', function () {
        $training = app(TrainingService::class);
        $dog = flPet(36);
        $date = $dog->localDate(now());

        expect($training->learningFactor($dog))->toBe(1.0)
            ->and($training->gainPerSuccess($dog, $date))->toBe(1.0);
    });
});

describe('generate-pin: paid like the Border Collie', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'finnish_lapphund', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = flPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(flLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::FinnishLapphund)
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
        foreach (['border_collie', 'finnish_lapphund'] as $breed) {
            $pin = flPin($parent, ['breed' => $breed, 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
            $pets[$breed] = Pet::findOrFail(flLogin($pin)->assertOk()->json('pet.id'));
        }

        $dog = $pets['finnish_lapphund'];
        expect($dog->challengeStatus())->toBe($pets['border_collie']->challengeStatus())
            ->and($dog->plan)->toBe(PetPlan::Challenge)
            ->and($dog->arrival_age_months)->toBe(108)
            ->and($dog->origin)->toBe(PetOrigin::Adopted)
            ->and($dog->pet_dna['breed'])->toBe('finnish_lapphund')
            ->and((int) $dog->pet_dna['version'])->toBe(2);
    });

    it('gets the full media set once the challenge is bought', function () {
        $pet = flPet(36, ['plan' => PetPlan::Challenge, 'challenge_paid_source' => ChallengePaidSource::Purchase]);
        $entitlement = app(MediaEntitlementService::class);

        expect($entitlement->tierFor($pet))->toBe(MediaEntitlementService::TIER_FULL);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Finnish Lapphund after the Neapolitan Mastiff with its suitability tags', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        $breeds = getJson('/api/breeds')->assertOk()->json('breeds');

        $i = expectListedAfter($breeds, 'finnish_lapphund', 'neapolitan_mastiff');

        expect(collect($breeds[$i])->except('colours')->all())->toBe([
            'breed' => 'finnish_lapphund', 'slug' => 'finnish-lapphund', 'species' => 'dog', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.finnish_lapphund',
            'search_keywords' => seededBreedConfig('finnish_lapphund')['search_keywords'], 'sort_order' => 1240,
            'suitability' => [
                'suits' => ['active_family', 'family_pet', 'children'],
                'consider' => ['needs_company', 'sheds'],
            ],
        ]);
    });
});

describe('AI appearance (runbook rule: standard colours only — FCI-Standard N° 189 S1003)', function () {
    it('samples DNA only in the six common colours of the standard', function () {
        $dna = app(PetDnaService::class);
        $prompts = app(PetAppearancePrompt::class);
        expect($dna->breeds())->toContain('finnish_lapphund')
            ->and(PetDnaService::hasAppearance('finnish_lapphund'))->toBeTrue()
            ->and(config('breed_appearance.finnish_lapphund.verified'))->toBeFalse();

        $standard = array_map(fn ($o) => is_array($o) ? $o['value'] : $o, config('breed_appearance.finnish_lapphund.traits.coat_color'));
        foreach (range(1, 30) as $seed) {
            $traits = $dna->sample('finnish_lapphund', $seed)['traits'];
            expect($standard)->toContain($traits['coat_color']);

            $prompt = $prompts->imagePrompt('finnish_lapphund', $traits);
            expect($prompt)->toContain('Finnish Lapphund')->toContain('erect')
                ->not->toMatch('/\b(merle|brindle|cropped|docked|white markings|flat-faced)\b/i')
                ->not->toContain(' cat')
                ->not->toContain('hypoallergenic');
        }
        $colours = config('breed_colours.finnish_lapphund');
        expect($standard)->toBe(['black and tan', 'black', 'brown', 'wolf-sable (grey sable)', 'sable', 'cream'])
            ->and(array_column($colours, 'value'))->toBe($standard)
            ->and(array_column($colours, 'key'))->toBe(['black-tan', 'black', 'brown', 'wolf-sable', 'sable', 'cream'])
            ->and(flData('finnish_lapphund.appearance.colours.value'))->toBe($standard);
    });

    it('draws the register portrait black and tan (the highest-weighted colour)', function () {
        $portrait = app(BreedPortraitService::class);
        $traits = $portrait->portraitTraits(BreedType::FinnishLapphund);

        expect($traits['coat_color'])->toBe('black and tan')
            ->and($portrait->prompt(BreedType::FinnishLapphund))->toContain('Finnish Lapphund')->toContain('black and tan')
            ->and(BreedPortraitService::relativeFile(BreedType::FinnishLapphund))->toBe('dog/finnish-lapphund.webp');
    });

    it('has a legacy DNA v1 prompt and stage prompts like every dog', function () {
        $v1 = app(FalAiService::class)->generateInitialPetDna(BreedType::FinnishLapphund);
        expect($v1['prompt_anchor'])->toContain('Finnish Lapphund')->toContain('black and tan')
            ->not->toMatch('/\b(merle|cropped)\b/i')
            ->and($v1['visual_traits']['color_scheme'])->toBe('black and tan');

        $pet = flPet(108, ['origin' => PetOrigin::Adopted]);
        $prompt = app(PetAppearancePrompt::class)->imagePromptForPet($pet->forceFill(['pet_dna' => app(PetDnaService::class)->forNewPet($pet)]), LifeStage::Senior);
        expect($prompt)->toContain('Finnish Lapphund')->toContain(PetAppearancePrompt::ADOPTED_AVOID)->toContain(PetAppearancePrompt::STYLE);
    });
});

describe('no health statistics in the app; in marketing (runbook §1, §3)', function () {
    it('keeps the app config free of statistics, has no welfare chip and stays in marketing', function () {
        $app = json_encode([
            config('breed_appearance.finnish_lapphund'),
            config('breed_suitability.breeds.finnish_lapphund'),
            collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund')->where('key', '!=', StageParamKey::LifespanYears->value)->values()->all(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['odds ratio', 'hypoallergenic', 'times higher', '%)', 'prevalence'] as $forbidden) {
            expect((string) $app)->not->toContain($forbidden);
        }
        $tags = app(BreedSuitability::class)->for(BreedType::FinnishLapphund);
        expect($tags['consider'])->not->toContain('brachycephalic_breathing')->not->toContain('hips_hind_legs')->not->toContain('shorter_lifespan')
            ->and(flData('proposed_game_parameters.finnish_lapphund.marketing.value'))->toContain('in marketing: yes')
            ->and(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'finnish-lapphund')->where('key', StageParamKey::StartsAtMonths->value)->pluck('value')->all())
            ->not->toContain(84);
    });
});

describe('suitability tags (own file, runbook "Own files only")', function () {
    it('suits active families with children; consider company and shedding', function () {
        $tags = app(BreedSuitability::class)->for(BreedType::FinnishLapphund);
        expect($tags['suits'])->toBe(['active_family', 'family_pet', 'children'])
            ->and($tags['consider'])->toBe(['needs_company', 'sheds'])
            ->and($tags['suits'])->not->toContain('small_children')->not->toContain('apartment')->not->toContain('other_pets')->not->toContain('large_home')->not->toContain('low_shedding')
            ->and($tags['consider'])->not->toContain('chases_small_pets')->not->toContain('heat_sensitive')->not->toContain('long_daily_exercise');

        expect(flData('finnish_lapphund.suitability.rkc_size_of_home.quote'))->toBe('Small house')
            ->and(flData('finnish_lapphund.suitability.rkc_shedding.quote'))->toBe('Yes')
            ->and(flData('finnish_lapphund.behaviour.time_alone.quote'))->toBe('Finnish Lapphunds are dogs that crave companionship.');
    });
});
