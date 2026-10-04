<?php

use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\User;
use App\Services\EscalationService;
use App\Services\PetActivityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| EscalationService row lock (M1-07 review fix, HANDOFF debt "finding 3")
|--------------------------------------------------------------------------
|
| Escalation used to decide and write from a pet model loaded before the
| per-pet work started. With child actions writing concurrently (M1-07), a
| clean / feed / hard stop committed after that load was ignored: the stale
| model still showed the neglect clock, so the dog fell ill or was taken away
| although the child had just acted. Now every pet is re-read with
| lockForUpdate() inside its own transaction; the passed model is only an ID.
*/

beforeEach(function () {
    seedBreedConfigs();
    // 08:00 in Ljubljana (morning feed window), no quiet hours configured.
    Carbon::setTestNow(Carbon::parse('2026-10-04 06:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function erlPet(array $attributes): Pet
{
    $parent = User::factory()->parent()->create(['timezone' => 'Europe/Ljubljana']);
    $child = User::factory()->child()->create(['parent_id' => $parent->id]);

    return disableHygieneEvents(Pet::factory()->create(array_merge(['user_id' => $child->id], $attributes)));
}

it('does not make the dog ill from a stale model after the child cleaned', function () {
    $pet = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7), 'pet_state' => 'sick']);
    $stale = Pet::findOrFail($pet->id); // loaded by the tick before the clean lands

    app(PetActivityService::class)->clean(Pet::findOrFail($pet->id));

    $escalated = app(EscalationService::class)->processPetEscalation($stale);

    $fresh = Pet::findOrFail($pet->id);
    expect($escalated)->toBeFalse()
        ->and($fresh->illness_until)->toBeNull()
        ->and($fresh->hygiene_level)->toBe(100.0)
        ->and($fresh->hygiene_zero_since)->toBeNull()
        // The caller's model is synced with the locked row.
        ->and($stale->hygiene_zero_since)->toBeNull()
        ->and($stale->hygiene_level)->toBe(100.0);
});

it('does not start an illness while a hard stop committed after the load', function () {
    $pet = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);
    $stale = Pet::findOrFail($pet->id);

    Pet::findOrFail($pet->id)->update(['is_hard_stopped' => true]);

    expect(app(EscalationService::class)->processPetEscalation($stale))->toBeFalse();

    $fresh = Pet::findOrFail($pet->id);
    expect($fresh->illness_until)->toBeNull()
        ->and($fresh->is_hard_stopped)->toBeTrue()
        ->and($fresh->escalation_level)->toBe(0);
});

it('does not take the dog away from a stale model after the child fed it', function () {
    $pet = erlPet(['hunger_level' => 0, 'hunger_zero_since' => now()->subHours(25)]);
    $stale = Pet::findOrFail($pet->id);

    expect(app(PetActivityService::class)->feed(Pet::findOrFail($pet->id))->status)->toBe('accepted');

    expect(app(EscalationService::class)->processPetEscalation($stale))->toBeFalse();

    $fresh = Pet::findOrFail($pet->id);
    expect($fresh->is_game_over)->toBeFalse()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->hunger_level)->toBe(100.0);
});

it('does not send a phase-1 reminder from a stale model after watering', function () {
    $pet = erlPet(['thirst_level' => 25]);
    $stale = Pet::findOrFail($pet->id);

    app(PetActivityService::class)->water(Pet::findOrFail($pet->id));

    app(EscalationService::class)->processPetEscalation($stale);

    expect(Pet::findOrFail($pet->id)->escalation_level)->toBe(0);
});

it('escalates normally from the fresh row', function () {
    $pet = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);

    expect(app(EscalationService::class)->processPetEscalation($pet))->toBeTrue()
        ->and($pet->illness_until)->not->toBeNull()
        ->and(Pet::findOrFail($pet->id)->illness_until)->not->toBeNull();
});

it('processes all pets from fresh rows (IDs only)', function () {
    $cleaned = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);
    $neglected = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);
    app(PetActivityService::class)->clean($cleaned);

    $result = app(EscalationService::class)->processAllActivePets();

    expect($result['processed'])->toBe(2)
        ->and(Pet::findOrFail($cleaned->id)->illness_until)->toBeNull()
        ->and(Pet::findOrFail($neglected->id)->illness_until)->not->toBeNull();
});

it('broadcasts the illness only after the transaction commits', function () {
    Event::fake([PetUpdated::class]);
    $pet = erlPet(['hygiene_level' => 0, 'hygiene_zero_since' => now()->subHours(7)]);

    DB::transaction(function () use ($pet) {
        app(EscalationService::class)->processPetEscalation($pet);

        Event::assertNotDispatched(PetUpdated::class);
    });

    Event::assertDispatched(PetUpdated::class, fn (PetUpdated $e) => $e->eventType === 'illness_triggered');
});
