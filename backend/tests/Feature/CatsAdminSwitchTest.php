<?php

use App\Enums\CatsAvailability;
use App\Enums\Species;
use App\Filament\Pages\FeatureSwitches;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\Pet;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\ChildProfileService;
use App\Services\SpeciesAvailability;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| M5-R06-09 — cats switch in the superadmin panel (David 2026-10-10)
|--------------------------------------------------------------------------
|
| app_settings `cats_availability`: off | test_families | everyone (+ test
| parent ids; a family is a test family when any of its parents is listed).
| Env PETPREP_CATS_ENABLED=true = everyone. Gates only NEW cats (catalogue,
| generate-pin, new-pet pin-login); existing cats keep working. The
| `species_cat` client feature stays required.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    seedLifeStageData();
    config(['petprep.cats_enabled' => false]);
    $this->withoutMiddleware([ThrottleRequests::class]);
});

function cswAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

/** @param list<int> $testParentIds */
function cswSet(CatsAvailability $mode, array $testParentIds = []): void
{
    app(AppSettingsService::class)->updateCats(cswAdmin(), $mode, $testParentIds);
}

/** @param array<string, mixed> $body */
function cswPin(User $parent, array $body, ?User $child = null): TestResponse
{
    $child ??= app(ChildProfileService::class)->createChild($parent, 'Kid'.random_int(100, 999), null);
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return postJson('/api/parent/generate-pin', array_merge(['child_id' => $child->id], $body));
}

function cswCatPin(User $parent, ?User $child = null): TestResponse
{
    return cswPin($parent, [
        'species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'young',
        'plan' => 'free', 'features' => ['species_cat'],
    ], $child);
}

/** @param list<string> $features */
function cswLogin(string $pin, array $features = ['species_cat']): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);

    return postJson('/api/child/pin-login', ['pin' => $pin, 'device_name' => 'Tablet', 'features' => $features]);
}

/** @param list<string> $features */
function cswCatalogue(User $parent, array $features = ['species_cat']): TestResponse
{
    app('auth')->forgetGuards();
    actingAsRole($parent);

    return getJson('/api/breeds?'.http_build_query(['features' => $features]));
}

/** A parent whose family exists (created on the first write). */
function cswParent(): User
{
    $parent = User::factory()->parent()->create();
    app(ChildProfileService::class)->createChild($parent, 'Sib'.random_int(100, 999), null);

    return $parent;
}

