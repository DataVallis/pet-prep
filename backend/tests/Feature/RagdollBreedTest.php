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
| M5-R10-15 — Ragdoll (third paid cat breed, hidden with the cats)
|--------------------------------------------------------------------------
| Sourced data: docs/research/cat-data/data.json `ragdoll` (C13, C14, C19,
| C29–C32) and the runbook's standing rules (confirmed by David 2026-10-10):
| paid breed after the British Shorthair (sort_order 30), the pedigree kitten
| arrives at 3 months (CAT_SPEC Q4 rule, C13), every other stage / meal / play /
| litter value is the general cat rule, grooming 2× per week (PetMD C32, David
| 2026-10-11), lifespan = C14 Ragdoll life expectancy (background only), FIFe
| colours and patterns only, blue eyes only, a seal colourpoint portrait.
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the cat data.json. */
function ragData(string $path): mixed
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
function ragRows(string $slug = 'ragdoll'): array
{
    return collect(BreedStageParamsSeeder::catRows())->where('breed_slug', $slug)->values()->all();
}

/** @return array<string, mixed> the Ragdoll seeder row of (stage, from, key) */
function ragRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(ragRows())->first(fn (array $r) => $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("ragdoll.{$stage}.{$from}.{$key->value}");

    return $row;
}

function ragPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->ragdoll()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function ragPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id, 'species' => 'cat', 'features' => ['species_cat']], $body));
}

function ragLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => ['species_cat']]);
}

