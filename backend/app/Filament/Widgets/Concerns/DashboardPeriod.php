<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Period choices of the dashboard filter (days, including today) and the
 * admin wall clock used for per-day buckets.
 */
final class DashboardPeriod
{
    public const TIMEZONE = 'Europe/Ljubljana';

    /** @var list<int> */
    public const DAYS = [7, 30, 90, 365];

    public const DEFAULT = 30;
}
