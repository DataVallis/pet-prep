<?php

namespace App\Filament\Widgets;

use App\Enums\PetPlan;
use App\Models\Pet;
use Filament\Widgets\ChartWidget;

/**
 * Active pets by plan: free, paid 12-week challenge, challenge not paid yet
 * (doughnut). All time — the dashboard period does not apply.
 */
class PetPlansChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return __('admin.dashboard.plans.heading');
    }

    public function getDescription(): ?string
    {
        return __('admin.dashboard.plans.description');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $active = Pet::where('is_active', true);
        $challenge = $active->clone()->where('plan', PetPlan::Challenge->value);

        return [
            'datasets' => [[
                'data' => [
                    $active->clone()->where('plan', PetPlan::Free->value)->count(),
                    $challenge->clone()->whereNotNull('challenge_paid_at')->count(),
                    $challenge->clone()->whereNull('challenge_paid_at')->count(),
                ],
                'backgroundColor' => ['#94a3b8', '#10b981', '#f59e0b'],
                'borderWidth' => 0,
            ]],
            'labels' => [
                __('admin.dashboard.plans.free'),
                __('admin.dashboard.plans.challenge_paid'),
                __('admin.dashboard.plans.challenge_unpaid'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'cutout' => '60%',
        ];
    }
}
