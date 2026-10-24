<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Models\UserSocialAccount;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
| Filament UserResource — e-mail stored lower case and unique ignoring case
| (M2-10a / PR #25, same rule as POST /api/register).
*/

beforeEach(function () {
    actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true, 'email' => 'admin@example.com']));
});

it('stores an edited e-mail trimmed and lower case', function () {
    $parent = User::factory()->create(['role' => 'parent', 'email' => 'old@example.com']);

    Livewire::test(EditUser::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['email' => 'New.Parent@Example.COM'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($parent->fresh()->email)->toBe('new.parent@example.com');
});

it('refuses an e-mail another account uses in a different letter case', function () {
    User::factory()->create(['role' => 'parent', 'email' => 'taken@example.com']);
    $parent = User::factory()->create(['role' => 'parent', 'email' => 'mine@example.com']);

    Livewire::test(EditUser::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['email' => 'Taken@Example.com'])
        ->call('save')
        ->assertHasFormErrors(['email']);

    expect($parent->fresh()->email)->toBe('mine@example.com');
});

it('lets a record keep its own e-mail in another letter case', function () {
    $parent = User::factory()->create(['role' => 'parent', 'email' => 'me@example.com']);

    Livewire::test(EditUser::class, ['record' => $parent->getRouteKey()])
        ->fillForm(['email' => 'ME@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($parent->fresh()->email)->toBe('me@example.com');
});

it('M2-10c: a parent with a linked Google / Apple identity may have no e-mail; other parents still need one', function () {
    $social = User::factory()->create(['role' => 'parent', 'email' => 'social@example.com', 'password' => null]);
    UserSocialAccount::create(['user_id' => $social->id, 'provider' => 'google', 'subject' => 'g-1']);

    Livewire::test(EditUser::class, ['record' => $social->getRouteKey()])
        ->fillForm(['email' => null])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($social->fresh()->email)->toBeNull();

    $plain = User::factory()->create(['role' => 'parent', 'email' => 'plain@example.com']);
    Livewire::test(EditUser::class, ['record' => $plain->getRouteKey()])
        ->fillForm(['email' => null])
        ->call('save')
        ->assertHasFormErrors(['email' => 'required']);
    expect($plain->fresh()->email)->toBe('plain@example.com');
});

it('M5-R14 – R20 follow-up: shows the app features of the latest request read-only, and saving keeps them', function () {
    $parent = User::factory()->create(['role' => 'parent', 'email' => 'apps@example.com']);
    $parent->forceFill(['client_features' => ['training', 'care_tasks'], 'client_features_at' => '2026-10-24 08:15:00'])->saveQuietly();

    Livewire::test(EditUser::class, ['record' => $parent->getRouteKey()])
        ->assertSee('App features (latest request)')
        ->assertSee('training, care_tasks')
        ->assertSee('2026-10-24 08:15:00')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($parent->fresh()->client_features)->toBe(['training', 'care_tasks']);
});
