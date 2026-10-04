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

        return [
            Stat::make('AI spend today (est.)', sprintf('$%.2f / $%.2f', $today, $daily))
                ->description('Daily cap AI_DAILY_BUDGET_USD ('.$guard->timezone().')')
                ->color($today >= $daily ? 'danger' : ($today >= 0.8 * $daily ? 'warning' : 'success')),
            Stat::make('AI spend this month (est.)', sprintf('$%.2f / $%.2f', $month, $monthly))
                ->description(sprintf('Lab $%.2f · reference images $%.2f · videos $%.2f',
                    $guard->spentThisMonthUsdFor(AiSpendPurpose::Lab),
                    $guard->spentThisMonthUsdFor(AiSpendPurpose::ReferenceImage),
                    $guard->spentThisMonthUsdFor(AiSpendPurpose::StateVideo),
                ))
                ->color($month >= $monthly ? 'danger' : ($month >= 0.8 * $monthly ? 'warning' : 'success')),
            Stat::make('fal.ai balance', $balanceAt ? 'EXHAUSTED' : 'OK')
                ->description($balanceAt
                    ? 'Since '.$balanceAt.' — top up at fal.ai/dashboard/billing'
                    : $mediaErrors.' pet(s) without media (budget / balance / errors)')
                ->color($balanceAt ? 'danger' : ($mediaErrors > 0 ? 'warning' : 'success')),
        ];
    }
}
