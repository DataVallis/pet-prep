<?php

namespace App\Services\Auth;

use App\Models\User;

/**
 * Outcome of SocialSignInService::signIn (M2-10c).
 */
final class SocialSignInResult
{
    public function __construct(
        public readonly User $user,
        /** Plain-text Sanctum token (ability `parent`). */
        public readonly string $token,
        /** true = the account (and its family) was created by this request. */
        public readonly bool $created,
    ) {}
}
