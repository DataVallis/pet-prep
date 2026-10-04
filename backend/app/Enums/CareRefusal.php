<?php

namespace App\Enums;

/**
 * Why a care action was refused although the pet is not locked (HTTP 422,
 * M1-07). The response carries `next_allowed_at` where a time is known.
 */
enum CareRefusal: string
{
    /** Feeding only inside a breed feed window (family-local, PRODUCT_SPEC §5). */
    case OutsideFeedWindow = 'outside_feed_window';

    /** One feed per window ("2× / dan" with two windows). */
    case AlreadyFedThisWindow = 'already_fed_this_window';

    /** breed water_times_per_day reached for the family-local day. */
    case WaterDailyLimit = 'water_daily_limit';

    /** Less than breed water_min_gap_minutes since the last refill. */
    case WaterTooSoon = 'water_too_soon';

    /** Hygiene shows 0 %: the mess must be cleaned first (PRODUCT_SPEC §8). */
    case NeedsCleaning = 'needs_cleaning';

    /** The responsibility contract is signed once per pet. */
    case ContractAlreadySigned = 'contract_already_signed';
}
