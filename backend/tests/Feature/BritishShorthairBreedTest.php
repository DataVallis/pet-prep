<?php

use App\Enums\BreedType;
use App\Enums\ChallengeStatus;
use App\Enums\LifeStage;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Enums\StageParamKey;
use App\Models\BreedConfig;
use App\Models\BreedStageParam;
use App\Models\Pet;
use App\Models\User;
use App\Services\CatChoreService;
use App\Services\ChildProfileService;
use App\Services\FalAiService;
use App\Services\LifeStageService;
use App\Services\Media\BreedPortraitService;
use App\Services\Media\PetAppearancePrompt;
use App\Services\Media\PetDnaService;
use Database\Seeders\BreedConfigsSeeder;
use Database\Seeders\BreedStageParamsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R10-14 — British Shorthair (second paid cat breed, hidden with the cats)
|--------------------------------------------------------------------------
| Sourced data: docs/research/cat-data/data.json `british_shorthair` (C13, C14,
| C19, C26–C28) and the runbook's standing rules (confirmed by David
| 2026-10-10): paid breed after the Maine Coon (sort_order 20), the pedigree
| kitten arrives at 3 months (CAT_SPEC Q4 rule, C13), every other stage / meal /
| play / litter value is the general cat rule, grooming 1× per week (VCA C28,
| David 2026-10-11), lifespan = the C14 "British" group (background only),
| FIFe colours only with a blue-grey portrait and a moderate (not flat) face.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the cat data.json. */
function bshData(string $path): mixed
{
    static $data = null;
    $data ??= json_decode((string) file_get_contents(base_path('../docs/research/cat-data/data.json')), true, flags: JSON_THROW_ON_ERROR);

    $node = $data;
    foreach (explode('.', $path) as $segment) {
        expect($node)->toHaveKey($segment);
        $node = $node[$segment];
    }

    return $node;
}

/** @return list<array<string, mixed>> the seeder rows of one cat breed slug */
function bshRows(string $slug = 'british-shorthair'): array
{
    return collect(BreedStageParamsSeeder::catRows())->where('breed_slug', $slug)->values()->all();
}

/** @return array<string, mixed> the British Shorthair seeder row of (stage, from, key) */
function bshRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(bshRows())->first(fn (array $r) => $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("british_shorthair.{$stage}.{$from}.{$key->value}");

    return $row;
}

function bshPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->britishShorthair()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function bshPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id, 'species' => 'cat', 'features' => ['species_cat']], $body));
}

function bshLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => ['species_cat']]);
}

