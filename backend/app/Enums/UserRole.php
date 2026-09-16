<?php

namespace App\Enums;

enum UserRole: string
{
    case Parent = 'parent';
    case Child = 'child';

    /**
     * Determine if the user is a parent.
     */
    public function isParent(): bool
    {
        return $this === self::Parent;
    }

    /**
     * Determine if the user is a child.
     */
    public function isChild(): bool
    {
        return $this === self::Child;
    }
}
