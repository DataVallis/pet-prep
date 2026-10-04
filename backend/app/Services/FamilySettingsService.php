<?php

namespace App\Services;

use App\Models\User;

/**
 * Family-wide settings, editable by any parent of the family (M1-03, M2-01).
 *
 * The family timezone is stored on `families.timezone`; every parent's
 * `users.timezone` is kept in sync (deprecated mirror). Changing it only
 * changes how wall clock rules are read from now on — stored timestamps
 * stay UTC.
 */
class FamilySettingsService
{
    public function __construct(private readonly FamilyService $families) {}

    public function updateTimezone(User $parent, string $timezone): User
    {
        $family = $this->families->ensureFamilyFor($parent);
        $this->families->updateTimezone($family, $timezone);

        $parent->forceFill(['timezone' => $timezone])->syncOriginalAttribute('timezone');
        $parent->unsetRelation('family');

        return $parent;
    }
}
