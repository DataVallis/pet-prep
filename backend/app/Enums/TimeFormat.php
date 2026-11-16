<?php

namespace App\Enums;

/**
 * How clock times are shown to a person (David 2026-11-16: »da se lahko
 * nastavi 24-urni ali pa 12-urni format, ker gremo globalno«).
 * `users.time_format` (CHECK constraint). `auto` = follow the phone — for
 * push copy that is `device_push_tokens.hour12` of the receiving phone
 * (null = an older app → 24 h, as before).
 */
enum TimeFormat: string
{
    case Auto = 'auto';
    case H24 = '24h';
    case H12 = '12h';

    /**
     * 12 h on a phone whose own setting is `$deviceHour12` (null = unknown → 24 h).
     */
    public function hour12On(?bool $deviceHour12): bool
    {
        return match ($this) {
            self::H12 => true,
            self::H24 => false,
            self::Auto => $deviceHour12 === true,
        };
    }
}
