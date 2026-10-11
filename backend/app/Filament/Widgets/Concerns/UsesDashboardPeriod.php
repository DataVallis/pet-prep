<?php

namespace App\Filament\Widgets\Concerns;

use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared by the dashboard widgets (App\Filament\Pages\Dashboard): the period
 * picked in the dashboard filter and per-day counts in the admin's wall clock
 * (Europe/Ljubljana — storage is UTC).
 */
trait UsesDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function periodDays(): int
    {
        $days = (int) ($this->filters['period'] ?? DashboardPeriod::DEFAULT);

        return in_array($days, DashboardPeriod::DAYS, true) ? $days : DashboardPeriod::DEFAULT;
    }

    /** First instant of the period (local midnight, `periodDays()` days including today), in UTC. */
    protected function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::now(DashboardPeriod::TIMEZONE)->startOfDay()->subDays($this->periodDays() - 1)->utc();
    }

    /**
     * Local dates of the period, oldest first, as `Y-m-d`.
     *
     * @return list<string>
     */
    protected function periodDates(): array
    {
        $day = $this->periodStart()->setTimezone(DashboardPeriod::TIMEZONE);
        $dates = [];
        for ($i = 0; $i < $this->periodDays(); $i++) {
            $dates[] = $day->addDays($i)->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * Rows per local day in the period, zero-filled, oldest first.
     *
     * @param  Builder<Model>  $query
     * @return list<int>
     */
    protected function dailyCounts(Builder $query, string $column = 'created_at'): array
    {
        $table = $query->getModel()->getTable();
        $qualified = str_contains($column, '.') ? $column : "{$table}.{$column}";
        $day = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(({$qualified} AT TIME ZONE 'UTC') AT TIME ZONE '".DashboardPeriod::TIMEZONE."', 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', {$qualified})";

        /** @var array<string, int> $counts */
        $counts = $query->clone()
            ->where($qualified, '>=', $this->periodStart())
            ->toBase()
            ->selectRaw("{$day} as day, count(*) as aggregate")
            ->groupByRaw($day)
            ->pluck('aggregate', 'day')
            ->map(fn ($n): int => (int) $n)
            ->all();

        return array_map(fn (string $date): int => $counts[$date] ?? 0, $this->periodDates());
    }

    /** Short x-axis label for a `Y-m-d` date. */
    protected static function dayLabel(string $date): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, DashboardPeriod::TIMEZONE)?->format('j. n.') ?? $date;
    }
}
