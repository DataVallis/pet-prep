<?php

namespace App\Observers;

use App\Events\PetUpdated;
use App\Models\ActivityLog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after the surrounding DB transaction commits (if any): escalation
 * writes its activity rows under the pet's row lock (M1-07 review fix), and
 * a broadcast must never run inside a transaction.
 */
class ActivityLogObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * Handle the ActivityLog "created" event.
     * Broadcast a real-time PetUpdated event to the parent dashboard
     * whenever a new activity log entry is saved.
     */
    public function created(ActivityLog $activityLog): void
    {
        $pet = $activityLog->pet;

        if ($pet && $pet->is_active) {
            broadcast(new PetUpdated($pet, $activityLog->activity_type->value))->toOthers();
        }
    }
}
