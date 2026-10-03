<?php

use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Models\Pet;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Filament PetResource — precise metrics are not overwritten by rounding
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    actingAs(User::factory()->create(['role' => 'parent', 'is_superadmin' => true]));
});

it('keeps precise metrics when saving other fields', function () {
    $child = User::factory()->child()->create();
    $pet = Pet::factory()->create(['user_id' => $child->id, 'hunger_level' => 52.37, 'thirst_level' => 40.6]);

    Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
        ->assertFormSet(['hunger_level' => 52, 'thirst_level' => 41])
        ->fillForm(['escalation_level' => 1])
        ->call('save')
        ->assertHasNoFormErrors();

    $pet->refresh();
    expect($pet->escalation_level)->toBe(1);
    expect($pet->hunger_level)->toEqualWithDelta(52.37, 1e-9);
    expect($pet->thirst_level)->toEqualWithDelta(40.6, 1e-9);
});

it('saves a metric the admin actually changed', function () {
    $child = User::factory()->child()->create();
    $pet = Pet::factory()->create(['user_id' => $child->id, 'hunger_level' => 52.37]);

    Livewire::test(EditPet::class, ['record' => $pet->getRouteKey()])
        ->fillForm(['hunger_level' => 80])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($pet->fresh()->hunger_level)->toBe(80.0);
});
