<?php

namespace App\Policies;

use App\Models\User;

/**
 * Auto-discovered for App\Models\User.
 */
class UserPolicy
{
    /**
     * Family-wide settings (timezone, M1-03) belong to the parents.
     */
    public function updateFamilySettings(User $user): bool
    {
        return $user->isParent();
    }

    /**
     * Parent-only family endpoints (dashboard, PINs, invites, join).
     */
    public function manageFamily(User $user): bool
    {
        return $user->isParent();
    }
}
