<?php

namespace App\Enums;

use App\Models\User;

/**
 * Sanctum token abilities (M2-03). Every token issued since M2-02 carries
 * exactly one of them; route groups require it (`ability:parent` /
 * `ability:child`). Tokens issued before M2-02 have ['*'] and pass every
 * ability check — the policies (role + family) still decide.
 *
 * `gift:redeem` (M6-S01b, CONTRACT_WEB_SIGNIN §1.4) is never returned by
 * forUser() / abilitiesFor(): only WebParentSessionService mints it, for the
 * 15-minute website sign-in on the gift redeem page. Such a token works on
 * exactly two routes (RestrictWebSessionTokens, rule W).
 */
enum TokenAbility: string
{
    case Parent = 'parent';
    case Child = 'child';
    case GiftRedeem = 'gift:redeem';

    public static function forUser(User $user): self
    {
        return $user->isChild() ? self::Child : self::Parent;
    }

    /**
     * @return list<string>
     */
    public static function abilitiesFor(User $user): array
    {
        return [self::forUser($user)->value];
    }
}
