<?php

namespace App\Enums;

/**
 * Which schedule a `quiet_hours_changes` row describes (David 2026-11-24).
 * Mirrored by the CHECK constraint `quiet_hours_changes_scope_check`.
 */
enum QuietHoursChangeScope: string
{
    /** The family's `quiet_hours` row (PUT /api/parent/quiet-hours). */
    case Family = 'family';

    /** A pet's own hours, `pets.own_quiet_hours` (PUT /api/parent/pets/{pet}/quiet-hours). */
    case Pet = 'pet';
}