// ─────────────────────────────────────────────────────────────────────────
describe('modes × endpoints', function () {
    it('off (default, no row): no cat anywhere', function () {
        $parent = cswParent();
        expect(AppSetting::count())->toBe(0)
            ->and(app(AppSettingsService::class)->catsMode())->toBe(CatsAvailability::Off);

        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog']);
        cswCatPin($parent)->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
    });

    it('off after being on: catalogue hides cats, generate-pin refuses, an open new-cat PIN is revoked', function () {
        $parent = cswParent();
        cswSet(CatsAvailability::Everyone);
        $pin = cswCatPin($parent)->assertOk()->json('pin');

        cswSet(CatsAvailability::Off);

        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog']);
        cswCatPin($parent)->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
        cswLogin($pin)->assertStatus(422)->assertJsonPath('reason', 'pin_not_usable');
        expect(Pet::count())->toBe(0);
    });

    it('everyone: any family with the new app gets cats, an old app never does', function () {
        cswSet(CatsAvailability::Everyone);
        $parent = cswParent();

        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog', 'cat']);
        cswCatalogue($parent, [])->assertOk()->assertJsonPath('species', ['dog']);
        cswPin($parent, ['species' => 'cat', 'breed' => 'domestic_cat', 'origin' => 'adopted', 'age_stage' => 'young', 'plan' => 'free'])
            ->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');

        $pin = cswCatPin($parent)->assertOk()->json('pin');
        cswLogin($pin, [])->assertStatus(422)->assertJsonPath('reason', 'app_update_required');
        cswLogin($pin)->assertOk()->assertJsonPath('pet.species', 'cat');
    });

    it('test_families: only families with a listed parent', function () {
        $tester = cswParent();
        $other = cswParent();
        cswSet(CatsAvailability::TestFamilies, [$tester->id]);

        cswCatalogue($tester)->assertOk()->assertJsonPath('species', ['dog', 'cat']);
        cswCatalogue($other)->assertOk()->assertJsonPath('species', ['dog'])->assertJsonMissing(['species' => 'cat']);

        cswCatPin($other)->assertStatus(422)->assertJsonPath('reason', 'species_unavailable');
        $pin = cswCatPin($tester)->assertOk()->json('pin');
        cswLogin($pin)->assertOk()->assertJsonPath('pet.species', 'cat');

        // Dogs are unchanged for the other family.
        cswLogin(cswPin($other, ['origin' => 'bought', 'age_stage' => 'puppy'])->json('pin'), [])
            ->assertOk()->assertJsonPath('pet.species', 'dog');
    });

    it('test_families: a second parent of a test family counts too', function () {
        $tester = cswParent();
        $coParent = User::factory()->parent()->create();
        $family = SpeciesAvailability::familyOfUser($tester);
        DB::table('family_user')->where('user_id', $coParent->id)->delete();
        DB::table('family_user')->insert(['family_id' => $family->id, 'user_id' => $coParent->id, 'role' => 'parent', 'created_at' => now(), 'updated_at' => now()]);

        cswSet(CatsAvailability::TestFamilies, [$tester->id]);

        cswCatalogue($coParent)->assertOk()->assertJsonPath('species', ['dog', 'cat']);
    });

    it('test_families: the PIN family decides at login (removed from the list → PIN revoked)', function () {
        $tester = cswParent();
        cswSet(CatsAvailability::TestFamilies, [$tester->id]);
        $pin = cswCatPin($tester)->assertOk()->json('pin');

        cswSet(CatsAvailability::TestFamilies, []);
        cswLogin($pin)->assertStatus(422)->assertJsonPath('reason', 'pin_not_usable');
    });

    it('a parent without a family yet sees no cats in test_families mode', function () {
        $loner = User::factory()->parent()->create();
        DB::table('family_user')->where('user_id', $loner->id)->delete();
        cswSet(CatsAvailability::TestFamilies, [$loner->id]);

        cswCatalogue($loner)->assertOk()->assertJsonPath('species', ['dog']);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('existing cats keep working after the switch is turned off', function () {
    it('re-login, join and the child state endpoint stay available', function () {
        $parent = cswParent();
        cswSet(CatsAvailability::TestFamilies, [$parent->id]);
        $ana = app(ChildProfileService::class)->createChild($parent, 'Ana', null);
        $petId = cswLogin(cswCatPin($parent, $ana)->json('pin'))->assertOk()->json('pet.id');

        cswSet(CatsAvailability::Off);

        // Re-login to the cat.
        $relogin = cswPin($parent, [], $ana)->assertOk()->assertJsonPath('mode', 'relogin')->json('pin');
        $token = cswLogin($relogin)->assertOk()->assertJsonPath('mode', 'relogin')->assertJsonPath('pet.species', 'cat')->json('token');

        // State endpoint with the fresh token.
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/child/pet')
            ->assertOk()->assertJsonPath('pet.species', 'cat');

        // A sibling joins the existing cat.
        $bor = app(ChildProfileService::class)->createChild($parent, 'Bor', null);
        $join = cswPin($parent, ['pet_id' => $petId], $bor)->assertOk()->assertJsonPath('mode', 'join_pet')->json('pin');
        cswLogin($join)->assertOk()->assertJsonPath('pet.id', $petId);

        expect(Pet::findOrFail($petId)->species)->toBe(Species::Cat);
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('setting storage, cache and env override', function () {
    it('caches the value and busts it on save', function () {
        $parent = cswParent();
        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog']);
        expect(Cache::has(AppSettingsService::cacheKey(AppSettingsService::CATS_KEY)))->toBeTrue();

        // A raw DB write (not through the service) is hidden by the cache…
        AppSetting::create(['key' => AppSettingsService::CATS_KEY, 'value' => ['mode' => 'everyone', 'test_parent_ids' => []]]);
        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog']);

        // …until the TTL runs out.
        $this->travel(AppSettingsService::CACHE_TTL_SECONDS + 1)->seconds();
        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog', 'cat']);

        // Saving through the service takes effect at once.
        cswSet(CatsAvailability::Off);
        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog']);
    });

    it('audits every change (who, old → new) and skips a no-op save', function () {
        $admin = cswAdmin();
        $tester = cswParent();
        $service = app(AppSettingsService::class);

        $service->updateCats($admin, CatsAvailability::TestFamilies, [(string) $tester->id, $tester->id]);
        $service->updateCats($admin, CatsAvailability::TestFamilies, [$tester->id]);
        $service->updateCats($admin, CatsAvailability::Everyone, []);

        $changes = AppSettingChange::orderBy('id')->get();
        expect($changes)->toHaveCount(2)
            ->and($changes[0]->user_id)->toBe($admin->id)
            ->and($changes[0]->old)->toBeNull()
            ->and($changes[0]->new)->toBe(['mode' => 'test_families', 'test_parent_ids' => [$tester->id]])
            ->and($changes[1]->old)->toBe(['mode' => 'test_families', 'test_parent_ids' => [$tester->id]])
            ->and($changes[1]->new)->toBe(['mode' => 'everyone', 'test_parent_ids' => []])
            ->and(AppSetting::find(AppSettingsService::CATS_KEY)->updated_by)->toBe($admin->id);
    });

    it('refuses a child account as a test parent', function () {
        $child = User::factory()->child()->create();

        expect(fn () => cswSet(CatsAvailability::TestFamilies, [$child->id]))->toThrow(InvalidArgumentException::class);
        expect(AppSetting::count())->toBe(0);
    });

    it('env PETPREP_CATS_ENABLED=true overrides the stored value as everyone', function () {
        $parent = cswParent();
        cswSet(CatsAvailability::Off);
        config(['petprep.cats_enabled' => true]);

        expect(app(AppSettingsService::class)->catsMode())->toBe(CatsAvailability::Everyone);
        cswCatalogue($parent)->assertOk()->assertJsonPath('species', ['dog', 'cat']);
        cswLogin(cswCatPin($parent)->assertOk()->json('pin'))->assertOk();
    });
});

// ─────────────────────────────────────────────────────────────────────────
describe('Filament page "Funkcije"', function () {
    it('is superadmin only', function () {
        $this->actingAs(cswAdmin())->get('/admin/feature-switches')->assertOk()->assertSee('Mačke')->assertSee('Samo testne družine');
        app('auth')->forgetGuards();
        $this->actingAs(User::factory()->parent()->create())->get('/admin/feature-switches')->assertForbidden();
    });

    it('saves the mode and the picked test parents, searchable by e-mail', function () {
        $admin = cswAdmin();
        $tester = User::factory()->parent()->create(['email' => 'david.tester@example.com']);
        User::factory()->child()->create(['email' => 'david.kid@example.com']);
        $this->actingAs($admin);

        expect(FeatureSwitches::parentsMatching('DAVID'))->toBe([(string) $tester->id => "david.tester@example.com ({$tester->name}, #{$tester->id})"]);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['cats_mode' => 'off', 'cats_test_parent_ids' => []])
            ->fillForm(['cats_mode' => 'test_families', 'cats_test_parent_ids' => [(string) $tester->id]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect(app(AppSettingsService::class)->storedCats())
            ->toBe(['mode' => CatsAvailability::TestFamilies, 'test_parent_ids' => [$tester->id]]);
        expect(AppSettingChange::sole()->user_id)->toBe($admin->id);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['cats_mode' => 'test_families', 'cats_test_parent_ids' => [(string) $tester->id]])
            // History: who changed what.
            ->assertSee($admin->email)
            ->assertSee('test_families');
    });

    it('requires at least one test parent for test_families and refuses unknown modes', function () {
        $this->actingAs(cswAdmin());

        Livewire::test(FeatureSwitches::class)
            ->fillForm(['cats_mode' => 'test_families', 'cats_test_parent_ids' => []])
            ->call('save')
            ->assertHasFormErrors(['cats_test_parent_ids' => 'required']);

        Livewire::test(FeatureSwitches::class)
            ->fillForm(['cats_mode' => 'sometimes'])
            ->call('save')
            ->assertHasFormErrors(['cats_mode']);

        expect(AppSetting::count())->toBe(0);
    });

    it('refuses the save action for a non-superadmin', function () {
        $this->actingAs(User::factory()->parent()->create());

        Livewire::test(FeatureSwitches::class)->assertForbidden();
        expect(AppSetting::count())->toBe(0);
    });
});
