<?php

namespace App\Services;

use App\Models\User;

/**
 * Family-wide settings owned by the parent profile (M1-03).
 *
 * The family timezone is stored on the parent (`users.timezone`); children
 * read it through User::familyTimezone(). Changing it only changes how wall
 * clock rules are read from now on — stored timestamps stay UTC.
 */
class FamilySettingsService
{
    public function updateTimezone(User $parent, string $timezone): User
    {
        $parent->forceFill(['timezone' => $timezone])->save();

        return $parent;
    }
}
