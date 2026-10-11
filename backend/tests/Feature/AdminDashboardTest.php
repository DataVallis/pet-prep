<?php

use App\Enums\ActivityType;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AiSpendOverview;
use App\Filament\Widgets\AttentionOverview;
use App\Filament\Widgets\CareActivityChart;
use App\Filament\Widgets\GrowthChart;
use App\Filament\Widgets\LatestPets;
use App\Filament\Widgets\LatestPurchases;
use App\Filament\Widgets\PetPlansChart;
use App\Filament\Widgets\PetsByBreedChart;
use App\Filament\Widgets\PlatformOverview;
use App\Http\Middleware\SetAdminLocale;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\User;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Superadmin dashboard + EN/SL panel language (David 2026-10-11)
|--------------------------------------------------------------------------
|
| /admin no longer shows Filament's Welcome / Filament info cards; it shows
| PetPrep widgets driven by one period filter. The panel is English by
| default; the switcher (cookie) turns it Slovenian, also on Livewire
| re-renders.
|
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-11 10:00:00', 'UTC'));
});

function adAdmin(): User
{
    return User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
}

/** A parent with a child caring for one pet (dog unless overridden). */
function adPet(array $attributes = []): Pet
{
    $parent = User::factory()->parent()->create();
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return Pet::factory()->create(array_merge(['user_id' => $child->id], $attributes));
}

/** Call a widget's protected getData() (chart data). */
function adChartData(object $widget): array
{
    $method = new ReflectionMethod($widget, 'getData');

    return $method->invoke($widget);
}

describe('dashboard page', function () {
    it('drops the Filament Welcome / info cards and registers only PetPrep widgets', function () {
        $this->actingAs(adAdmin())->get('/admin')
            ->assertOk()
            ->assertDontSee('filamentphp.com/docs')
            ->assertDontSee('github.com/filamentphp/filament');

        $widgets = collect(Filament\Facades\Filament::getWidgets())->values()->all();
        expect($widgets)->not->toContain(AccountWidget::class)
            ->and($widgets)->not->toContain(FilamentInfoWidget::class)
            ->and($widgets)->toContain(PlatformOverview::class, AttentionOverview::class, GrowthChart::class, CareActivityChart::class, PetsByBreedChart::class, PetPlansChart::class, LatestPets::class, LatestPurchases::class, AiSpendOverview::class);
    });

    it('is closed to non-superadmins', function () {
        $this->actingAs(User::factory()->parent()->create())->get('/admin')->assertForbidden();
    });

    it('offers the period filter', function () {
        $this->actingAs(adAdmin());

        Livewire::test(Dashboard::class)
            ->assertSee('Last 30 days')
            ->assertSee('Last 12 months');
    });
});

