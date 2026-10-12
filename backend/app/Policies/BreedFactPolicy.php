<?php

namespace App\Policies;

use App\Models\BreedFact;
use App\Models\User;
use App\Services\BreedFactService;

/**
 * Breed facts (M5-R09b, DID_YOU_KNOW_SPEC §2): parents and children of a
 * family read them; which ones is BreedFactService::mayRead (approved, the
 * audience, a breed of the family's pets / the child's own pet). Only a
 * superadmin approves (admin panel).
 */
class BreedFactPolicy
{
    public function useFacts(User $user): bool
    {
        return $user->isParent() || $user->isChild();
    }

    public function read(User $user, BreedFact $fact): bool
    {
        return app(BreedFactService::class)->mayRead($user, $fact);
    }

    public function approve(User $user, BreedFact $fact): bool
    {
        return $user->isSuperadmin();
    }
}
