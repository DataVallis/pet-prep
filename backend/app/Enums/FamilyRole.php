<?php

namespace App\Enums;

/**
 * Role of a user inside a family (family_user.role, ADR-012). Mirrors
 * users.role; a parent never cares for a pet, a child never manages the family.
 */
enum FamilyRole: string
{
    case Parent = 'parent';
    case Child = 'child';
}
