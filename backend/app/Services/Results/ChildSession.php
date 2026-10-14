<?php

namespace App\Services\Results;

use App\Models\Pet;
use App\Models\User;

/**
 * A signed-in child device (PIN login M2-02, parent device session M2-11).
 */
final class ChildSession
{
    /**
     * @param  'new_pet'|'join_pet'|'relogin'  $mode
     */
    public function __construct(
        /** Plain-text Sanctum token with the `child` ability. */
        public readonly string $token,
        public readonly User $child,
        public readonly Pet $pet,
        /** @var 'new_pet'|'join_pet'|'relogin' */
        public readonly string $mode,
        public readonly bool $joinedExisting,
    ) {}
}
