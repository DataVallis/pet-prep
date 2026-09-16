<?php

namespace App\Enums;

enum BreedType: string
{
    case Mutt = 'mutt';
    case BorderCollie = 'border_collie';

    /**
     * Get the breed slug as used in the breed_configs table.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Mutt => 'mutt',
            self::BorderCollie => 'border-collie',
        };
    }

    /**
     * Determine if this breed requires a premium unlock.
     */
    public function isPremium(): bool
    {
        return $this === self::BorderCollie;
    }
}
