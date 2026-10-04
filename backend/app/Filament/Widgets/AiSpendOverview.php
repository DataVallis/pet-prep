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
    protected static ?int $sort = 1;

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
            Stat::make('Pets AI spend today (est.)', sprintf('$%.2f / $%.2f', $today, $daily))
                ->description(sprintf('This month $%.2f / $%.2f · images $%.2f · videos $%.2f (%s)',
                    $month,
                    $monthly,
                    $guard->spentThisMonthUsdFor(AiSpendPurpose::ReferenceImage),
                    $guard->spentThisMonthUsdFor(AiSpendPurpose::StateVideo),
                    $guard->timezone(),
                ))
                ->color(($today >= $daily || $month >= $monthly) ? 'danger' : (($today >= 0.8 * $daily || $month >= 0.8 * $monthly) ? 'warning' : 'success')),
            Stat::make('AI Lab spend today (est.)', sprintf('$%.2f / $%.2f', $labToday, $guard->labDailyCapUsd()))
                ->description(sprintf('This month $%.2f / $%.2f — separate from the pets budget', $labMonth, $guard->labMonthlyCapUsd()))
                ->color(($labToday >= $guard->labDailyCapUsd() || $labMonth >= $guard->labMonthlyCapUsd()) ? 'danger' : 'success'),
            Stat::make('fal.ai balance', $balanceAt ? 'EXHAUSTED' : 'OK')
                ->description($balanceAt
                    ? 'Since '.$balanceAt.' — top up at fal.ai/dashboard/billing'
                    : $mediaErrors.' pet(s) without media (budget / balance / errors)')
                ->color($balanceAt ? 'danger' : ($mediaErrors > 0 ? 'warning' : 'success')),
        ];
    }
}
