<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Models\AiBudgetAlert;
use App\Models\AiSpendLedger;
use App\Models\User;
use App\Notifications\AiBudgetAlertNotification;
use App\Services\AppSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * AI budget warnings (M5-T15, David 2026-10-22: the budgets stay capped and we
 * top up fal.ai ourselves, so the system must warn when only 20 % of a budget
 * is left — whatever amount is configured).
 *
 *  - statuses(): production + AI Lab, today + this month, from AiSpendGuard
 *    (reserved + committed, the numbers of /admin → AI budget). Warning at
 *    ≥ 80 % of the cap, blocked at 100 %; percentages, so a changed cap
 *    re-computes the level at once.
 *  - check(): run by the queued CheckAiBudgetAlerts job (scheduled every 5 min,
 *    `ai:budget-check`) — never inside a DB transaction. Every threshold is
 *    announced at most once per budget day / month (`ai_budget_alerts` unique
 *    row, claimed before the notification goes out). A cap of 0 (AI calls
 *    switched off on purpose) shows red but is not announced.
 *  - the fal.ai account balance (Platform API, FalGateway::accountBalance(),
 *    refreshed at most every 15 min and cached): warning below the admin
 *    threshold (default 20 $), once per day; exhausted (balance 0 or the
 *    gateway's `fal_balance` flag) once per day.
 *
 * Messages carry amounts only — never a pet, family or child.
 */
class AiBudgetMonitor
{
    public const WARN_PERCENT = 80;

    public const BLOCK_PERCENT = 100;

    public const BALANCE_CACHE_KEY = 'ai:fal_account_balance';

    public const BALANCE_REFRESH_MINUTES = 15;

    public const FAL_LOW = 0;

    public const FAL_EXHAUSTED = 100;

    public function __construct(
        private readonly AiSpendGuard $guard,
        private readonly FalGateway $fal,
        private readonly AppSettingsService $settings,
    ) {}

    /**
     * @return list<AiBudgetStatus> production day, production month, lab day, lab month
     */
    public function statuses(): array
    {
        $now = CarbonImmutable::now($this->guard->timezone());
        $day = $now->format('Y-m-d');
        $month = $now->format('Y-m');

        return [
            new AiBudgetStatus(AiBudgetAlert::BUDGET_PRODUCTION, 'day', $day, $this->guard->spentTodayUsd(), $this->guard->dailyCapUsd()),
            new AiBudgetStatus(AiBudgetAlert::BUDGET_PRODUCTION, 'month', $month, $this->guard->spentThisMonthUsd(), $this->guard->monthlyCapUsd()),
            new AiBudgetStatus(AiBudgetAlert::BUDGET_LAB, 'day', $day, $this->guard->spentTodayUsd(true), $this->guard->labDailyCapUsd()),
            new AiBudgetStatus(AiBudgetAlert::BUDGET_LAB, 'month', $month, $this->guard->spentThisMonthUsd(true), $this->guard->labMonthlyCapUsd()),
        ];
    }

    /**
     * The worst level over all budgets (ok | warning | blocked).
     *
     * @param  list<AiBudgetStatus>|null  $statuses
     */
    public function worstLevel(?array $statuses = null): string
    {
        $levels = array_map(fn (AiBudgetStatus $s): string => $s->level(), $statuses ?? $this->statuses());

        return in_array(AiBudgetStatus::BLOCKED, $levels, true)
            ? AiBudgetStatus::BLOCKED
            : (in_array(AiBudgetStatus::WARNING, $levels, true) ? AiBudgetStatus::WARNING : AiBudgetStatus::OK);
    }

    // ── fal.ai account balance ───────────────────────────────────

    /**
     * The last balance read (cache), or null when never read.
     *
     * @return array{status: string, balance_usd: float|null, currency: string|null, error: string|null, checked_at: string}|null
     */
    public function falBalance(): ?array
    {
        try {
            $value = Cache::get(self::BALANCE_CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }

    /**
     * Read the balance from fal when the cached value is older than 15 min (or $force).
     * Queued job only (HTTP).
     *
     * @return array{status: string, balance_usd: float|null, currency: string|null, error: string|null, checked_at: string}|null
     */
    public function refreshFalBalance(bool $force = false): ?array
    {
        $cached = $this->falBalance();
        if (! $force && $cached !== null
            && Carbon::parse($cached['checked_at'])->gt(Carbon::now()->subMinutes(self::BALANCE_REFRESH_MINUTES))) {
            return $cached;
        }

        $value = [...$this->fal->accountBalance(), 'checked_at' => Carbon::now()->utc()->toIso8601String()];
        Cache::forever(self::BALANCE_CACHE_KEY, $value);

        return $value;
    }

    public function falBalanceAlertUsd(): float
    {
        return $this->settings->falBalanceAlertUsd();
    }

    /** ok | low | exhausted | unknown */
    public function falBalanceLevel(): string
    {
        if (FalGateway::balanceExhaustedAt() !== null) {
            return 'exhausted';
        }

        $balance = $this->falBalance();
        if ($balance === null || $balance['status'] !== 'ok' || $balance['balance_usd'] === null) {
            return 'unknown';
        }

        return match (true) {
            $balance['balance_usd'] <= 0 => 'exhausted',
            $balance['balance_usd'] < $this->falBalanceAlertUsd() => 'low',
            default => 'ok',
        };
    }

    /** fal calls refused for an exhausted balance (voided ledger rows) in the last $hours. */
    public function falBalanceFailures(int $hours = 24): int
    {
        return AiSpendLedger::query()
            ->where('error_reason', AiCallFailure::FalBalance->value)
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->count();
    }

    // ── Warnings ────────────────────────────────────────────────

    /**
     * Announce every newly reached threshold (budgets + fal balance). Returns
     * the number of notifications sent (one per budget period at most per run).
     */
    public function check(): int
    {
        $sent = 0;

        foreach ($this->statuses() as $status) {
            if ($status->capUsd <= 0) {
                continue; // switched off on purpose: red on the pages, no message
            }

            $new = array_values(array_filter(
                $status->reachedThresholds(),
                fn (int $threshold): bool => $this->claim($status->budget, $status->period, $status->periodKey, $threshold, $status->spentUsd, $status->capUsd),
            ));

            if ($new === []) {
                continue;
            }

            // 70 % → 100 % in one step: both thresholds are claimed, only the 100 % goes out.
            $threshold = max($new);
            $this->send(AiBudgetAlertNotification::budget($status, $threshold), [$status->budget, $status->period, $status->periodKey, $new]);
            $sent++;
        }

        return $sent + $this->checkFalBalance();
    }

    private function checkFalBalance(): int
    {
        $level = $this->falBalanceLevel();
        if (! in_array($level, ['low', 'exhausted'], true)) {
            return 0;
        }

        $threshold = $level === 'exhausted' ? self::FAL_EXHAUSTED : self::FAL_LOW;
        $day = CarbonImmutable::now($this->guard->timezone())->format('Y-m-d');
        $balance = $this->falBalance()['balance_usd'] ?? null;

        if (! $this->claim(AiBudgetAlert::BUDGET_FAL_BALANCE, 'day', $day, $threshold, $balance, $this->falBalanceAlertUsd())) {
            return 0;
        }

        $this->send(AiBudgetAlertNotification::falBalance($level, $balance, $this->falBalanceAlertUsd()), [AiBudgetAlert::BUDGET_FAL_BALANCE, 'day', $day, [$threshold]]);

        return 1;
    }

    /** Insert the dedupe row; false when this threshold was already announced. */
    private function claim(string $budget, string $period, string $periodKey, int $threshold, ?float $spent, ?float $cap): bool
    {
        return AiBudgetAlert::query()->insertOrIgnore([
            'budget' => $budget,
            'period' => $period,
            'period_key' => $periodKey,
            'threshold' => $threshold,
            'spent_usd' => $spent,
            'cap_usd' => $cap,
            'created_at' => Carbon::now(),
        ]) > 0;
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: list<int>}  $claimed  released again if sending fails, so the next run retries
     */
    private function send(AiBudgetAlertNotification $notification, array $claimed): void
    {
        $admins = User::query()->where('is_superadmin', true)->get();

        try {
            if ($admins->isNotEmpty()) {
                Notification::send($admins, $notification);
            }
        } catch (Throwable $e) {
            [$budget, $period, $periodKey, $thresholds] = $claimed;
            AiBudgetAlert::query()
                ->where(['budget' => $budget, 'period' => $period, 'period_key' => $periodKey])
                ->whereIn('threshold', $thresholds)
                ->delete();
            throw $e;
        }

        Log::warning('AI budget warning sent', ['budget' => $claimed[0], 'period' => $claimed[1], 'period_key' => $claimed[2], 'thresholds' => $claimed[3], 'admins' => $admins->count()]);
    }
}
