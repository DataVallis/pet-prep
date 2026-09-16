<?php

namespace App\Observers;

use App\Events\PetUpdated;
use App\Models\Pet;

class PetObserver
{
    /**
     * Handle the Pet "updated" event.
     * Broadcast a real-time PetUpdated event to the parent dashboard
     * whenever any pet metric changes (hunger, energy, hygiene, etc.).
     */
    public function updated(Pet $pet): void
    {
        // Only broadcast if the pet is in an active simulation session
        if ($pet->is_active) {
            broadcast(new PetUpdated($pet, 'metric_changed'));
        }
    }
}
