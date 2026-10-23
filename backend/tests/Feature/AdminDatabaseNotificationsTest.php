<?php

use App\Models\User;
use App\Notifications\AiBudgetAlertNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Hotfix 2026-10-23 — /admin 500 from the Filament bell
|--------------------------------------------------------------------------
|
| Filament's DatabaseNotifications component filters on
| `data->format = 'filament'` (a JSON operator). With `notifications.data`
| as `text` PostgreSQL has no `text ->> unknown` operator and every /admin
| page load failed. `data` is now `jsonb`.
|
*/

it('renders the admin bell with a stored AI budget notification and its unread count', function () {
    $admin = User::factory()->create(['role' => 'parent', 'is_superadmin' => true]);
    $admin->notifyNow(AiBudgetAlertNotification::falBalance('low', 4.5, 10.0), ['database']);

    expect($admin->notifications()->count())->toBe(1);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $component = Livewire::actingAs($admin)->test(DatabaseNotifications::class);

    $component->assertOk();
    expect($component->instance()->getUnreadNotificationsCount())->toBe(1)
        ->and($component->instance()->getNotifications()->count())->toBe(1);
    $component->assertSee(AiBudgetAlertNotification::falBalance('low', 4.5, 10.0)->title());
});
