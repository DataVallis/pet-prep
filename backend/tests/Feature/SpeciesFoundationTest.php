<?php

use App\Enums\BreedType;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Enums\Species;
use App\Events\PetUpdated;
use App\Filament\Resources\BreedConfigResource\Pages\EditBreedConfig;
use App\Filament\Resources\BreedConfigResource\Pages\ListBreedConfigs;
use App\Filament\Resources\PetResource\Pages\ListPets;
use App\Models\BreedConfig;
use App\Models\ChildLoginPin;
use App\Models\Pet;
use App\Models\User;
use App\Services\BreedCatalogService;
use App\Services\ChildProfileService;
use App\Services\FalAiService;
use App\Services\PairingService;
use App\Services\PetPlanPayload;
use Database\Seeders\BreedConfigsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R06-01 — species foundation (dog + cat), cats hidden
|--------------------------------------------------------------------------
|
| pets.species + breed ↔ species CHECK, BreedType cats, breed_configs as the
| single source of free / paid (premium_unlock), generate-pin `species`
| (422 breed_species_mismatch / species_unavailable), cats dark behind
| PETPREP_CATS_ENABLED + ClientFeature species_cat (parent AND child app),
| GET /api/breeds, `species` in every pet payload. No cat game rules yet.
| Regression: dogs behave exactly as before (whole suite unchanged).
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 10:00:00', 'UTC'));
    seedLifeStageData();
    config(['petprep.cats_enabled' => false]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function spCatsOn(): void
{
    config(['petprep.cats_enabled' => true]);
}

/** @param array<string, mixed> $body */
function spPin(User $parent, array $body, ?User $child = null): TestResponse
{
    $child ??= app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

/** @param list<string> $features */
function spLogin(string $pin, array $features = []): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => $features]);
}

/** @param array<string, mixed> $profile */
function spCatPin(User $parent, array $profile = [], ?User $child = null): TestResponse
{
    return spPin($parent, array_merge([
        'species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'young',
        'plan' => 'free', 'features' => ['species_cat'],
    ], $profile), $child);
}

/** @param array<string, mixed> $query */
function spCatalogue(User $parent, array $query = []): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return getJson('/api/breeds'.($query === [] ? '' : '?'.http_build_query($query)));
}

