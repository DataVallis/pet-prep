<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Models\Pet;
use App\Models\User;
use Filament\Widgets\ChartWidget;

/**
 * New parents, children and pets per day in the dashboard period (line chart,
 * series can be toggled in the legend). Superadmins are not counted.
 */
class GrowthChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return __('admin.dashboard.growth.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.growth.description');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => __('admin.dashboard.growth.parents'),
                    'data' => $this->dailyCounts(User::where('role', UserRole::Parent->value)->where('is_superadmin', false)),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => __('admin.dashboard.growth.children'),
                    'data' => $this->dailyCounts(User::where('role', UserRole::Child->value)),
                    'borderColor' => '#a855f7',
                    'backgroundColor' => 'rgba(168, 85, 247, 0.1)',
                    'tension' => 0.3,
                ],
                [
                    'label' => __('admin.dashboard.growth.pets'),
                    'data' => $this->dailyCounts(Pet::query()),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension' => 0.3,
                ],
            ],
            'labels' => array_map(fn (string $d): string => self::dayLabel($d), $this->periodDates()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ];
    }
}