describe('breed, config and database', function () {
    it('is a paid cat breed with its own slug and the Maine Coon\'s cat config', function () {
        $breed = BreedType::BritishShorthair;
        $config = BreedConfig::where('breed_slug', 'british-shorthair')->sole();

        expect($breed->value)->toBe('british_shorthair')
            ->and($breed->slug())->toBe('british-shorthair')
            ->and(BreedType::fromSlug('british-shorthair'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Cat)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Cat->freeBreed())->toBe(BreedType::DomesticCat)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Cat, true, 20, 'breeds.british_shorthair'])
            ->and($config->search_keywords)->toBe(['british shorthair', 'britanska kratkodlaka mačka', 'britanska kratkodlaka macka', 'britanska kratkodlaka', 'britanka', 'british blue', 'bsh'])
            ->and($config->daily_steps_required)->toBe(0)
            ->and(bshData('british_shorthair.identity.value'))->toBe(['slug' => 'british-shorthair', 'enum' => 'british_shorthair', 'premium' => true])
            ->and((string) bshData('british_shorthair.identity.decision'))->toContain('sort_order 20');

        // No breed-specific care number exists → the Maine Coon's cat rhythm (CAT_SPEC Q9).
        $coon = BreedConfig::where('breed_slug', 'maine-coon')->sole();
        foreach (['daily_steps_required', 'hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($coon->{$field}, $field);
        }
    });

    it('accepts a British Shorthair pet only as a cat (breed / species CHECKs) and the migration is reversible', function () {
        $pet = bshPet(12);
        expect($pet->species)->toBe(Species::Cat)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('british_shorthair');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'dog']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'british_blue']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_15_120000_add_british_shorthair_breed.php');
        // down() refuses while a British Shorthair exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maine_coon']);
        $migration->down();
        expect($refused(['breed_type' => 'british_shorthair']))->toThrow(QueryException::class)
            // The old species rule is back: a British Shorthair would have had to be a dog.
            ->and(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pets_species_breed_check'")->def)->not->toContain('british_shorthair');
        // The Siberian Husky (previous migration) and the other cats stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_husky', 'species' => 'dog']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'domestic_cat', 'species' => 'cat']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'british_shorthair']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::BritishShorthair);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'british-shorthair')->count();
        expect($count)->toBe(count(bshRows()))->and($count)->toBeGreaterThan(0);

        BreedConfig::where('breed_slug', 'british-shorthair')->sole()->update(['sort_order' => 5]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'british-shorthair')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'british-shorthair')->value('sort_order'))->toBe(5);
    });

    it('leaves the dog rows and the other cats\' rows untouched', function () {
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'british-shorthair')->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::catRows())->pluck('breed_slug')->unique()->values()->all())->toBe(breedSlugsOf(Species::Cat))
            ->and(collect(BreedStageParamsSeeder::catRows())->where('breed_slug', '!=', 'british-shorthair')
                ->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_BRITISH_SHORTHAIR)->all())->toBe([])
            // The Maine Coon still has its grooming row and its own lifespan / arrival.
            ->and(collect(bshRows('maine-coon'))->where('key', StageParamKey::GroomingSessionsPerWeek->value)->count())->toBe(1)
            ->and(collect(bshRows('maine-coon'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(9.71)
            ->and(collect(bshRows('domestic-cat'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(11.89);
    });
});

describe('life-stage data against data.json', function () {
    it('shares every general cat row with the Maine Coon except arrival, lifespan and grooming', function () {
        $own = [StageParamKey::LifespanYears->value, StageParamKey::GroomingSessionsPerWeek->value];
        $general = fn (string $slug) => collect(bshRows($slug))
            ->reject(fn (array $r) => in_array($r['key'], $own, true)
                || ($r['key'] === StageParamKey::ArrivalAgeMonths->value && $r['stage'] === 'puppy'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('british-shorthair'))->toBe($general('maine-coon'))
            ->and($general('british-shorthair'))->toBe($general('domestic-cat'));
    });

    it('lets the pedigree kitten arrive at 3 months (runbook rule = CAT_SPEC Q4, C13)', function () {
        $row = bshRow('puppy', 0, StageParamKey::ArrivalAgeMonths);

        expect($row['value'])->toBe(bshData('british_shorthair.arrival_age_kitten.value'))
            ->and($row['value'])->toBe(3)
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'british_shorthair.arrival_age_kitten')
            ->and($row['source_id'])->toBe(bshData('british_shorthair.arrival_age_kitten.source_id'))
            ->and($row['quote'])->toBe(bshData('british_shorthair.arrival_age_kitten.quote'))
            ->and($row['verified'])->toBeTrue()
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_BRITISH_SHORTHAIR)
            ->and((string) bshData('british_shorthair.arrival_age_kitten.decision'))->toStartWith(BreedStageParamsSeeder::CONFIRMED_R10_BRITISH_SHORTHAIR);

        foreach (['young' => 12, 'adult' => 84, 'senior' => 120] as $stage => $month) {
            expect(bshRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe($month)
                ->and(bshRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe($month);
        }
    });

    it('takes the lifespan from the C14 "British" group as background only', function () {
        $row = bshRow('all', 0, StageParamKey::LifespanYears);

        expect($row['value'])->toBe(bshData('british_shorthair.lifespan.expectancy_at_birth.value'))
            ->and($row['value'])->toBe(9.58)
            ->and($row['source_id'])->toBe('C14')
            ->and($row['quote'])->toBe(bshData('british_shorthair.lifespan.expectancy_at_birth.quote'))
            ->and($row['confidence'])->toBe(bshData('british_shorthair.lifespan.expectancy_at_birth.confidence'))
            // Cats: the senior boundary is C1 (10 years), never 0.75 × lifespan.
            ->and(bshRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(120);
    });

    it('combs 1× per program week from the sourced need (David 2026-10-11) and has no unverified row', function () {
        $row = bshRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($row['value'])->toBe(1)
            ->and($row['value'])->toBe(bshData('british_shorthair.grooming.game_sessions_per_week.value'))
            ->and($row['source_id'])->toBe('C28')
            ->and($row['quote'])->toBe('Run a comb through its coat at least weekly to keep it mat-free and looking healthy.')
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'british_shorthair.grooming.game_sessions_per_week')
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_CAT_GROOMING_2026_10_11)
            ->and(collect(bshRows())->where('verified', false)->all())->toBe([])
            ->and(CatChoreService::mattedAfterMissed(1))->toBe(bshData('british_shorthair.grooming.game_matted_after_missed.value'));

        $cat = bshPet(12);
        expect(app(CatChoreService::class)->groomingGoalOn($cat, $cat->localDate(now())))->toBe(1)
            ->and(app(CatChoreService::class)->groomingAppliesTo($cat))->toBeTrue();
    });

    it('cites a data.json entry for every row', function () {
        foreach (bshRows() as $row) {
            expect($row['ref'])->toStartWith(BreedStageParamsSeeder::CAT_REF);
            $entry = bshData(substr($row['ref'], strlen(BreedStageParamsSeeder::CAT_REF)));
            expect(is_array($entry))->toBeTrue($row['ref']);
        }
    });
});

describe('stage rules', function () {
    it('follows the AAHA/AAFP cat stages: kitten → young at 12, mature at 84, senior at 120 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageStarts('british-shorthair'))->toBe(['puppy' => 0, 'young' => 12, 'adult' => 84, 'senior' => 120])
            ->and($service->arrivalAgeFor('british-shorthair', LifeStage::Puppy))->toBe(3)
            ->and($service->arrivalAgeFor('british-shorthair', LifeStage::Senior))->toBe(120)
            ->and($service->stageForAge('british-shorthair', 11))->toBe(LifeStage::Puppy)
            ->and($service->stageForAge('british-shorthair', 12))->toBe(LifeStage::Young)
            ->and($service->stageForAge('british-shorthair', 120))->toBe(LifeStage::Senior);
    });

    it('plays instead of walking (no steps, the general play goal)', function (int $arrival, LifeStage $stage, int $meals) {
        $pet = bshPet($arrival);
        $rules = app(LifeStageService::class)->rulesOn($pet, $pet->localDate(now()));

        expect($rules->lifeStage)->toBe($stage)
            ->and($rules->mealsPerDay)->toBe($meals)
            ->and($rules->verified())->toBeTrue();
    })->with([
        'kitten 3 months' => [3, LifeStage::Puppy, 3],
        'young cat 12 months' => [12, LifeStage::Young, 2],
        'senior cat 120 months' => [120, LifeStage::Senior, 2],
    ]);
});

describe('generate-pin: paid like the Maine Coon (cats switched on)', function () {
    it('applies the paid-breed rule (challenge, free plan refused)', function (?string $plan, ?string $reason, ?string $petPlan) {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();
        $body = ['breed' => 'british_shorthair', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = bshPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(bshLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::BritishShorthair)
            ->and($pet->species)->toBe(Species::Cat)
            ->and($pet->arrival_age_months)->toBe(3)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            // DNA v2 from the British Shorthair appearance data (a cat never gets DNA v1).
            ->and($pet->pet_dna['breed'])->toBe('british_shorthair')
            ->and((int) $pet->pet_dna['version'])->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('refuses the British Shorthair as a dog', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();

        bshPin($parent, ['species' => 'dog', 'breed' => 'british_shorthair', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge'])
            ->assertStatus(422);
        expect(Pet::count())->toBe(0);
    });

    it('arrives as a senior at 120 months', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();
        $pin = bshPin($parent, ['breed' => 'british_shorthair', 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
        $pet = Pet::findOrFail(bshLogin($pin)->assertOk()->json('pet.id'));

        expect($pet->arrival_age_months)->toBe(120)->and($pet->plan)->toBe(PetPlan::Challenge);
    });
});

describe('GET /api/breeds', function () {
    it('lists the British Shorthair after the Maine Coon, and only for a cat app while the cats are on', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        // Hidden by default (PETPREP_CATS_ENABLED=false).
        expect(array_column(getJson('/api/breeds?'.http_build_query(['features' => ['species_cat']]))->assertOk()->json('breeds'), 'breed'))
            ->not->toContain('british_shorthair');

        config(['petprep.cats_enabled' => true]);
        $cats = getJson('/api/breeds?'.http_build_query(['species' => 'cat', 'features' => ['species_cat']]))->assertOk()->json('breeds');

        $i = expectListedAfter($cats, 'british_shorthair', 'maine_coon', [Species::Cat]);

        expect($cats[$i])->toMatchArray([
            'breed' => 'british_shorthair', 'slug' => 'british-shorthair', 'species' => 'cat', 'premium' => true,
            'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.british_shorthair',
            'sort_order' => 20,
        ]);
    });
});

describe('AI appearance (FIFe colours only, blue-grey portrait, a moderate face)', function () {
    it('samples British Shorthair DNA inside the FIFe varieties with matching eye colours', function () {
        $dna = app(PetDnaService::class);
        expect($dna->breeds())->toContain('british_shorthair')
            ->and(PetDnaService::hasAppearance('british_shorthair'))->toBeTrue()
            ->and(config('breed_appearance.british_shorthair.verified'))->toBeFalse()
            ->and(config('breed_appearance.british_shorthair.source'))->toContain('C26');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $t = $dna->sample('british_shorthair', $seed)['traits'];
            $colours[$t['coat_color']] = true;
            expect($t['coat_color'])->toBeIn(['blue-grey', 'black', 'cream', 'lilac', 'white', 'red', 'silver', 'tortoiseshell black and red']);
            if ($t['eye_color'] === 'green') {
                expect($t['coat_color'])->toBe('silver');
            }
            if ($t['eye_color'] === 'deep blue') {
                expect($t['coat_color'])->toBe('white');
            }
            if ($t['coat_color'] === 'silver') {
                expect($t['eye_color'])->toBe('green')->and($t['coat_pattern'])->toBe('with classic tabby markings');
            }
        }
        expect($colours)->toHaveKey('blue-grey');
    });

    it('writes a cat prompt without "dog" and without a flat face, and grows up through the stage overrides', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = app(PetDnaService::class)->sample('british_shorthair', 7)['traits'];

        foreach ([null, LifeStage::Puppy, LifeStage::Young, LifeStage::Adult] as $stage) {
            $text = $prompts->describe('british_shorthair', $traits, $stage);
            expect(strtolower($text))->not->toContain('dog')
                ->and($text)->toContain('British Shorthair cat');
        }
        expect($prompts->describe('british_shorthair', $traits))->toContain('not a flat face')
            ->and($prompts->describe('british_shorthair', $traits, LifeStage::Puppy))->not->toContain('massive');
        expect(array_keys((array) config('breed_appearance.british_shorthair.stage_overrides')))
            ->toBe(array_keys((array) config('breed_appearance.british_shorthair.stage_notes')));
    });

    it('defaults the portrait to the blue-grey solid cat and has no DNA v1 path', function () {
        $traits = app(BreedPortraitService::class)->portraitTraits(BreedType::BritishShorthair);

        expect($traits['coat_color'])->toBe('blue-grey')
            ->and($traits['coat_pattern'])->toBe('solid')
            ->and($traits['eye_color'])->toBe('deep copper');
        expect(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::BritishShorthair))->toThrow(InvalidArgumentException::class);
    });
});
