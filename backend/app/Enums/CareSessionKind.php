<?php

namespace App\Enums;

/**
 * Server-driven cat care sessions (M5-R06_PLAN T6, one table
 * `pet_care_sessions`). Mirrored by the pet_care_sessions_kind_check
 * constraint.
 *
 * `wand_play` (M5-R06-04); `grooming`, `litter_change` and `scratching`
 * (the "carry to the scratcher + praise" redirect) — M5-R06-05.
 */
enum CareSessionKind: string
{
    /** "Palica s peresom": the ~60 s wand mini-game, the cat's daily play (CAT_SPEC Q1, §5.2). */
    case WandPlay = 'wand_play';

    /** Combing a Maine Coon (CAT_SPEC Q8) — M5-R06-05, CatChoreService. */
    case Grooming = 'grooming';

    /** Weekly full litter change (CAT_SPEC Q3) — M5-R06-05, CatChoreService. */
    case LitterChange = 'litter_change';

    /** "Odnesi na praskalnik in pohvali" (CAT_SPEC Q10) — M5-R06-05, ScratchingService. */
    case Scratching = 'scratching';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