describe('breed, config and database', function () {
    it('is a paid cat breed with its own slug and the Maine Coon\'s cat config', function () {
        $breed = BreedType::Ragdoll;
        $config = BreedConfig::where('breed_slug', 'ragdoll')->sole();

        expect($breed->value)->toBe('ragdoll')
            ->and($breed->slug())->toBe('ragdoll')
            ->and(BreedType::fromSlug('ragdoll'))->toBe($breed)
            ->and($breed->species())->toBe(Species::Cat)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Cat->freeBreed())->toBe(BreedType::DomesticCat)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Cat, true, 30, 'breeds.ragdoll'])
            ->and($config->search_keywords)->toBe(['ragdoll', 'ragdoll mačka', 'ragdoll macka', 'ragdol', 'rag doll'])
            ->and($config->daily_steps_required)->toBe(0)
            ->and(ragData('ragdoll.identity.value'))->toBe(['slug' => 'ragdoll', 'enum' => 'ragdoll', 'premium' => true])
            ->and((string) ragData('ragdoll.identity.decision'))->toContain('sort_order 30');

        // No breed-specific care number exists → the Maine Coon's cat rhythm (CAT_SPEC Q9).
        $coon = BreedConfig::where('breed_slug', 'maine-coon')->sole();
        foreach (['daily_steps_required', 'hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($coon->{$field}, $field);
        }
    });

    it('accepts a Ragdoll pet only as a cat (breed / species CHECKs) and the migration is reversible', function () {
        $pet = ragPet(12);
        expect($pet->species)->toBe(Species::Cat)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('ragdoll');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'dog']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'rag_doll']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_16_120000_add_ragdoll_breed.php');
        // down() refuses while a Ragdoll exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maine_coon']);
        $migration->down();
        expect($refused(['breed_type' => 'ragdoll']))->toThrow(QueryException::class)
            // The old species rule is back: a Ragdoll would have had to be a dog.
            ->and(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pets_species_breed_check'")->def)->not->toContain('ragdoll');
        // The British Shorthair (previous migration, still a cat) and the dogs stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'british_shorthair']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_husky', 'species' => 'dog']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'domestic_cat', 'species' => 'cat']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'ragdoll']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::Ragdoll);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'ragdoll')->count();
        expect($count)->toBe(count(ragRows()))->and($count)->toBeGreaterThan(0);

        BreedConfig::where('breed_slug', 'ragdoll')->sole()->update(['sort_order' => 5]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'ragdoll')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'ragdoll')->value('sort_order'))->toBe(5);
    });

    it('leaves the dog rows and the other cats\' rows untouched', function () {
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'ragdoll')->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::catRows())->pluck('breed_slug')->unique()->values()->all())->toBe(['domestic-cat', 'maine-coon', 'british-shorthair', 'ragdoll', 'siberian-cat', 'norwegian-forest-cat'])
            ->and(collect(BreedStageParamsSeeder::catRows())->where('breed_slug', '!=', 'ragdoll')
                ->where('decision', BreedStageParamsSeeder::CONFIRMED_R10_RAGDOLL)->all())->toBe([])
            // The Maine Coon still has its grooming row and its own lifespan / arrival.
            ->and(collect(ragRows('maine-coon'))->where('key', StageParamKey::GroomingSessionsPerWeek->value)->count())->toBe(1)
            ->and(collect(ragRows('maine-coon'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(9.71)
            ->and(collect(ragRows('domestic-cat'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(11.89)
            ->and(collect(ragRows('british-shorthair'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(9.58)
            ->and(collect(ragRows('british-shorthair'))->firstWhere('key', StageParamKey::ArrivalAgeMonths->value)['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'british_shorthair.arrival_age_kitten');
    });
});

describe('life-stage data against data.json', function () {
    it('shares every general cat row with the Maine Coon except arrival, lifespan and grooming', function () {
        $own = [StageParamKey::LifespanYears->value, StageParamKey::GroomingSessionsPerWeek->value];
        $general = fn (string $slug) => collect(ragRows($slug))
            ->reject(fn (array $r) => in_array($r['key'], $own, true)
                || ($r['key'] === StageParamKey::ArrivalAgeMonths->value && $r['stage'] === 'puppy'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('ragdoll'))->toBe($general('maine-coon'))
            ->and($general('ragdoll'))->toBe($general('british-shorthair'))
            ->and($general('ragdoll'))->toBe($general('domestic-cat'));
    });

    it('lets the pedigree kitten arrive at 3 months (runbook rule = CAT_SPEC Q4, C13)', function () {
        $row = ragRow('puppy', 0, StageParamKey::ArrivalAgeMonths);

        expect($row['value'])->toBe(ragData('ragdoll.arrival_age_kitten.value'))
            ->and($row['value'])->toBe(3)
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'ragdoll.arrival_age_kitten')
            ->and($row['source_id'])->toBe(ragData('ragdoll.arrival_age_kitten.source_id'))
            ->and($row['quote'])->toBe(ragData('ragdoll.arrival_age_kitten.quote'))
            ->and($row['verified'])->toBeTrue()
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_RAGDOLL)
            ->and((string) ragData('ragdoll.arrival_age_kitten.decision'))->toStartWith(BreedStageParamsSeeder::CONFIRMED_R10_RAGDOLL);

        foreach (['young' => 12, 'adult' => 84, 'senior' => 120] as $stage => $month) {
            expect(ragRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe($month)
                ->and(ragRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe($month);
        }
    });

    it('takes the lifespan from the C14 Ragdoll life expectancy as background only (not the C31 median age at death)', function () {
        $row = ragRow('all', 0, StageParamKey::LifespanYears);

        expect($row['value'])->toBe(ragData('ragdoll.lifespan.expectancy_at_birth.value'))
            ->and($row['value'])->toBe(10.31)
            ->and($row['source_id'])->toBe('C14')
            ->and($row['quote'])->toBe(ragData('ragdoll.lifespan.expectancy_at_birth.quote'))
            ->and($row['confidence'])->toBe(ragData('ragdoll.lifespan.expectancy_at_birth.confidence'))
            ->and(ragData('ragdoll.lifespan.expectancy_at_birth.alternatives.0.source_id'))->toBe('C31')
            ->and(collect(ragRows())->pluck('value')->all())->not->toContain(12.85)
            // Cats: the senior boundary is C1 (10 years), never 0.75 × lifespan.
            ->and(ragRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(120);
    });

    it('combs 2× per program week from the sourced need (David 2026-10-11) and has no unverified row', function () {
        $row = ragRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($row['value'])->toBe(2)
            ->and($row['value'])->toBe(ragData('ragdoll.grooming.game_sessions_per_week.value'))
            ->and($row['source_id'])->toBe('C32')
            ->and($row['quote'])->toBe('Some Ragdolls only need to be combed (more effective than brushing) once or twice a week')
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'ragdoll.grooming.game_sessions_per_week')
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_CAT_GROOMING_2026_10_11)
            ->and(collect(ragRows())->where('verified', false)->all())->toBe([])
            ->and(CatChoreService::mattedAfterMissed(2))->toBe(ragData('ragdoll.grooming.game_matted_after_missed.value'));

        $cat = ragPet(12);
        expect(app(CatChoreService::class)->groomingGoalOn($cat, $cat->localDate(now())))->toBe(2)
            ->and(app(CatChoreService::class)->groomingAppliesTo($cat))->toBeTrue();
    });

    it('cites a data.json entry for every row', function () {
        foreach (ragRows() as $row) {
            expect($row['ref'])->toStartWith(BreedStageParamsSeeder::CAT_REF);
            $entry = ragData(substr($row['ref'], strlen(BreedStageParamsSeeder::CAT_REF)));
            expect(is_array($entry))->toBeTrue($row['ref']);
        }
    });
});

describe('stage rules', function () {
    it('follows the AAHA/AAFP cat stages: kitten → young at 12, mature at 84, senior at 120 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageStarts('ragdoll'))->toBe(['puppy' => 0, 'young' => 12, 'adult' => 84, 'senior' => 120])
            ->and($service->arrivalAgeFor('ragdoll', LifeStage::Puppy))->toBe(3)
            ->and($service->arrivalAgeFor('ragdoll', LifeStage::Senior))->toBe(120)
            ->and($service->stageForAge('ragdoll', 11))->toBe(LifeStage::Puppy)
            ->and($service->stageForAge('ragdoll', 12))->toBe(LifeStage::Young)
            ->and($service->stageForAge('ragdoll', 120))->toBe(LifeStage::Senior);
    });

    it('plays instead of walking (no steps, the general play goal)', function (int $arrival, LifeStage $stage, int $meals) {
        $pet = ragPet($arrival);
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
        $body = ['breed' => 'ragdoll', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = ragPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(ragLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::Ragdoll)
            ->and($pet->species)->toBe(Species::Cat)
            ->and($pet->arrival_age_months)->toBe(3)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            // DNA v2 from the Ragdoll appearance data (a cat never gets DNA v1).
            ->and($pet->pet_dna['breed'])->toBe('ragdoll')
            ->and((int) $pet->pet_dna['version'])->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('refuses the Ragdoll as a dog', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();

        ragPin($parent, ['species' => 'dog', 'breed' => 'ragdoll', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge'])
            ->assertStatus(422);
        expect(Pet::count())->toBe(0);
    });

    it('arrives as a senior at 120 months', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();
        $pin = ragPin($parent, ['breed' => 'ragdoll', 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
        $pet = Pet::findOrFail(ragLogin($pin)->assertOk()->json('pet.id'));

        expect($pet->arrival_age_months)->toBe(120)->and($pet->plan)->toBe(PetPlan::Challenge);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Ragdoll after the British Shorthair, and only for a cat app while the cats are on', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        // Hidden by default (PETPREP_CATS_ENABLED=false).
        expect(array_column(getJson('/api/breeds?'.http_build_query(['features' => ['species_cat']]))->assertOk()->json('breeds'), 'breed'))
            ->not->toContain('ragdoll');

        config(['petprep.cats_enabled' => true]);
        $cats = getJson('/api/breeds?'.http_build_query(['species' => 'cat', 'features' => ['species_cat']]))->assertOk()->json('breeds');

        expect(array_column($cats, 'breed'))->toBe(['domestic_cat', 'maine_coon', 'british_shorthair', 'ragdoll', 'siberian_cat', 'norwegian_forest_cat'])
            ->and($cats[3])->toMatchArray([
                'breed' => 'ragdoll', 'slug' => 'ragdoll', 'species' => 'cat', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.ragdoll',
                'sort_order' => 30,
            ]);
    });
});

describe('AI appearance (FIFe colours and patterns only, blue eyes only, seal colourpoint portrait)', function () {
    it('samples Ragdoll DNA inside the FIFe colours and patterns, always with blue eyes', function () {
        $dna = app(PetDnaService::class);
        expect($dna->breeds())->toContain('ragdoll')
            ->and(PetDnaService::hasAppearance('ragdoll'))->toBeTrue()
            ->and(config('breed_appearance.ragdoll.verified'))->toBeFalse()
            ->and(config('breed_appearance.ragdoll.source'))->toContain('C29');

        $colours = [];
        $patterns = [];
        foreach (range(1, 300) as $seed) {
            $t = $dna->sample('ragdoll', $seed)['traits'];
            $colours[strtok($t['coat_color'], ' ')] = true;
            $patterns[$t['coat_pattern']] = true;
            // Any eye colour other than blue disqualifies (FIFe C29).
            expect($t['eye_color'])->toBe('deep blue')
                ->and(strtok($t['coat_color'], ' '))->toBeIn(['seal', 'blue', 'chocolate', 'lilac', 'red', 'cream'])
                ->and($t['coat_color'])->toContain(' point (')
                ->and($t['coat_pattern'])->toMatch('/^in the (colourpoint|mitted|bicolour) pattern/');
        }
        expect($colours)->toHaveKey('seal')
            ->and(count($patterns))->toBe(3);
        // No van pattern (CFA only, not FIFe), no non-blue eyes anywhere in the options.
        expect(strtolower(json_encode(config('breed_appearance.ragdoll.traits'))))->not->toContain('van')
            ->not->toContain('copper')->not->toContain('green')->not->toContain('gold');
    });

    it('writes a cat prompt without "dog", with blue eyes, and grows up through the stage overrides', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = app(PetDnaService::class)->sample('ragdoll', 7)['traits'];

        foreach ([null, LifeStage::Puppy, LifeStage::Young, LifeStage::Adult] as $stage) {
            $text = $prompts->describe('ragdoll', $traits, $stage);
            expect(strtolower($text))->not->toContain('dog')
                ->and($text)->toContain('Ragdoll cat')
                ->and($text)->toContain('deep blue eyes');
        }
        expect($prompts->describe('ragdoll', $traits))->toContain('a full bib of longer fur')
            ->and($prompts->describe('ragdoll', $traits, LifeStage::Puppy))->not->toContain('full bib')
            ->and($prompts->describe('ragdoll', $traits, LifeStage::Puppy))->not->toContain('plume');
        expect(array_keys((array) config('breed_appearance.ragdoll.stage_overrides')))
            ->toBe(array_keys((array) config('breed_appearance.ragdoll.stage_notes')));
    });

    it('defaults the portrait to the seal colourpoint cat and has no DNA v1 path', function () {
        $traits = app(BreedPortraitService::class)->portraitTraits(BreedType::Ragdoll);

        expect($traits['coat_color'])->toStartWith('seal point')
            ->and($traits['coat_pattern'])->toBe('in the colourpoint pattern, with no white anywhere')
            ->and($traits['eye_color'])->toBe('deep blue');
        expect(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::Ragdoll))->toThrow(InvalidArgumentException::class);
    });
});

describe('no health statistics in the game data', function () {
    it('keeps the C31 percentages and the health notes out of the seeder rows', function () {
        $json = json_encode(ragRows(), JSON_UNESCAPED_UNICODE);

        expect($json)->not->toMatch('/\d+(\.\d+)?\s?%/')
            ->not->toContain('periodontal')
            ->not->toContain('cardiomyopathy');
    });
});
