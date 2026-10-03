<?php

namespace App\Observers;

use App\Events\PetUpdated;
use App\Models\Pet;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after the surrounding DB transaction commits (if any), so writes made
 * under a row lock never broadcast from inside the transaction.
 */
class PetObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the Pet "updated" event.
     * Broadcast a real-time PetUpdated event to the parent dashboard
     * whenever any pet metric changes (hunger, energy, hygiene, etc.).
     * The decay tick writes quietly and broadcasts itself (once per tick).
     */
    public function updated(Pet $pet): void
    {
        // Only broadcast if the pet is in an active simulation session
        if ($pet->is_active) {
            broadcast(new PetUpdated($pet, 'metric_changed'));
        }
    }
}
