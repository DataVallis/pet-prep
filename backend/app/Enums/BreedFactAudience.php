<?php

namespace App\Enums;

/**
 * Who a breed fact is written for (M5-R09, DID_YOU_KNOW_SPEC §2): the child
 * (short, child tone, never health) or the parent (all categories, with the
 * source link). Mirrored by `breed_facts_audience_check`.
 */
enum BreedFactAudience: string
{
    case Child = 'child';
    case Parent = 'parent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
