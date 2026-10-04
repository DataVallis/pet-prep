<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\FamilyMember;
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

    /**
     * A child profile's devices / PINs (M2-02): any parent of the child's
     * family — never by users.parent_id.
     */
    public function manageChild(User $user, User $child): bool
    {
        if (! $user->isParent() || ! $child->isChild()) {
            return false;
        }

        $familyId = FamilyMember::where('user_id', $child->id)->value('family_id');

        return $familyId !== null && (Family::find($familyId)?->hasParent($user) ?? false);
    }
}
