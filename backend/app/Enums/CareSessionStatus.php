<?php

namespace App\Enums;

/**
 * Life cycle of a `pet_care_sessions` row (M5-R06-04). Mirrored by the
 * pet_care_sessions_status_check constraint. Only `completed` counts (play
 * meter, routine, the 2 h gap); every other end has no consequence — no
 * penalty, the child may start again at once (David 2026-10-08 ~20:40).
 */
enum CareSessionStatus: string
{
    /** Started, not finished yet (at most one per pet and kind). */
    case Active = 'active';

    /** Finished and the server judged the child really took part — counts. */
    case Completed = 'completed';

    /** Finished, but the interaction check failed (too few / badly spread "away" moves) — does not count. */
    case Failed = 'failed';

    /** The same child started a new session before finishing this one — does not count. */
    case Aborted = 'aborted';

    /** Not finished before its TTL — does not count. */
    case Expired = 'expired';

    /** A lock (hard stop, vet, game over, payment) began during the session — does not count. */
    case Interrupted = 'interrupted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