describe('widgets', function () {
    it('counts families, parents (not superadmins), children, pets by species and care actions', function () {
        $admin = adAdmin();
        $dog = adPet();
        adPet(['breed_type' => 'maine_coon', 'species' => 'cat', 'plan' => 'challenge']);
        ActivityLog::create(['pet_id' => $dog->id, 'actor_user_id' => $dog->user_id, 'activity_type' => ActivityType::FedPet->value, 'value' => 100]);
        $this->actingAs($admin);

        Livewire::test(PlatformOverview::class, ['filters' => ['period' => '7']])
            ->assertOk()
            ->assertSee('Families')
            ->assertSee('Parents')
            ->assertSee('1 dogs · 1 cats · 2 new in the period')
            ->assertSee('Care actions');
    });

    it('lists pets needing attention and the cats switch', function () {
        adPet(['pet_state' => 'sick']);
        $this->actingAs(adAdmin());

        Livewire::test(AttentionOverview::class)
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('Sick pets')
            ->assertSee('Cats switch')
            ->assertSee('Off');
    });

    it('buckets new sign-ups per day in Ljubljana time, zero-filled', function () {
        $this->actingAs(adAdmin());
        // 2026-10-10 22:30 UTC = 2026-10-11 00:30 in Ljubljana → today, not yesterday.
        User::factory()->parent()->create(['created_at' => Carbon::parse('2026-10-10 22:30:00', 'UTC')]);
        User::factory()->parent()->create(['created_at' => Carbon::parse('2026-10-09 09:00:00', 'UTC')]);
        // Before the 7-day period: not counted.
        User::factory()->parent()->create(['created_at' => Carbon::parse('2026-10-01 09:00:00', 'UTC')]);

        $widget = Livewire::test(GrowthChart::class, ['filters' => ['period' => '7']])->instance();
        $data = adChartData($widget);

        expect($data['labels'])->toHaveCount(7)
            ->and($data['labels'][6])->toBe('11. 10.')
            // The superadmin (created "now") is not a parent sign-up.
            ->and($data['datasets'][0]['data'])->toBe([0, 0, 0, 0, 1, 0, 1]);
    });

    it('falls back to 30 days for an unknown period', function () {
        $this->actingAs(adAdmin());

        $widget = Livewire::test(GrowthChart::class, ['filters' => ['period' => '9999']])->instance();

        expect(adChartData($widget)['labels'])->toHaveCount(30);
    });

    it('groups care actions and filters them by species', function () {
        $this->actingAs(adAdmin());
        $dog = adPet();
        $cat = adPet(['breed_type' => 'maine_coon', 'species' => 'cat']);
        ActivityLog::create(['pet_id' => $dog->id, 'actor_user_id' => $dog->user_id, 'activity_type' => ActivityType::FedPet->value, 'value' => 100]);
        ActivityLog::create(['pet_id' => $cat->id, 'actor_user_id' => $cat->user_id, 'activity_type' => ActivityType::PlayedWand->value, 'value' => 1]);
        ActivityLog::create(['pet_id' => $cat->id, 'actor_user_id' => null, 'activity_type' => ActivityType::PetScratched->value, 'value' => 0]);

        $all = Livewire::test(CareActivityChart::class, ['filters' => ['period' => '7']]);
        $sum = fn (array $data): array => collect($data['datasets'])->mapWithKeys(fn (array $d): array => [$d['label'] => array_sum($d['data'])])->all();

        expect($sum(adChartData($all->instance())))->toBe([
            'Food & water' => 1, 'Walks & potty' => 0, 'Cleaning & grooming' => 0, 'Play, cuddles & training' => 1, 'Incidents' => 1,
        ]);

        $cats = $all->set('filter', 'cat');
        expect($sum(adChartData($cats->instance())))->toBe([
            'Food & water' => 0, 'Walks & potty' => 0, 'Cleaning & grooming' => 0, 'Play, cuddles & training' => 1, 'Incidents' => 1,
        ]);
    });

    it('counts active pets per breed (filterable) and per plan', function () {
        $this->actingAs(adAdmin());
        adPet();
        adPet();
        adPet(['breed_type' => 'maine_coon', 'species' => 'cat', 'challenge_paid_at' => null, 'challenge_paid_source' => null, 'trial_ends_at' => now()]);
        adPet(['is_active' => false]);

        $breeds = Livewire::test(PetsByBreedChart::class);
        expect(adChartData($breeds->instance()))->toMatchArray(['labels' => ['Mutt', 'Maine Coon']])
            ->and(adChartData($breeds->instance())['datasets'][0]['data'])->toBe([2, 1])
            ->and(adChartData($breeds->set('filter', 'dog')->instance())['labels'])->toBe(['Mutt']);

        expect(adChartData(Livewire::test(PetPlansChart::class)->instance())['datasets'][0]['data'])->toBe([0, 2, 1]);
    });

    it('shows the latest pets and purchases tables', function () {
        $this->actingAs(adAdmin());
        $pet = adPet(['name' => 'Luna']);

        Livewire::test(LatestPets::class)->assertOk()->assertCanSeeTableRecords([$pet])->assertSee('Luna');
        Livewire::test(LatestPurchases::class)->assertOk()->assertSee('Latest purchases');
    });
});

describe('panel language', function () {
    // One request per test: Filament keeps the built navigation in memory for the
    // app instance (production builds it fresh on every request).
    it('is English by default', function () {
        $this->actingAs(adAdmin())->get('/admin')->assertOk()->assertSee('lang="en"', false)->assertSee('Dashboard')->assertSee('Feature switches');
    });

    it('is Slovenian with the switcher cookie, navigation included', function () {
        $this->actingAs(adAdmin())->withCookie(SetAdminLocale::COOKIE, 'sl')->get('/admin')
            ->assertOk()->assertSee('lang="sl"', false)->assertSee('Nadzorna plošča')->assertSee('Funkcije')->assertSee('Ljubljenčki');
    });

    it('stores the choice in a cookie and goes back to the admin page it came from', function () {
        $this->actingAs(adAdmin());

        $this->from('/admin/feature-switches')->get('/admin/locale/sl')
            ->assertRedirect('/admin/feature-switches')
            ->assertCookie(SetAdminLocale::COOKIE, 'sl');

        // Never back to an outside page.
        $this->from('https://evil.example/phish')->get('/admin/locale/en')->assertRedirect('/admin');
    });

    it('refuses unsupported languages and guests', function () {
        $this->actingAs(adAdmin())->get('/admin/locale/de')->assertNotFound();
        app('auth')->forgetGuards();
        auth()->logout();
        $this->get('/admin/locale/sl')->assertRedirect('/admin/login');
    });

    it('renders widgets in Slovenian', function () {
        $this->actingAs(adAdmin());
        app()->setLocale('sl');

        Livewire::test(AttentionOverview::class)->assertSee('Potrebuje pozornost')->assertSee('Izklopljeno');
        Livewire::test(PlatformOverview::class)->assertSee('Družine')->assertSee('Skrb (dejanja)');
    });

    it('resolves only supported languages', function () {
        expect(SetAdminLocale::resolve('sl'))->toBe('sl')
            ->and(SetAdminLocale::resolve('de'))->toBe('en')
            ->and(SetAdminLocale::resolve(null))->toBe('en')
            ->and(SetAdminLocale::resolve(['sl']))->toBe('en');
    });
});
