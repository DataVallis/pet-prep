<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
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
