<?php

namespace App\Enums;

/**
 * Lifecycle of a `pet_play_events` row (M5-R05). Mirrored by the
 * pet_play_events_status_check constraint. Free plays are always `done`.
 */
enum PlayStatus: string
{
    /** An invitation that has not been completed and has not ended yet. */
    case Pending = 'pending';

    /** Completed by a child (`completed_by`, `completed_at`). */
    case Done = 'done';

    /** An invitation nobody took within its open time — no consequence. */
    case Expired = 'expired';

    /** An invitation that fell into a freeze, before birth or into a scheduler outage. */
    case Skipped = 'skipped';
}
