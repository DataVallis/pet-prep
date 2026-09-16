<?php

namespace App\Observers;

use App\Events\PetUpdated;
use App\Models\ActivityLog;

class ActivityLogObserver
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
