<?php

use App\Enums\Phase2PriceChoice;
use App\Enums\ValidationSignalsMode;
use App\Filament\Pages\FeatureSwitches;
use App\Http\Middleware\SetAdminLocale;
use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\EmailContact;
use App\Models\ParentDashboardOpen;
use App\Models\Phase2Interest;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\Validation\ValidationSignals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
|--------------------------------------------------------------------------
| Admin switch »Validation signals« (David 2026-11-22)
|--------------------------------------------------------------------------
|
| Tested in production behind /admin → Funkcije, default off; »Vsi« only
| after the privacy policy is updated. Modes off | test_families | everyone
| (same pattern as the cats switch). While off for a family the server
| stores nothing new; a consent withdrawal always works; stored data stay.
|
*/

function vssAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

function vssAs(User $user): void
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => '']);
    actingAsRole($user);
}

/** @param list<int> $ids */
function vssSet(ValidationSignalsMode $mode, array $ids = []): void
{
    app(AppSettingsService::class)->updateValidationSignals(vssAdmin(), $mode, $ids);
}

/** @param array<string, mixed> $overrides */
function vssRegister(array $overrides = [], string $ip = '198.51.100.160'): TestResponse
{
    app('auth')->forgetGuards();
    test()->withHeaders(['Authorization' => ''])->withServerVariables(['REMOTE_ADDR' => $ip]);

    return postJson('/api/register', array_merge([
        'name' => 'Mama Ana', 'email' => 'ana@example.com', 'password' => 'Varno1Geslo',
        'password_confirmation' => 'Varno1Geslo', 'timezone' => 'Europe/Ljubljana', 'accept_terms' => true,
        'acquisition_source' => 'ad', 'acquisition_detail' => 'FB-1',
    ], $overrides));
}

/**
 * Every write endpoint once for this parent; returns what was stored.
 *
 * @return array{acquisition: string|null, opens: int, phase2: bool, phase2Status: int}
 */
function vssWriteAll(User $parent): array
{
    vssAs($parent);
    patchJson('/api/parent/settings', ['acquisition_source' => 'instagram'])->assertOk();
    postJson('/api/parent/dashboard-opened', ['trigger' => 'launch'])->assertNoContent();
    postJson('/api/parent/phase2-interest/viewed')->assertNoContent();
    $status = postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'p'.$parent->id.'@example.com'])->status();

    return [
        'acquisition' => $parent->fresh()->acquisition_source,
        'opens' => ParentDashboardOpen::where('user_id', $parent->id)->count(),
        'phase2' => Phase2Interest::where('user_id', $parent->id)->exists(),
        'phase2Status' => $status,
    ];
}

