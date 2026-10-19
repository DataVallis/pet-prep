<?php

namespace App\Filament\Widgets\SystemHealth;

use App\Models\User;
use App\Services\SystemHealth\SystemHealthService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Error occurrences per hour over the last 48 h (System health, David
 * 2026-10-19). Hours shown in Europe/Ljubljana; buckets are UTC hours.
 */
class ErrorsPerHourChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $pollingInterval = '60s';

    protected static ?string $maxHeight = '220px';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    public function getHeading(): string
    {
        return __('admin.system_health.chart.heading');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $series = app(SystemHealthService::class)->occurrencesPerHour(48);

        return [
            'datasets' => [[
                'label' => __('admin.system_health.chart.label'),
                'data' => array_values($series),
                'backgroundColor' => '#ef4444',
                'borderRadius' => 2,
            ]],
            'labels' => array_map(
                fn (string $hour): string => Carbon::parse($hour)->timezone('Europe/Ljubljana')->format('D H:00'),
                array_keys($series),
            ),
        ];
    }
}
