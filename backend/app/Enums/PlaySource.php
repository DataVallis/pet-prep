<?php

namespace App\Enums;

/**
 * Where a `pet_play_events` row comes from (M5-R05). Mirrored by the
 * pet_play_events_source_check constraint.
 */
enum PlaySource: string
{
    /** The dog's invitation, scheduled by the server (2 per family-local day). */
    case Invitation = 'invitation';

    /** The child started a game on their own (no open invitation of that kind). */
    case Free = 'free';
}
