<?php

use App\Events\PetUpdated;
use App\Filament\Resources\PetResource;
use App\Filament\Resources\PetResource\Pages\EditPet;
use App\Filament\Resources\PetResource\Pages\ListPets;
use App\Models\Pet;
use App\Models\User;
use App\Services\PetNameService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

/*
|--------------------------------------------------------------------------
| Pet name: read-only + "Clear name" (M5-R08, David 2026-10-09)
|--------------------------------------------------------------------------
*/

describe('pet name in the admin', function () {
    beforeEach(function () {
        $this->namedPet = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'name' => 'Pika']);
    });

    it('shows the name read-only: in the table and disabled in the form, never saved by the form', function () {
        Livewire::test(ListPets::class)
            ->assertCanSeeTableRecords([$this->namedPet])
            ->assertTableColumnStateSet('name', 'Pika', $this->namedPet);

        Livewire::test(EditPet::class, ['record' => $this->namedPet->getRouteKey()])
            ->assertFormSet(['name' => 'Pika'])
            ->assertFormFieldIsDisabled('name')
            ->fillForm(['name' => 'Changed', 'escalation_level' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->namedPet->fresh())->name->toBe('Pika')->escalation_level->toBe(1);
    });

    it('clears the name from the table through PetNameService: one pet_renamed broadcast + an audit log line', function () {
        Event::fake([PetUpdated::class]);
        Log::spy();
        $admin = auth()->user();

        Livewire::test(ListPets::class)
            ->assertTableActionVisible('clearName', $this->namedPet)
            ->callTableAction('clearName', $this->namedPet)
            ->assertHasNoTableActionErrors();

        expect($this->namedPet->fresh()->name)->toBeNull();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
        Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e): bool => $e->petId === $this->namedPet->id
            && $e->eventType === PetNameService::EVENT_TYPE
            && $e->payload['name'] === null);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => $message === 'Admin cleared a pet name'
            && $context['pet_id'] === $this->namedPet->id
            && $context['admin_user_id'] === $admin->id
            && ! in_array('Pika', $context, true))->once();
    });

    it('clears the name from the edit page and shows the empty field', function () {
        Event::fake([PetUpdated::class]);

        Livewire::test(EditPet::class, ['record' => $this->namedPet->getRouteKey()])
            ->assertActionVisible('clearName')
            ->callAction('clearName')
            ->assertFormSet(['name' => null])
            ->assertActionHidden('clearName');

        expect($this->namedPet->fresh()->name)->toBeNull();
        Event::assertDispatchedTimes(PetUpdated::class, 1);
    });

    it('hides the action for a pet without a name', function () {
        $unnamed = Pet::factory()->create(['user_id' => User::factory()->child()->create()->id, 'name' => null]);

        Livewire::test(ListPets::class)->assertTableActionHidden('clearName', $unnamed);
        Livewire::test(EditPet::class, ['record' => $unnamed->getRouteKey()])->assertActionHidden('clearName');
    });

    it('is superadmin only: hidden and not callable for anyone else, and the panel itself is refused', function () {
        actingAs($other = User::factory()->create(['role' => 'parent', 'is_superadmin' => false]));
        Event::fake([PetUpdated::class]);

        // Hidden, and a forged Livewire call (bypassing the UI) does nothing.
        Livewire::test(ListPets::class)
            ->assertTableActionHidden('clearName', $this->namedPet)
            ->call('mountTableAction', 'clearName', (string) $this->namedPet->getKey())
            ->call('callMountedTableAction');
        Livewire::test(EditPet::class, ['record' => $this->namedPet->getRouteKey()])
            ->assertActionHidden('clearName')
            ->call('mountAction', 'clearName')
            ->call('callMountedAction');

        expect($this->namedPet->fresh()->name)->toBe('Pika');
        Event::assertNotDispatched(PetUpdated::class);

        $this->get(PetResource::getUrl('index'))->assertForbidden();
        expect($other->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    });
});