describe('modes × endpoints', function () {
    it('off (default, no row): nothing is stored, the app is told false everywhere', function () {
        $parent = User::factory()->parent()->create();
        expect(AppSetting::count())->toBe(0)
            ->and(app(AppSettingsService::class)->validationSignalsMode())->toBe(ValidationSignalsMode::Off);

        expect(vssWriteAll($parent))->toBe(['acquisition' => null, 'opens' => 0, 'phase2' => false, 'phase2Status' => 422]);

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => false])
            ->assertUnprocessable()->assertExactJson(['message' => 'This feature is not available.', 'code' => 'feature_unavailable']);
        getJson('/api/user')->assertOk()->assertJsonPath('validation_signals', false);
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('validation_signals', false)
            ->assertJsonPath('phase2_interest', ['submitted' => false, 'notify' => false, 'price_choice' => null]);
        expect(EmailContact::count())->toBe(0);

        vssRegister()->assertCreated();
        expect(User::where('email', 'ana@example.com')->value('acquisition_source'))->toBeNull();
        getJson('/api/app-features')->assertOk()->assertExactJson(['validation_signals_signup' => false]);
    });

    it('test families: only listed parents\' families record; sign-up never stores the answer', function () {
        $tester = User::factory()->parent()->create();
        $other = User::factory()->parent()->create();
        vssSet(ValidationSignalsMode::TestFamilies, [$tester->id]);

        expect(vssWriteAll($tester))->toBe(['acquisition' => 'instagram', 'opens' => 1, 'phase2' => true, 'phase2Status' => 200])
            ->and(vssWriteAll($other))->toBe(['acquisition' => null, 'opens' => 0, 'phase2' => false, 'phase2Status' => 422]);

        vssAs($tester);
        getJson('/api/user')->assertOk()->assertJsonPath('validation_signals', true);
        getJson('/api/parent/dashboard')->assertOk()->assertJsonPath('validation_signals', true);
        vssAs($other);
        getJson('/api/user')->assertOk()->assertJsonPath('validation_signals', false);

        // A brand-new account is never on the list: the sign-up answer is ignored, the picker stays hidden.
        vssRegister()->assertCreated();
        expect(User::where('email', 'ana@example.com')->value('acquisition_source'))->toBeNull();
        getJson('/api/app-features')->assertOk()->assertJsonPath('validation_signals_signup', false);
    });

    it('test families: a second parent of a listed family is on too; a child never is', function () {
        $tester = User::factory()->parent()->create();
        $child = User::factory()->child()->create(['parent_id' => $tester->id]);
        vssSet(ValidationSignalsMode::TestFamilies, [$tester->id]);

        expect(app(ValidationSignals::class)->enabledFor($tester))->toBeTrue()
            ->and(app(ValidationSignals::class)->enabledFor($tester->family))->toBeTrue()
            ->and(app(ValidationSignals::class)->enabledFor($child))->toBeFalse()
            ->and(app(ValidationSignals::class)->enabledFor(null))->toBeFalse();

        vssAs($child);
        getJson('/api/user')->assertOk()->assertJsonPath('validation_signals', false);
    });

    it('everyone: every parent records and the sign-up stores the answer', function () {
        vssSet(ValidationSignalsMode::Everyone);
        $parent = User::factory()->parent()->create();

        expect(vssWriteAll($parent))->toBe(['acquisition' => 'instagram', 'opens' => 1, 'phase2' => true, 'phase2Status' => 200]);

        getJson('/api/app-features')->assertOk()->assertExactJson(['validation_signals_signup' => true]);
        vssRegister()->assertCreated();
        expect(User::where('email', 'ana@example.com')->value('acquisition_source'))->toBe('ad');
    });
});

describe('withdrawal and switching off', function () {
    it('lets a parent withdraw »Obvestite me« while the switch is off; the price stays', function () {
        $parent = User::factory()->parent()->create();
        vssSet(ValidationSignalsMode::TestFamilies, [$parent->id]);
        vssAs($parent);
        postJson('/api/parent/phase2-interest', ['price_choice' => 'monthly_2_99', 'notify' => true, 'email' => 'mama@example.com'])->assertOk();
        expect(EmailContact::count())->toBe(1);

        vssSet(ValidationSignalsMode::Off);
        vssAs($parent);

        // A new answer is refused …
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => true, 'email' => 'mama@example.com'])
            ->assertUnprocessable()->assertJsonPath('code', 'feature_unavailable');
        // … the withdrawal always works, also with a price sent along (ignored).
        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => false])->assertOk()->assertExactJson(['status' => 'saved']);

        $row = Phase2Interest::sole();
        expect($row->notify)->toBeFalse()
            ->and($row->price_choice)->toBe(Phase2PriceChoice::Monthly299)
            ->and(EmailContact::count())->toBe(0);
        getJson('/api/parent/dashboard')->assertOk()
            ->assertJsonPath('validation_signals', false)
            ->assertJsonPath('phase2_interest', ['submitted' => true, 'notify' => false, 'price_choice' => 'monthly_2_99']);
    });

    it('keeps the stored data when switched off and stops new writes', function () {
        $parent = User::factory()->parent()->create();
        vssSet(ValidationSignalsMode::Everyone);
        vssWriteAll($parent);

        vssSet(ValidationSignalsMode::Off);
        $this->travel(2)->hours();
        vssAs($parent);
        postJson('/api/parent/dashboard-opened')->assertNoContent();
        postJson('/api/parent/phase2-interest/viewed')->assertNoContent();

        expect($parent->fresh()->acquisition_source)->toBe('instagram')
            ->and(ParentDashboardOpen::where('user_id', $parent->id)->count())->toBe(1)
            ->and(Phase2Interest::where('user_id', $parent->id)->value('notify'))->toBeTrue();
    });

    it('a child token on the Phase-2 endpoint still gets 403, not feature_unavailable', function () {
        $parent = User::factory()->parent()->create();
        vssAs(User::factory()->child()->create(['parent_id' => $parent->id]));

        postJson('/api/parent/phase2-interest', ['price_choice' => 'free', 'notify' => false])->assertForbidden();
    });
});

