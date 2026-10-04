<?php

namespace App\Policies;

use App\Models\Pet;
use App\Models\User;

/**
 * Child API authorization (M1-07). Auto-discovered for App\Models\Pet.
 * Tokens carry no Sanctum abilities yet, so the role decides.
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
     * A child acts only on its own pet.
     */
    public function act(User $user, Pet $pet): bool
    {
        return $user->isChild() && $pet->user_id === $user->id;
    }
}
