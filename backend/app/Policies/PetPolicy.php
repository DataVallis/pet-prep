<?php

namespace App\Policies;

use App\Models\Family;
use App\Models\Pet;
use App\Models\User;

/**
 * Pet authorization (M1-07, family model M2-01 / ADR-012). Auto-discovered
 * for App\Models\Pet. Tokens carry no Sanctum abilities yet, so role and
 * family membership decide — never users.parent_id / pets.user_id.
 */
class PetPolicy
{
    /**
     * Only a child profile may use the child API (a parent gets 403).
     */
    public function useChildApi(User $user): bool
    {
        return $user->isChild();
    }

    /**
     * Care actions: a child who is a caretaker of this pet. Parents never
     * act on a pet.
     */
    public function act(User $user, Pet $pet): bool
    {
        return $user->isChild() && $pet->hasCaretaker($user);
    }

    /**
     * Real-time channel `private-pet.{id}` (M1-08): every caretaker child of
     * the pet and every parent of the pet's family — nobody else.
     */
    public function listen(User $user, Pet $pet): bool
    {
        if ($user->isChild()) {
            return $pet->hasCaretaker($user);
        }

        return $this->manage($user, $pet);
    }

    /**
     * Parent controls (dashboard detail, hard stop, join PIN): any parent of
     * the pet's family.
     */
    public function manage(User $user, Pet $pet): bool
    {
        return $user->isParent()
            && (Family::find($pet->family_id)?->hasParent($user) ?? false);
    }
}
