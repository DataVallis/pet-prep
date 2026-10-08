<?php

namespace App\Enums;

/**
 * Server-driven cat care sessions (M5-R06_PLAN T6, one table
 * `pet_care_sessions`). Mirrored by the pet_care_sessions_kind_check
 * constraint.
 *
 * Only `wand_play` is used today (M5-R06-04); `grooming` and
 * `litter_change` are reserved for M5-R06-05 (the enum and the CHECK are
 * ready so that task needs no schema change for the kind).
 */
enum CareSessionKind: string
{
    /** "Palica s peresom": the ~60 s wand mini-game, the cat's daily play (CAT_SPEC Q1, §5.2). */
    case WandPlay = 'wand_play';

    /** Combing a Maine Coon (CAT_SPEC Q8) — M5-R06-05. */
    case Grooming = 'grooming';

    /** Weekly full litter change (CAT_SPEC Q3) — M5-R06-05. */
    case LitterChange = 'litter_change';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