describe('admin page, audit and cache', function () {
    it('saves the mode with test parents from /admin → Funkcije and audits it', function () {
        $admin = vssAdmin();
        $tester = User::factory()->parent()->create(['email' => 'tester@example.com']);
        $this->actingAs($admin);

        Livewire::test(FeatureSwitches::class)
            ->assertFormSet(['validation_signals_mode' => 'off', 'validation_signals_test_parent_ids' => []])
            ->fillForm(['validation_signals_mode' => 'test_families', 'validation_signals_test_parent_ids' => [(string) $tester->id]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        expect(app(AppSettingsService::class)->storedValidationSignals())
            ->toBe(['mode' => ValidationSignalsMode::TestFamilies, 'test_parent_ids' => [$tester->id]]);
        $change = AppSettingChange::sole();
        expect($change->key)->toBe(AppSettingsService::VALIDATION_SIGNALS_KEY)
            ->and($change->user_id)->toBe($admin->id)
            ->and($change->old)->toBeNull()
            ->and($change->new)->toEqual(['mode' => 'test_families', 'test_parent_ids' => [$tester->id]]);

        // Saving again unchanged writes no second audit row.
        Livewire::test(FeatureSwitches::class)->call('save')->assertHasNoFormErrors();
        expect(AppSettingChange::count())->toBe(1);

        $this->get('/admin/feature-switches')->assertOk()->assertSee('Validation signals');
        $this->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin/feature-switches')->assertOk()->assertSee('Validacijski signali');
    });

    it('requires a test parent for test families and refuses non-parent accounts', function () {
        $this->actingAs(vssAdmin());

        Livewire::test(FeatureSwitches::class)
            ->fillForm(['validation_signals_mode' => 'test_families', 'validation_signals_test_parent_ids' => []])
            ->call('save')
            ->assertHasFormErrors(['validation_signals_test_parent_ids' => 'required']);

        $child = User::factory()->child()->create();
        expect(fn () => app(AppSettingsService::class)->updateValidationSignals(vssAdmin(), ValidationSignalsMode::TestFamilies, [$child->id]))
            ->toThrow(InvalidArgumentException::class);
        expect(AppSetting::where('key', AppSettingsService::VALIDATION_SIGNALS_KEY)->exists())->toBeFalse();
    });

    it('caches the mode and forgets the cache on save', function () {
        $parent = User::factory()->parent()->create();
        $settings = app(AppSettingsService::class);
        expect($settings->validationSignalsEnabledForFamily($parent->family))->toBeFalse(); // cached: off

        // A direct DB write is not seen until the cache expires …
        AppSetting::create(['key' => AppSettingsService::VALIDATION_SIGNALS_KEY, 'value' => ['mode' => 'everyone', 'test_parent_ids' => []]]);
        expect($settings->validationSignalsEnabledForFamily($parent->family))->toBeFalse()
            ->and(Cache::has(AppSettingsService::cacheKey(AppSettingsService::VALIDATION_SIGNALS_KEY)))->toBeTrue();

        // … a save through the service busts it at once.
        $settings->updateValidationSignals(vssAdmin(), ValidationSignalsMode::TestFamilies, [$parent->id]);
        expect(Cache::has(AppSettingsService::cacheKey(AppSettingsService::VALIDATION_SIGNALS_KEY)))->toBeFalse()
            ->and($settings->validationSignalsEnabledForFamily($parent->family))->toBeTrue();
    });
});
