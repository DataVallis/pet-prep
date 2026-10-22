<?php

namespace App\Filament\Widgets;

use App\Enums\AiSpendPurpose;
use App\Models\Pet;
use App\Models\User;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\AiBudgetStatus;
use App\Services\Media\AiSpendGuard;
use App\Services\Media\FalGateway;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Estimated fal.ai spend vs. caps (M4-07) + fal balance alert. Superadmin only.
 */
class AiSpendOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $guard = app(AiSpendGuard::class);
        // M5-T15: the same levels as the AI budget banner / warnings (≥ 80 % yellow, 100 % red).
        $monitor = app(AiBudgetMonitor::class);
        $statuses = $monitor->statuses();
        $today = $guard->spentTodayUsd();
        $month = $guard->spentThisMonthUsd();
        $daily = $guard->dailyCapUsd();
        $monthly = $guard->monthlyCapUsd();
        $balanceAt = FalGateway::balanceExhaustedAt();
        $mediaErrors = Pet::query()->whereNotNull('media_error')->where('media_status', 'failed')->count();

        $labToday = $guard->spentTodayUsd(true);
        $labMonth = $guard->spentThisMonthUsd(true);

        return [
            Stat::make(__('admin.dashboard.ai.pets_today'), sprintf('$%.2f / $%.2f', $today, $daily))
                ->description(__('admin.dashboard.ai.pets_month', [
                    'month' => sprintf('%.2f', $month),
                    'cap' => sprintf('%.2f', $monthly),
                    'images' => sprintf('%.2f', $guard->spentThisMonthUsdFor(AiSpendPurpose::ReferenceImage)),
                    'videos' => sprintf('%.2f', $guard->spentThisMonthUsdFor(AiSpendPurpose::StateVideo)),
                    'tz' => $guard->timezone(),
                ]))
                ->color(self::colorOf($monitor->worstLevel([$statuses[0], $statuses[1]]))),
            Stat::make(__('admin.dashboard.ai.lab_today'), sprintf('$%.2f / $%.2f', $labToday, $guard->labDailyCapUsd()))
                ->description(__('admin.dashboard.ai.lab_month', ['month' => sprintf('%.2f', $labMonth), 'cap' => sprintf('%.2f', $guard->labMonthlyCapUsd())]))
                ->color(self::colorOf($monitor->worstLevel([$statuses[2], $statuses[3]]))),
            Stat::make(__('admin.dashboard.ai.balance'), $balanceAt ? __('admin.dashboard.ai.exhausted') : __('admin.dashboard.ai.ok'))
                ->description($balanceAt
                    ? __('admin.dashboard.ai.exhausted_desc', ['at' => $balanceAt])
                    : __('admin.dashboard.ai.errors_desc', ['count' => $mediaErrors]))
                ->color($balanceAt ? 'danger' : ($mediaErrors > 0 ? 'warning' : 'success')),
        ];
    }

    private static function colorOf(string $level): string
    {
        return match ($level) {
            AiBudgetStatus::BLOCKED => 'danger',
            AiBudgetStatus::WARNING => 'warning',
            default => 'success',
        };
    }
}
