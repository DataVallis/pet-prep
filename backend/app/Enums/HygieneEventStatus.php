<?php

namespace App\Enums;

/**
 * Lifecycle of a scheduled hygiene event (M1-05). Mirrored by the
 * pet_hygiene_events_status_check constraint.
 */
enum HygieneEventStatus: string
{
    /** Scheduled, its time has not been processed yet. */
    case Pending = 'pending';

    /** Happened: hygiene dropped to 0 at scheduled_at. */
    case Applied = 'applied';

    /** Did not happen: fell into a freeze, before the pet's decay clock, or into quiet hours. */
    case Skipped = 'skipped';
}
