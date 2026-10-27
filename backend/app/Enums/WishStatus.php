<?php

namespace App\Enums;

/**
 * Status of a child's wish (M6-W01, `pet_wishes.status`, mirrored by the
 * pet_wishes_status_check constraint): open → bought | declined | cancelled.
 */
enum WishStatus: string
{
    /** Waiting for a parent. */
    case Open = 'open';

    /** A parent bought it; the item went to the wish's pet. */
    case Bought = 'bought';

    /** »Ne zdaj« — the same child may ask again 7 days later. */
    case Declined = 'declined';

    /** The pet got the item another way (bundle, surprise purchase) or the pet ended (game over). */
    case Cancelled = 'cancelled';
}