// ─────────────────────────────────────────────────────────────────────────
describe('migration: species columns, backfill and constraints', function () {
    it('backfills existing pets as dogs and existing dog breed configs with catalogue fields', function () {
        $migration = require database_path('migrations/2026_10_24_120000_add_species.php');
        $child = User::factory()->child()->create();
        $familyId = Pet::factory()->create(['user_id' => $child->id])->family_id;
        $migration->down();

        DB::table('pets')->insert(['user_id' => $child->id, 'family_id' => $familyId, 'breed_type' => 'border_collie', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('breed_configs')->whereIn('breed_slug', ['domestic-cat', 'maine-coon'])->delete();

        $migration->up();

        expect(DB::table('pets')->pluck('species')->unique()->values()->all())->toBe(['dog']);
        $mutt = DB::table('breed_configs')->where('breed_slug', 'mutt')->first();
        $collie = DB::table('breed_configs')->where('breed_slug', 'border-collie')->first();
        expect([$mutt->species, $mutt->sort_order, $mutt->label_key, json_decode($mutt->search_keywords, true)])
            ->toBe(['dog', 0, 'breeds.mutt', ['mešanček', 'mesancek', 'mutt', 'mixed']])
            ->and([$collie->species, $collie->sort_order, $collie->label_key, json_decode($collie->search_keywords, true)])
            ->toBe(['dog', 10, 'breeds.border_collie', ['border collie', 'koli']])
            // Dog game numbers untouched.
            ->and([(float) $mutt->hunger_decay_rate, (float) $mutt->thirst_decay_rate, (bool) $mutt->premium_unlock])->toBe([8.0, 10.0, false])
            ->and([(float) $collie->hunger_decay_rate, (float) $collie->thirst_decay_rate, (bool) $collie->premium_unlock])->toBe([12.0, 15.0, true]);
    });

    it('accepts the cat breeds and keeps species and breed consistent at the database level', function () {
        $child = User::factory()->child()->create();
        $pet = Pet::factory()->create(['user_id' => $child->id]);

        // Savepoints: a refused statement must not abort the test transaction.
        $refused = fn (string $table, array $match, array $values) => fn () => DB::transaction(fn () => DB::table($table)->where($match)->update($values));

        // Breed of the other species with the old species → CHECK.
        expect($refused('pets', ['id' => $pet->id], ['breed_type' => 'maine_coon']))->toThrow(QueryException::class);
        expect($refused('pets', ['id' => $pet->id], ['species' => 'cat']))->toThrow(QueryException::class);
        expect($refused('pets', ['id' => $pet->id], ['species' => 'bird']))->toThrow(QueryException::class);
        expect($refused('pets', ['id' => $pet->id], ['breed_type' => 'persian', 'species' => 'cat']))->toThrow(QueryException::class);
        expect($refused('breed_configs', ['breed_slug' => 'mutt'], ['species' => 'bird']))->toThrow(QueryException::class);
        expect($refused('breed_configs', ['breed_slug' => 'mutt'], ['search_keywords' => '{"a": 1}']))->toThrow(QueryException::class);

        DB::table('pets')->where('id', $pet->id)->update(['breed_type' => 'maine_coon', 'species' => 'cat']);
        expect(Pet::findOrFail($pet->id)->species)->toBe(Species::Cat);
    });

    it('derives the species from the breed in the model (creation and an admin breed change)', function () {
        $child = User::factory()->child()->create();
        $dog = Pet::factory()->create(['user_id' => $child->id]);
        $cat = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'domestic_cat']);

        expect($dog->species)->toBe(Species::Dog)
            ->and($dog->fresh()->species)->toBe(Species::Dog)
            ->and($cat->species)->toBe(Species::Cat)
            ->and($cat->fresh()->species)->toBe(Species::Cat);

        $cat->update(['breed_type' => BreedType::MaineCoon]);
        expect($cat->fresh()->species)->toBe(Species::Cat);
        $dog->update(['breed_type' => BreedType::MaineCoon]);
        expect($dog->fresh()->species)->toBe(Species::Cat);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('breed configs: cat rows and the single free / paid source', function () {
    it('seeds the cat rows (CAT_SPEC Q5 / Q6 / Q9)', function () {
        $cat = BreedConfig::where('breed_slug', 'domestic-cat')->firstOrFail();
        $coon = BreedConfig::where('breed_slug', 'maine-coon')->firstOrFail();

        foreach ([$cat, $coon] as $row) {
            expect([$row->species, $row->hunger_decay_rate, $row->thirst_decay_rate, $row->water_times_per_day, $row->water_min_gap_minutes, $row->poops_per_day, $row->daily_steps_required])
                ->toBe([Species::Cat, 8.0, 8.0, 2, 240, 0, 0]);
        }
        expect([$cat->premium_unlock, $cat->sort_order, $cat->label_key, $cat->search_keywords])
            ->toBe([false, 0, 'breeds.domestic_cat', ['domača mačka', 'domaca macka', 'mešanka', 'mesanka', 'domestic cat', 'moggy']])
            ->and([$coon->premium_unlock, $coon->sort_order, $coon->label_key, $coon->search_keywords])
            ->toBe([true, 10, 'breeds.maine_coon', ['maine coon', 'mejnkun', 'mainska']]);
    });

    it('keeps the enum fallback equal to the seeder and gives every species exactly one free breed', function () {
        $configs = collect(BreedConfigsSeeder::configs())->keyBy('breed_slug');

        foreach (BreedType::cases() as $breed) {
            $row = $configs->get($breed->slug());
            expect($row)->not->toBeNull()
                ->and($row['premium_unlock'])->toBe($breed->defaultPremium())
                ->and($row['species'])->toBe($breed->species()->value);
        }
        foreach (Species::cases() as $species) {
            expect($configs->where('species', $species->value)->where('premium_unlock', false)->count())->toBe(1);
        }
        expect(Species::Dog->freeBreed())->toBe(BreedType::Mutt)
            ->and(Species::Cat->freeBreed())->toBe(BreedType::DomesticCat);
    });

    it('reads premium from breed_configs (an admin change applies at once)', function () {
        expect(BreedType::Mutt->isPremium())->toBeFalse()
            ->and(BreedType::BorderCollie->isPremium())->toBeTrue()
            ->and(BreedType::DomesticCat->isPremium())->toBeFalse()
            ->and(BreedType::MaineCoon->isPremium())->toBeTrue();

        BreedConfig::where('breed_slug', 'border-collie')->firstOrFail()->update(['premium_unlock' => false]);

        expect(BreedType::BorderCollie->isPremium())->toBeFalse()
            ->and(PairingService::defaultPlanFor(BreedType::BorderCollie))->toBe(PetPlan::Free)
            ->and(PairingService::breedAllowed(BreedType::BorderCollie, PetPlan::Free))->toBeTrue();
    });

    it('falls back to the enum defaults without breed configs', function () {
        DB::table('breed_configs')->delete();
        BreedCatalogService::forget();

        expect(BreedType::Mutt->isPremium())->toBeFalse()
            ->and(BreedType::MaineCoon->isPremium())->toBeTrue()
            ->and(Species::Cat->freeBreed())->toBe(BreedType::DomesticCat)
            ->and(PairingService::breedAllowed(BreedType::Mutt, PetPlan::Free))->toBeFalse(); // no config → not allowed (unchanged)
    });

    it('gives a cat created anywhere the plan of its breed (Pet::creating)', function () {
        $noPlan = ['plan' => null, 'challenge_paid_at' => null, 'challenge_paid_source' => null];
        $cat = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'domestic_cat'] + $noPlan);
        $coon = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'maine_coon'] + $noPlan);
        // Factory default = a grandfathered paid challenge (like the M3-11 backfill).
        $grandfatheredCat = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'domestic_cat']);
        $grandfatheredCoon = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'maine_coon']);
        $unpaidCat = Pet::factory()->trial()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'domestic_cat']);
        $unpaidCoon = Pet::factory()->trial()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'maine_coon']);

        expect($cat->plan)->toBe(PetPlan::Free)
            ->and($coon->plan)->toBe(PetPlan::Challenge)
            ->and($unpaidCat->plan)->toBe(PetPlan::Free)
            ->and($unpaidCoon->plan)->toBe(PetPlan::Challenge)
            ->and($unpaidCoon->challenge_paid_at)->toBeNull()
            ->and(PetPlanPayload::displaysAsFree($grandfatheredCat))->toBeTrue()
            ->and(PetPlanPayload::displaysAsFree($grandfatheredCoon))->toBeFalse();
    });

    it('refuses legacy DNA v1 for a cat', function () {
        expect(fn () => app(FalAiService::class)->generateInitialPetDna(BreedType::DomesticCat))
            ->toThrow(InvalidArgumentException::class);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('generate-pin: species validation and hidden cats', function () {
    it('keeps old app builds on dogs: no species → dog, breed default mutt', function () {
        $parent = User::factory()->parent()->create();

        spPin($parent, ['origin' => 'bought', 'age_stage' => 'puppy'])->assertOk()
            ->assertJsonPath('pet_profile.species', 'dog')
            ->assertJsonPath('pet_profile.breed', 'mutt')
            ->assertJsonPath('plan', 'free');
        spPin($parent, [])->assertOk()->assertJsonPath('pet_profile', null)->assertJsonPath('plan', 'free');
        spPin($parent, ['species' => 'dog'])->assertOk()->assertJsonPath('pet_profile', null);
    });

    it('refuses a breed of another species', function (array $body) {
        spCatsOn();
        $parent = User::factory()->parent()->create();

        spPin($parent, array_merge(['origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['species_cat']], $body))
            ->assertStatus(422)->assertJsonPath('reason', 'breed_species_mismatch');
    })->with([
        'cat + mutt' => [['species' => 'cat', 'breed' => 'mutt']],
        'cat + border collie' => [['species' => 'cat', 'breed' => 'border_collie', 'plan' => 'challenge']],
        'dog + maine coon' => [['species' => 'dog', 'breed' => 'maine_coon', 'plan' => 'challenge']],
        'dog + domestic cat' => [['species' => 'dog', 'breed' => 'domestic_cat']],
    ]);

    it('hides cats while the server flag is off', function () {
        $parent = User::factory()->parent()->create();

        spCatPin($parent)->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
        // A cat breed without `species` is a cat too.
        spPin($parent, ['breed' => 'maine_coon', 'origin' => 'bought', 'age_stage' => 'puppy', 'plan' => 'challenge', 'features' => ['species_cat']])
            ->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
        expect(ChildLoginPin::count())->toBe(0);
    });

    it('hides cats from a parent app without species_cat even with the flag on', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();

        spCatPin($parent, ['features' => ['behaviour_events', 'training']])
            ->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
    });

    it('needs a full profile for a cat and refuses species when joining', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();

        spPin($parent, ['species' => 'cat', 'features' => ['species_cat']])
            ->assertStatus(422)->assertJsonValidationErrors(['origin', 'age_stage']);
        spPin($parent, ['species' => 'cat', 'pet_id' => 123])->assertStatus(422)->assertJsonValidationErrors(['species']);
        spPin($parent, ['species' => 'fish'])->assertStatus(422)->assertJsonValidationErrors(['species']);
    });

    it('stores the cat choice on the PIN when cats are available', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();

        spCatPin($parent, ['breed' => null])->assertOk()
            ->assertJsonPath('pet_profile.species', 'cat')
            ->assertJsonPath('pet_profile.breed', 'domestic_cat') // no breed → free breed of the species
            ->assertJsonPath('pet_profile.features', ['species_cat'])
            ->assertJsonPath('plan', 'free');
        expect(ChildLoginPin::latest('id')->firstOrFail()->pet_options['species'])->toBe('cat');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('free / paid matrix: species × plan × app generation', function () {
    it('applies one rule to dogs and cats', function (string $species, ?string $plan, string $breed, ?string $reason, ?string $petPlan) {
        spCatsOn();
        $parent = User::factory()->parent()->create();
        $body = ['species' => $species, 'breed' => $breed, 'origin' => 'bought', 'age_stage' => 'puppy', 'features' => ['species_cat']];
        if ($plan !== null) {
            $body['plan'] = $plan;
        }

        $response = spPin($parent, $body);

        if ($reason !== null) {
            $response->assertStatus(422)->assertJsonPath('reason', $reason);

            return;
        }

        $response->assertOk()->assertJsonPath('plan', $petPlan);
        $pet = Pet::findOrFail(spLogin($response->json('pin'), ['species_cat'])->assertOk()->json('pet.id'));
        expect($pet->plan->value)->toBe($petPlan)
            ->and($pet->breed_type->value)->toBe($breed)
            ->and($pet->species->value)->toBe($species);
    })->with([
        // species, plan (null = old app), breed, 422 reason, resulting plan
        'dog free + mutt' => ['dog', 'free', 'mutt', null, 'free'],
        'dog free + collie' => ['dog', 'free', 'border_collie', 'breed_locked', null],
        'dog challenge + mutt' => ['dog', 'challenge', 'mutt', 'challenge_requires_paid_breed', null],
        'dog challenge + collie' => ['dog', 'challenge', 'border_collie', null, 'challenge'],
        'dog old app + mutt' => ['dog', null, 'mutt', null, 'free'],
        'dog old app + collie' => ['dog', null, 'border_collie', null, 'challenge'],
        'cat free + domestic' => ['cat', 'free', 'domestic_cat', null, 'free'],
        'cat free + maine coon' => ['cat', 'free', 'maine_coon', 'breed_locked', null],
        'cat challenge + domestic' => ['cat', 'challenge', 'domestic_cat', 'challenge_requires_paid_breed', null],
        'cat challenge + maine coon' => ['cat', 'challenge', 'maine_coon', null, 'challenge'],
        'cat old app + domestic' => ['cat', null, 'domestic_cat', null, 'free'],
        'cat old app + maine coon' => ['cat', null, 'maine_coon', null, 'challenge'],
    ]);

    it('keeps the Maine Coon challenge payment-required until bought (no trial)', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();
        $pin = spCatPin($parent, ['breed' => 'maine_coon', 'plan' => 'challenge', 'age_stage' => 'puppy', 'origin' => 'bought'])->assertOk()->json('pin');

        $pet = Pet::findOrFail(spLogin($pin, ['species_cat'])->assertOk()->json('pet.id'));

        expect($pet->challengeStatus())->toBe(ChallengeStatus::PaymentRequired);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('pin-login: the child app must show cats', function () {
    it('refuses a cat PIN on a child app without species_cat and keeps the PIN usable', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();
        $pin = spCatPin($parent)->assertOk()->json('pin');

        spLogin($pin, ['behaviour_events', 'training'])->assertStatus(422)->assertJsonPath('reason', 'app_update_required');
        expect(Pet::count())->toBe(0);

        $login = spLogin($pin, ['species_cat'])->assertOk()
            ->assertJsonPath('pet.species', 'cat')
            ->assertJsonPath('pet.breed_type', 'domestic_cat')
            ->assertJsonPath('pet.media_status', 'disabled');
        $pet = Pet::findOrFail($login->json('pet.id'));

        // No cat media / DNA before M5-R06-07 (never a dog prompt).
        expect($pet->species)->toBe(Species::Cat)
            ->and($pet->pet_dna)->toBeNull()
            ->and($pet->plan)->toBe(PetPlan::Free);
    });

    it('revokes a new-cat PIN when cats were switched off after it was issued', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();
        $pin = spCatPin($parent)->assertOk()->json('pin');
        config(['petprep.cats_enabled' => false]);

        spLogin($pin, ['species_cat'])->assertStatus(422)->assertJsonPath('reason', 'pin_not_usable');
        expect(Pet::count())->toBe(0);
    });

    it('refuses a re-login to a cat from an old child app, but not to a dog', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();
        $child = app(ChildProfileService::class)->createChild($parent, 'Ana', null);
        spLogin(spCatPin($parent, [], $child)->json('pin'), ['species_cat'])->assertOk();

        $relogin = spPin($parent, [], $child)->assertOk()->assertJsonPath('mode', 'relogin')->json('pin');
        spLogin($relogin, [])->assertStatus(422)->assertJsonPath('reason', 'app_update_required');
        spLogin($relogin, ['species_cat'])->assertOk()->assertJsonPath('mode', 'relogin');

        // A dog family with an old child app is unchanged.
        $dogParent = User::factory()->parent()->create();
        spLogin(spPin($dogParent, ['origin' => 'bought', 'age_stage' => 'puppy'])->json('pin'), [])
            ->assertOk()->assertJsonPath('pet.species', 'dog');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('GET /api/breeds', function () {
    it('lists only dogs by default (cats hidden), free breed first', function () {
        $parent = User::factory()->parent()->create();

        spCatalogue($parent)->assertOk()
            ->assertJsonPath('species', ['dog'])
            ->assertJsonPath('breeds.0', [
                'breed' => 'mutt', 'slug' => 'mutt', 'species' => 'dog', 'premium' => false,
                'free_plan_allowed' => true, 'challenge_allowed' => false, 'label_key' => 'breeds.mutt',
                'search_keywords' => ['mešanček', 'mesancek', 'mutt', 'mixed'], 'sort_order' => 0,
            ])
            ->assertJsonPath('breeds.1.breed', 'border_collie')
            ->assertJsonPath('breeds.1.premium', true)
            ->assertJsonPath('breeds.1.challenge_allowed', true)
            ->assertJsonCount(2, 'breeds');

        // Flag off: the cat feature alone changes nothing.
        spCatalogue($parent, ['features' => ['species_cat']])->assertOk()->assertJsonPath('species', ['dog'])->assertJsonCount(2, 'breeds');
        spCatalogue($parent, ['species' => 'cat', 'features' => ['species_cat']])->assertOk()->assertJsonCount(0, 'breeds');
    });

    it('lists cats only for an app that declares species_cat while the flag is on', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create();

        spCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog'])->assertJsonCount(2, 'breeds');

        $all = spCatalogue($parent, ['features' => ['species_cat', 'unknown_future']])->assertOk()
            ->assertJsonPath('species', ['dog', 'cat']);
        expect(array_column($all->json('breeds'), 'breed'))->toBe(['mutt', 'border_collie', 'domestic_cat', 'maine_coon']);

        $cats = spCatalogue($parent, ['species' => 'cat', 'features' => ['species_cat']])->assertOk();
        expect(array_column($cats->json('breeds'), 'breed'))->toBe(['domestic_cat', 'maine_coon'])
            ->and($cats->json('breeds.1'))->toMatchArray(['premium' => true, 'free_plan_allowed' => false, 'challenge_allowed' => true, 'label_key' => 'breeds.maine_coon', 'search_keywords' => ['maine coon', 'mejnkun', 'mainska']]);
    });

    it('orders paid breeds by sort_order and follows admin edits; ignores slugs the app does not know', function () {
        BreedConfig::create(['breed_slug' => 'labrador', 'daily_steps_required' => 8000, 'hunger_decay_rate' => 8, 'premium_unlock' => true, 'sort_order' => 1]);
        BreedConfig::where('breed_slug', 'border-collie')->firstOrFail()->update(['premium_unlock' => false, 'sort_order' => 5]);
        $parent = User::factory()->parent()->create();

        $breeds = spCatalogue($parent, ['species' => 'dog'])->assertOk()->json('breeds');

        expect(array_column($breeds, 'breed'))->toBe(['mutt', 'border_collie'])
            ->and($breeds[1]['premium'])->toBeFalse()
            ->and($breeds[1]['free_plan_allowed'])->toBeTrue();
    });

    it('is for parents only and validates the query', function () {
        $child = User::factory()->child()->create();
        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/breeds')->assertForbidden();

        $parent = User::factory()->parent()->create();
        spCatalogue($parent, ['species' => 'fish'])->assertStatus(422)->assertJsonValidationErrors(['species']);
        app('auth')->forgetGuards();
        test()->withHeaders(['Authorization' => '']);
        getJson('/api/breeds')->assertUnauthorized();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('payloads carry species', function () {
    it('adds species to the child state, dashboard, broadcast and export', function () {
        spCatsOn();
        $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
        $child = app(ChildProfileService::class)->createChild($parent, 'Ana', null);
        $login = spLogin(spCatPin($parent, [], $child)->json('pin'), ['species_cat'])->assertOk();
        $pet = Pet::findOrFail($login->json('pet.id'));

        app('auth')->forgetGuards();
        actingAsRole($child);
        getJson('/api/child/pet')->assertOk()->assertJsonPath('pet.species', 'cat');

        app('auth')->forgetGuards();
        actingAsRole($parent);
        $dashboard = getJson('/api/parent/dashboard')->assertOk();
        expect($dashboard->json('pet.species'))->toBe('cat')
            ->and(collect($dashboard->json('family.pets'))->firstWhere('id', $pet->id)['species'])->toBe('cat');

        $exported = collect(getJson('/api/parent/account/export')->assertOk()->json('pets'))->firstWhere('id', $pet->id);
        expect($exported['species'])->toBe('cat')
            ->and(PetUpdated::fromPet($pet, 'fed_pet')->broadcastWith()['species'])->toBe('cat');

        $dog = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id]);
        expect(PetUpdated::fromPet($dog, 'fed_pet')->broadcastWith()['species'])->toBe('dog');
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('Filament: species filters', function () {
    beforeEach(function () {
        actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
    });

    it('filters breed configs and pets by species', function () {
        $dogs = BreedConfig::whereIn('breed_slug', ['mutt', 'border-collie'])->get();
        $cats = BreedConfig::whereIn('breed_slug', ['domestic-cat', 'maine-coon'])->get();

        Livewire::test(ListBreedConfigs::class)
            ->filterTable('species', 'cat')
            ->assertCanSeeTableRecords($cats)
            ->assertCanNotSeeTableRecords($dogs);

        $dog = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id]);
        $cat = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'breed_type' => 'maine_coon']);

        Livewire::test(ListPets::class)
            ->filterTable('species', 'cat')
            ->assertCanSeeTableRecords([$cat])
            ->assertCanNotSeeTableRecords([$dog]);
    });

    it('keeps an enum breed\'s species when an admin saves the catalogue fields', function () {
        $coon = BreedConfig::where('breed_slug', 'maine-coon')->firstOrFail();

        Livewire::test(EditBreedConfig::class, ['record' => $coon->getRouteKey()])
            ->assertFormSet(['species' => 'cat', 'sort_order' => 10, 'label_key' => 'breeds.maine_coon'])
            ->fillForm(['species' => 'dog', 'sort_order' => 20, 'search_keywords' => ['maine coon', 'mejn kun']])
            ->call('save')
            ->assertHasNoFormErrors();

        $coon->refresh();
        expect($coon->species)->toBe(Species::Cat)
            ->and($coon->sort_order)->toBe(20)
            ->and($coon->search_keywords)->toBe(['maine coon', 'mejn kun']);
    });
});
