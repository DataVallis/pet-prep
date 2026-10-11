<?php

namespace App\Filament\Widgets;

use App\Enums\AiSpendPurpose;
use App\Models\Pet;
use App\Models\User;
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
                ->color(($today >= $daily || $month >= $monthly) ? 'danger' : (($today >= 0.8 * $daily || $month >= 0.8 * $monthly) ? 'warning' : 'success')),
            Stat::make(__('admin.dashboard.ai.lab_today'), sprintf('$%.2f / $%.2f', $labToday, $guard->labDailyCapUsd()))
                ->description(__('admin.dashboard.ai.lab_month', ['month' => sprintf('%.2f', $labMonth), 'cap' => sprintf('%.2f', $guard->labMonthlyCapUsd())]))
                ->color(($labToday >= $guard->labDailyCapUsd() || $labMonth >= $guard->labMonthlyCapUsd()) ? 'danger' : 'success'),
            Stat::make(__('admin.dashboard.ai.balance'), $balanceAt ? __('admin.dashboard.ai.exhausted') : __('admin.dashboard.ai.ok'))
                ->description($balanceAt
                    ? __('admin.dashboard.ai.exhausted_desc', ['at' => $balanceAt])
                    : __('admin.dashboard.ai.errors_desc', ['count' => $mediaErrors]))
                ->color($balanceAt ? 'danger' : ($mediaErrors > 0 ? 'warning' : 'success')),
        ];
    }
}
