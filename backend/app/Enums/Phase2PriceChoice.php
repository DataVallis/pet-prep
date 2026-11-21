<?php

namespace App\Enums;

/**
 * »Koliko bi bili pripravljeni plačati?« on the Phase-2 fake door (H7, VALIDATION_PLAN §4.4) —
 * monthly options (David 2026-11-21 D3): »Samo brezplačno« / »2,99 € na mesec« / »5,99 € na mesec«.
 * Stored in `phase2_interests.price_choice`, mirrored by the phase2_interests_price_choice_check
 * constraint. Nothing is sold: the answer only measures demand.
 */
enum Phase2PriceChoice: string
{
    case Free = 'free';
    case Monthly299 = 'monthly_2_99';
    case Monthly599 = 'monthly_5_99';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
