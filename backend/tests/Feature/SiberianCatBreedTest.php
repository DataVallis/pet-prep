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
| M5-R10-16 — Siberian cat (fourth paid cat breed, hidden with the cats)
|--------------------------------------------------------------------------
| Sourced data: docs/research/cat-data/data.json `siberian_cat` (C13, C19,
| C33–C35) and the runbook's standing rules (confirmed by David 2026-10-10 /
| 2026-10-11): paid breed after the Ragdoll (sort_order 40), the pedigree kitten
| arrives at 3 months (CAT_SPEC Q4 rule, C13), every other stage / meal / play /
| litter value is the general cat rule, grooming 1× per week (PetMD C35 "at least
| once a week"), lifespan = GCCF lower bound 12 y (background only; C14 has no
| Siberian row), FIFe colours only (never pointed / chocolate / lilac / cinnamon /
| fawn), no blue eyes except on a white cat, a brown tabby portrait, never
| "hypoallergenic".
*/

beforeEach(function () {
    seedLifeStageData();
});

/** A value at a dotted path of the cat data.json. */
function sibData(string $path): mixed
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
function sibRows(string $slug = 'siberian-cat'): array
{
    return collect(BreedStageParamsSeeder::catRows())->where('breed_slug', $slug)->values()->all();
}

/** @return array<string, mixed> the Siberian seeder row of (stage, from, key) */
function sibRow(string $stage, int $from, StageParamKey $key): array
{
    $row = collect(sibRows())->first(fn (array $r) => $r['stage'] === $stage && $r['age_from_months'] === $from && $r['key'] === $key->value);
    expect($row)->not->toBeNull("siberian-cat.{$stage}.{$from}.{$key->value}");

    return $row;
}

function sibPet(int $arrivalMonths, array $attributes = []): Pet
{
    $child = User::factory()->child()->create();

    return disableHygieneEvents(Pet::factory()->siberianCat()->create(array_merge([
        'user_id' => $child->id,
        'born_at' => now(),
        'arrival_age_months' => $arrivalMonths,
    ], $attributes)))->fresh();
}

/** @param array<string, mixed> $body */
function sibPin(User $parent, array $body): TestResponse
{
    $child = app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id, 'species' => 'cat', 'features' => ['species_cat']], $body));
}

function sibLogin(string $pin): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => ['species_cat']]);
}

