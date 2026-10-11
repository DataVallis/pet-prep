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
        return $this->dailyCountsBy($query, null, $column)[''] ?? array_fill(0, $this->periodDays(), 0);
    }

    /**
     * Rows per local day in the period split by a column (one query), each
     * series zero-filled, oldest first. With `$groupColumn` null the only key is ''.
     *
     * @param  Builder<Model>  $query
     * @return array<string, list<int>>
     */
    protected function dailyCountsBy(Builder $query, ?string $groupColumn, string $column = 'created_at'): array
    {
        $table = $query->getModel()->getTable();
        $qualified = str_contains($column, '.') ? $column : "{$table}.{$column}";
        // Production and CI are PostgreSQL (UTC storage → Ljubljana days); the
        // fallback only keeps other drivers working (UTC days).
        $day = DB::connection()->getDriverName() === 'pgsql'
            ? "to_char(({$qualified} AT TIME ZONE 'UTC') AT TIME ZONE '".DashboardPeriod::TIMEZONE."', 'YYYY-MM-DD')"
            : "strftime('%Y-%m-%d', {$qualified})";
        $group = $groupColumn !== null ? $query->getGrammar()->wrap($groupColumn) : "''";

        $rows = $query->clone()
            ->where($qualified, '>=', $this->periodStart())
            ->toBase()
            ->selectRaw("{$day} as day, {$group} as grp, count(*) as aggregate")
            ->groupByRaw($groupColumn !== null ? "{$day}, {$group}" : $day)
            ->get();

        $dates = $this->periodDates();
        $series = [];
        foreach ($rows as $row) {
            $key = (string) $row->grp;
            $series[$key] ??= array_fill_keys($dates, 0);
            if (array_key_exists((string) $row->day, $series[$key])) {
                $series[$key][(string) $row->day] = (int) $row->aggregate;
            }
        }

        return array_map(fn (array $byDate): array => array_values($byDate), $series);
    }

    /** Short x-axis label for a `Y-m-d` date. */
    protected static function dayLabel(string $date): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, DashboardPeriod::TIMEZONE)?->format('j. n.') ?? $date;
    }
}
