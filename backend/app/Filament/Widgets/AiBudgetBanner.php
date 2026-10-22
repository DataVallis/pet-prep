<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Media\AiBudgetMonitor;
use App\Services\Media\AiBudgetStatus;
use App\Services\Media\AiSpendGuard;
use Filament\Widgets\Widget;

/**
 * M5-T15 (David 2026-10-22): AI budget status banner — on the dashboard
 * (discovered) and on /admin/system-health (header widget). Yellow when a
 * production or AI Lab cap is ≥ 80 % used, red at 100 % (blocked), with
 * today / month spent vs cap, the fal.ai balance and a link to /admin/ai-budget.
 * Superadmin only.
 */
class AiBudgetBanner extends Widget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /** A warning must be visible at once, not after a lazy load. */
    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.ai-budget-banner';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperadmin();
    }

    /** Livewire requests (polling) reach a widget directly: check again. */
    public function boot(): void
    {
        abort_unless(static::canView(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $monitor = app(AiBudgetMonitor::class);
        $statuses = $monitor->statuses();
        $balance = $monitor->falBalance();

        return [
            'statuses' => $statuses,
            'level' => $monitor->worstLevel($statuses),
            'falLevel' => $monitor->falBalanceLevel(),
            'falBalance' => $balance,
            'falThreshold' => $monitor->falBalanceAlertUsd(),
            'falFailures' => $monitor->falBalanceFailures(24),
            'warnPercent' => AiBudgetMonitor::WARN_PERCENT,
            'okLevel' => AiBudgetStatus::OK,
            'budgetUrl' => url('/admin/ai-budget'),
            // QA m2: the budget day / month boundaries (AI_BUDGET_TIMEZONE), also for the times shown.
            'timezone' => app(AiSpendGuard::class)->timezone(),
        ];
    }
}
