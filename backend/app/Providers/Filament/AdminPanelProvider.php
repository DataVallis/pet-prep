<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Admin\SwitchAdminLocaleController;
use App\Http\Middleware\SetAdminLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('PetPrep Admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            // App\Filament\Pages\Dashboard replaces Filament's default dashboard; the
            // default Welcome / Filament info widgets are gone (David 2026-10-11).
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // EN + SL (David 2026-10-11): switcher in the top bar and the user menu.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => view('filament.components.locale-switcher')->render())
            ->userMenuItems([
                'locale' => MenuItem::make()
                    ->label(fn (): string => __('admin.locale.switch_to'))
                    ->icon('heroicon-o-language')
                    ->url(fn (): string => route('filament.admin.locale', ['locale' => app()->getLocale() === 'sl' ? 'en' : 'sl'])),
            ])
            ->authenticatedRoutes(function (): void {
                Route::get('locale/{locale}', SwitchAdminLocaleController::class)->name('locale');
            })
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Before DispatchServingFilamentEvent, so navigation is built in the right language.
                SetAdminLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Again as persistent middleware: Livewire updates (polling widgets, form
            // saves) skip the list above, and a re-render must not fall back to English.
            ->middleware([SetAdminLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('web')
            // M5-T15: AI budget / fal balance warnings land in the bell (notifications table).
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s');
    }
}
