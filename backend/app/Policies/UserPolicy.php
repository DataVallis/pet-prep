<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Family-wide settings (timezone, M1-03) belong to the parent profile.
     * Auto-discovered for App\Models\User.
     */
    public function updateFamilySettings(User $user): bool
    {
        return $user->isParent();
    }
}