describe('breed, config and database', function () {
    it('is a paid cat breed with its own slug and the Maine Coon\'s cat config', function () {
        $breed = BreedType::SiberianCat;
        $config = BreedConfig::where('breed_slug', 'siberian-cat')->sole();

        expect($breed->value)->toBe('siberian_cat')
            ->and($breed->slug())->toBe('siberian-cat')
            ->and(BreedType::fromSlug('siberian-cat'))->toBe($breed)
            ->and(BreedType::fromSlug('siberian'))->toBeNull()
            ->and($breed->species())->toBe(Species::Cat)
            ->and($breed->defaultPremium())->toBeTrue()
            ->and($breed->isPremium())->toBeTrue()
            ->and(Species::Cat->freeBreed())->toBe(BreedType::DomesticCat)
            ->and([$config->species, $config->premium_unlock, $config->sort_order, $config->label_key])
            ->toBe([Species::Cat, true, 40, 'breeds.siberian_cat'])
            ->and($config->search_keywords)->toBe(['siberian', 'siberian cat', 'sibirska mačka', 'sibirska macka', 'sibirka'])
            ->and($config->daily_steps_required)->toBe(0)
            ->and(sibData('siberian_cat.identity.value'))->toBe(['slug' => 'siberian-cat', 'enum' => 'siberian_cat', 'premium' => true])
            ->and((string) sibData('siberian_cat.identity.decision'))->toContain('sort_order 40');

        // No breed-specific care number exists → the Maine Coon's cat rhythm (CAT_SPEC Q9).
        $coon = BreedConfig::where('breed_slug', 'maine-coon')->sole();
        foreach (['daily_steps_required', 'hunger_decay_rate', 'thirst_decay_rate', 'poops_per_day', 'water_times_per_day', 'water_min_gap_minutes', 'feed_windows'] as $field) {
            expect($config->{$field})->toBe($coon->{$field}, $field);
        }
    });

    it('accepts a Siberian pet only as a cat (breed / species CHECKs) and the migration is reversible', function () {
        $pet = sibPet(12);
        expect($pet->species)->toBe(Species::Cat)
            ->and(DB::table('pets')->where('id', $pet->id)->value('breed_type'))->toBe('siberian_cat');

        $refused = fn (array $values) => fn () => DB::transaction(fn () => DB::table('pets')->where('id', $pet->id)->update($values));
        expect($refused(['species' => 'dog']))->toThrow(QueryException::class)
            ->and($refused(['breed_type' => 'siberian']))->toThrow(QueryException::class);

        $migration = require database_path('migrations/2026_11_17_120000_add_siberian_cat_breed.php');
        // down() refuses while a Siberian exists (no silent data loss).
        expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(QueryException::class);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maine_coon']);
        $migration->down();
        expect($refused(['breed_type' => 'siberian_cat']))->toThrow(QueryException::class)
            // The old species rule is back: a Siberian would have had to be a dog.
            ->and(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'pets_species_breed_check'")->def)->not->toContain('siberian_cat');
        // The Ragdoll (previous migration, still a cat) and the dogs stay allowed after the rollback.
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'ragdoll']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_husky', 'species' => 'dog']);
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'domestic_cat', 'species' => 'cat']);
        $migration->up();
        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'siberian_cat']);
        expect(Pet::findOrFail($pet->id)->breed_type)->toBe(BreedType::SiberianCat);
    });

    it('seeds insert-only: a second deploy run adds nothing and keeps admin edits', function () {
        $count = BreedStageParam::where('breed_slug', 'siberian-cat')->count();
        expect($count)->toBe(count(sibRows()))->and($count)->toBeGreaterThan(0);

        BreedConfig::where('breed_slug', 'siberian-cat')->sole()->update(['sort_order' => 5]);
        (new BreedConfigsSeeder)->run();

        expect(BreedStageParam::where('breed_slug', 'siberian-cat')->count())->toBe($count)
            ->and(BreedConfig::where('breed_slug', 'siberian-cat')->value('sort_order'))->toBe(5);
    });

    it('leaves the dog rows and the other cats\' rows untouched', function () {
        expect(collect(BreedStageParamsSeeder::rows())->where('breed_slug', 'siberian-cat')->all())->toBe([])
            ->and(collect(BreedStageParamsSeeder::catRows())->pluck('breed_slug')->unique()->values()->all())->toBe(['domestic-cat', 'maine-coon', 'british-shorthair', 'ragdoll', 'siberian-cat'])
            ->and(collect(BreedStageParamsSeeder::catRows())->where('breed_slug', '!=', 'siberian-cat')
                ->whereIn('decision', [BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT, BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT_GROOMING])->all())->toBe([])
            // The other cats keep their own grooming, lifespan and arrival rows.
            ->and(collect(sibRows('maine-coon'))->firstWhere('key', StageParamKey::GroomingSessionsPerWeek->value)['value'])->toBe(3)
            ->and(collect(sibRows('ragdoll'))->firstWhere('key', StageParamKey::GroomingSessionsPerWeek->value)['value'])->toBe(2)
            ->and(collect(sibRows('british-shorthair'))->firstWhere('key', StageParamKey::GroomingSessionsPerWeek->value)['value'])->toBe(1)
            ->and(collect(sibRows('maine-coon'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(9.71)
            ->and(collect(sibRows('domestic-cat'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(11.89)
            ->and(collect(sibRows('ragdoll'))->firstWhere('key', StageParamKey::LifespanYears->value)['value'])->toBe(10.31)
            ->and(collect(sibRows('ragdoll'))->firstWhere('key', StageParamKey::ArrivalAgeMonths->value)['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'ragdoll.arrival_age_kitten');
    });
});

describe('life-stage data against data.json', function () {
    it('shares every general cat row with the other cats except arrival, lifespan and grooming', function () {
        $own = [StageParamKey::LifespanYears->value, StageParamKey::GroomingSessionsPerWeek->value];
        $general = fn (string $slug) => collect(sibRows($slug))
            ->reject(fn (array $r) => in_array($r['key'], $own, true)
                || ($r['key'] === StageParamKey::ArrivalAgeMonths->value && $r['stage'] === 'puppy'))
            ->map(fn (array $r) => array_diff_key($r, ['breed_slug' => true]))
            ->values()->all();

        expect($general('siberian-cat'))->toBe($general('maine-coon'))
            ->and($general('siberian-cat'))->toBe($general('ragdoll'))
            ->and($general('siberian-cat'))->toBe($general('domestic-cat'));
    });

    it('lets the pedigree kitten arrive at 3 months (runbook rule = CAT_SPEC Q4, C13)', function () {
        $row = sibRow('puppy', 0, StageParamKey::ArrivalAgeMonths);

        expect($row['value'])->toBe(sibData('siberian_cat.arrival_age_kitten.value'))
            ->and($row['value'])->toBe(3)
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'siberian_cat.arrival_age_kitten')
            ->and($row['source_id'])->toBe(sibData('siberian_cat.arrival_age_kitten.source_id'))
            ->and($row['quote'])->toBe(sibData('siberian_cat.arrival_age_kitten.quote'))
            ->and($row['verified'])->toBeTrue()
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT)
            ->and((string) sibData('siberian_cat.arrival_age_kitten.decision'))->toStartWith(BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT);

        foreach (['young' => 12, 'adult' => 84, 'senior' => 120] as $stage => $month) {
            expect(sibRow($stage, 0, StageParamKey::ArrivalAgeMonths)['value'])->toBe($month)
                ->and(sibRow($stage, 0, StageParamKey::StartsAtMonths)['value'])->toBe($month);
        }
    });

    it('takes the lifespan from the GCCF lower bound as background only (C14 has no Siberian row)', function () {
        $row = sibRow('all', 0, StageParamKey::LifespanYears);

        expect($row['value'])->toBe(sibData('siberian_cat.lifespan.gccf_lower_bound.value'))
            ->and($row['value'])->toBe(12.0)
            ->and($row['source_id'])->toBe('C34')
            ->and($row['quote'])->toBe(sibData('siberian_cat.lifespan.gccf_lower_bound.quote'))
            ->and($row['confidence'])->toBe(sibData('siberian_cat.lifespan.gccf_lower_bound.confidence'))
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'siberian_cat.lifespan.gccf_lower_bound')
            ->and(sibData('siberian_cat.lifespan.gccf_lower_bound.alternatives.0.source_id'))->toBe('C35')
            // Cats: the senior boundary is C1 (10 years), never 0.75 × lifespan.
            ->and(sibRow('senior', 0, StageParamKey::StartsAtMonths)['value'])->toBe(120);
    });

    it('combs 1× per program week from the sourced need (PetMD C35) and has no unverified row', function () {
        $row = sibRow('all', 0, StageParamKey::GroomingSessionsPerWeek);
        expect($row['value'])->toBe(1)
            ->and($row['value'])->toBe(sibData('siberian_cat.grooming.game_sessions_per_week.value'))
            ->and($row['source_id'])->toBe('C35')
            ->and($row['quote'])->toBe(sibData('siberian_cat.grooming.game_sessions_per_week.quote'))
            ->and($row['quote'])->toContain('at least once a week')
            ->and($row['ref'])->toBe(BreedStageParamsSeeder::CAT_REF.'siberian_cat.grooming.game_sessions_per_week')
            ->and($row['decision'])->toBe(BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT_GROOMING)
            ->and((string) sibData('siberian_cat.grooming.game_sessions_per_week.decision'))->toStartWith(BreedStageParamsSeeder::CONFIRMED_R10_SIBERIAN_CAT_GROOMING)
            ->and(collect(sibRows())->where('verified', false)->all())->toBe([])
            ->and(CatChoreService::mattedAfterMissed(1))->toBe(sibData('siberian_cat.grooming.game_matted_after_missed.value'));

        $cat = sibPet(12);
        expect(app(CatChoreService::class)->groomingGoalOn($cat, $cat->localDate(now())))->toBe(1)
            ->and(app(CatChoreService::class)->groomingAppliesTo($cat))->toBeTrue();
    });

    it('cites a data.json entry for every row', function () {
        foreach (sibRows() as $row) {
            expect($row['ref'])->toStartWith(BreedStageParamsSeeder::CAT_REF);
            $entry = sibData(substr($row['ref'], strlen(BreedStageParamsSeeder::CAT_REF)));
            expect(is_array($entry))->toBeTrue($row['ref']);
        }
    });
});

describe('stage rules', function () {
    it('follows the AAHA/AAFP cat stages: kitten → young at 12, mature at 84, senior at 120 months', function () {
        $service = app(LifeStageService::class);

        expect($service->stageStarts('siberian-cat'))->toBe(['puppy' => 0, 'young' => 12, 'adult' => 84, 'senior' => 120])
            ->and($service->arrivalAgeFor('siberian-cat', LifeStage::Puppy))->toBe(3)
            ->and($service->arrivalAgeFor('siberian-cat', LifeStage::Senior))->toBe(120)
            ->and($service->stageForAge('siberian-cat', 11))->toBe(LifeStage::Puppy)
            ->and($service->stageForAge('siberian-cat', 12))->toBe(LifeStage::Young)
            ->and($service->stageForAge('siberian-cat', 120))->toBe(LifeStage::Senior);
    });

    it('plays instead of walking (no steps, the general play goal)', function (int $arrival, LifeStage $stage, int $meals) {
        $pet = sibPet($arrival);
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
        $body = ['breed' => 'siberian_cat', 'origin' => 'bought', 'age_stage' => 'puppy'];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = sibPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(sibLogin($response->json('pin'))->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type)->toBe(BreedType::SiberianCat)
            ->and($pet->species)->toBe(Species::Cat)
            ->and($pet->arrival_age_months)->toBe(3)
            ->and($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired)
            // DNA v2 from the Siberian appearance data (a cat never gets DNA v1).
            ->and($pet->pet_dna['breed'])->toBe('siberian_cat')
            ->and((int) $pet->pet_dna['version'])->toBe(2);
    })->with([
        'free plan' => ['free', 'breed_locked', null],
        'challenge' => ['challenge', null, 'challenge'],
        'old app (no plan)' => [null, null, 'challenge'],
    ]);

    it('refuses the Siberian as a dog', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();

        sibPin($parent, ['species' => 'dog', 'breed' => 'siberian_cat', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge'])
            ->assertStatus(422);
        expect(Pet::count())->toBe(0);
    });

    it('arrives as a senior at 120 months', function () {
        config(['petprep.cats_enabled' => true]);
        $parent = User::factory()->parent()->create();
        $pin = sibPin($parent, ['breed' => 'siberian_cat', 'origin' => 'adopted', 'age_stage' => 'senior', 'plan' => 'challenge'])->assertOk()->json('pin');
        $pet = Pet::findOrFail(sibLogin($pin)->assertOk()->json('pet.id'));

        expect($pet->arrival_age_months)->toBe(120)->and($pet->plan)->toBe(PetPlan::Challenge);
    });
});

describe('GET /api/breeds', function () {
    it('lists the Siberian after the Ragdoll, and only for a cat app while the cats are on', function () {
        $parent = User::factory()->parent()->create();
        app('auth')->forgetGuards();
        actingAsRole($parent);

        // Hidden by default (PETPREP_CATS_ENABLED=false).
        expect(array_column(getJson('/api/breeds?'.http_build_query(['features' => ['species_cat']]))->assertOk()->json('breeds'), 'breed'))
            ->not->toContain('siberian_cat');

        config(['petprep.cats_enabled' => true]);
        $cats = getJson('/api/breeds?'.http_build_query(['species' => 'cat', 'features' => ['species_cat']]))->assertOk()->json('breeds');

        expect(array_column($cats, 'breed'))->toBe(['domestic_cat', 'maine_coon', 'british_shorthair', 'ragdoll', 'siberian_cat'])
            ->and($cats[4])->toMatchArray([
                'breed' => 'siberian_cat', 'slug' => 'siberian-cat', 'species' => 'cat', 'premium' => true,
                'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.siberian_cat',
                'sort_order' => 40,
            ]);
    });
});

describe('AI appearance (FIFe colours only, no blue eyes on a coloured coat, brown tabby portrait)', function () {
    it('samples Siberian DNA inside the FIFe colours, never pointed / chocolate / lilac / cinnamon / fawn', function () {
        $dna = app(PetDnaService::class);
        expect($dna->breeds())->toContain('siberian_cat')
            ->and(PetDnaService::hasAppearance('siberian_cat'))->toBeTrue()
            ->and(config('breed_appearance.siberian_cat.verified'))->toBeFalse()
            ->and(config('breed_appearance.siberian_cat.source'))->toContain('C33');

        $colours = [];
        foreach (range(1, 300) as $seed) {
            $t = $dna->sample('siberian_cat', $seed)['traits'];
            $colours[$t['coat_color']] = true;
            expect($t['coat_color'])->toBeIn(['brown', 'silver', 'red', 'black', 'blue-grey', 'cream', 'white', 'tortoiseshell black and red']);
            // Blue eyes only with white (FIFe C33: blue is not a Siberian eye colour otherwise).
            if ($t['eye_color'] === 'deep blue') {
                expect($t['coat_color'])->toBe('white');
            }
        }
        expect($colours)->toHaveKey('brown')->toHaveKey('silver');
        // Never the Neva Masquerade's pointed pattern or a non-FIFe colour anywhere in the options.
        $options = strtolower(json_encode(config('breed_appearance.siberian_cat.traits')));
        foreach (['point', 'chocolate', 'lilac', 'cinnamon', 'fawn', 'colourpoint'] as $word) {
            expect($options)->not->toContain($word);
        }
    });

    it('writes a cat prompt without "dog" and grows up through the stage overrides', function () {
        $prompts = app(PetAppearancePrompt::class);
        $traits = app(PetDnaService::class)->sample('siberian_cat', 7)['traits'];

        foreach ([null, LifeStage::Puppy, LifeStage::Young, LifeStage::Adult] as $stage) {
            $text = $prompts->describe('siberian_cat', $traits, $stage);
            expect(strtolower($text))->not->toContain('dog')
                ->and(strtolower($text))->not->toContain('husky')
                ->and($text)->toContain('Siberian cat');
        }
        expect($prompts->describe('siberian_cat', $traits))->toContain('massive head')
            ->and($prompts->describe('siberian_cat', $traits, LifeStage::Puppy))->not->toContain('massive')
            ->and($prompts->describe('siberian_cat', $traits, LifeStage::Puppy))->not->toContain('full neck ruff');
        expect(array_keys((array) config('breed_appearance.siberian_cat.stage_overrides')))
            ->toBe(array_keys((array) config('breed_appearance.siberian_cat.stage_notes')));
    });

    it('defaults the portrait to the brown mackerel tabby with green-gold eyes and has no DNA v1 path', function () {
        $traits = app(BreedPortraitService::class)->portraitTraits(BreedType::SiberianCat);

        expect($traits['coat_color'])->toBe('brown')
            ->and($traits['coat_pattern'])->toBe('with mackerel tabby stripes')
            ->and($traits['eye_color'])->toBe('green-gold');
        expect(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::SiberianCat))->toThrow(InvalidArgumentException::class);
    });
});

describe('no health statistics and no allergy claim in the game data', function () {
    it('keeps percentages, health notes and "hypoallergenic" out of the seeder rows and the appearance config', function () {
        $json = strtolower(json_encode(sibRows(), JSON_UNESCAPED_UNICODE));

        expect($json)->not->toMatch('/\d+(\.\d+)?\s?%/')
            ->not->toContain('cardiomyopathy')
            ->not->toContain('allerg');
        expect(strtolower(json_encode(config('breed_appearance.siberian_cat'))))->not->toContain('allerg');
    });
});
