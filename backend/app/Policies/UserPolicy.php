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
     * Register / remove this app install for escalation pushes (M3-02):
     * parents (phase 3 alarm, illness, game over) and children (phase 1 / 2).
     */
    public function managePushDevices(User $user): bool
    {
        return $user->isParent() || $user->isChild();
    }

    /**
     * Parent-only family endpoints (dashboard, PINs, invites, join).
     */
    public function manageFamily(User $user): bool
    {
        return $user->isParent();
    }

    /**
     * Delete one's own parent account (M2-08). A superadmin is refused in
     * AccountDeletionService with the explicit reason `superadmin_protected`.
     */
    public function deleteAccount(User $user): bool
    {
        return $user->isParent();
    }

    /**
     * Export the family's data (M2-08, GDPR art. 15 / 20): parents only.
     */
    public function exportFamilyData(User $user): bool
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
