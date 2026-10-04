<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Models\AiSpendLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily / monthly caps on ESTIMATED fal.ai spend (M4-07).
 *
 * reserve() runs BEFORE every fal call in its own short transaction (never
 * around the HTTP call): it serialises concurrent callers with a PostgreSQL
 * advisory lock, sums today's and this month's reserved + committed rows and
 * either inserts a `reserved` row or refuses (fail closed). The caller settles
 * the row: commit() when fal accepted / produced, void() when fal refused or
 * the request failed before fal could charge.
 */
class AiSpendGuard
{
    private const ADVISORY_LOCK_KEY = 4_407_202_610;

    public function dailyCapUsd(): float
    {
        return max(0.0, (float) config('media.budget.daily_usd', 5));
    }

    public function monthlyCapUsd(): float
    {
        return max(0.0, (float) config('media.budget.monthly_usd', 50));
    }

    public function timezone(): string
    {
        return (string) config('media.budget.timezone', 'UTC');
    }

    public function spentTodayUsd(): float
    {
        return $this->spentSince($this->now()->startOfDay());
    }

    public function spentThisMonthUsd(): float
    {
        return $this->spentSince($this->now()->startOfMonth());
    }

    public function spentThisMonthUsdFor(AiSpendPurpose $purpose): float
    {
        return round((float) AiSpendLedger::query()->counted()
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', $this->now()->startOfMonth()->utc())
            ->sum('cost_usd'), 4);
    }

    /**
     * Could an estimated amount still be spent now? (UI pre-check; reserve() is the real gate.)
     */
    public function refusalFor(float $costUsd): ?AiCallFailure
    {
        if ($this->spentTodayUsd() + $costUsd > $this->dailyCapUsd() + 1e-9) {
            return AiCallFailure::BudgetDaily;
        }

        if ($this->spentThisMonthUsd() + $costUsd > $this->monthlyCapUsd() + 1e-9) {
            return AiCallFailure::BudgetMonthly;
        }

        return null;
    }

    /**
     * @throws AiCallException when a cap would be exceeded
     */
    public function reserve(ModelProfile $profile, AiSpendPurpose $purpose, ?int $petId = null, ?int $labResultId = null): AiSpendLedger
    {
        $cost = $profile->estimatedCostUsd();

        return DB::transaction(function () use ($profile, $purpose, $petId, $labResultId, $cost) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY]);
            }

            $refusal = $this->refusalFor($cost);

            if ($refusal !== null) {
                throw new AiCallException($refusal, sprintf(
                    '%s: today $%.2f of $%.2f, this month $%.2f of $%.2f, call ~$%.4f (%s).',
                    $refusal->label(),
                    $this->spentTodayUsd(),
                    $this->dailyCapUsd(),
                    $this->spentThisMonthUsd(),
                    $this->monthlyCapUsd(),
                    $cost,
                    $profile->key,
                ));
            }

            return AiSpendLedger::create([
                'purpose' => $purpose->value,
                'profile' => $profile->key,
                'endpoint' => $profile->endpoint,
                'unit' => $profile->unit(),
                'units' => $profile->units(),
                'cost_usd' => $cost,
                'status' => AiSpendLedger::STATUS_RESERVED,
                'pet_id' => $petId,
                'media_lab_result_id' => $labResultId,
            ]);
        });
    }

    public function commit(AiSpendLedger $entry, ?string $requestId = null): void
    {
        $entry->update(['status' => AiSpendLedger::STATUS_COMMITTED, 'request_id' => $requestId ?? $entry->request_id]);
    }

    public function void(AiSpendLedger $entry, AiCallFailure $reason): void
    {
        $entry->update(['status' => AiSpendLedger::STATUS_VOID, 'error_reason' => $reason->value]);
    }

    private function spentSince(CarbonImmutable $localStart): float
    {
        return round((float) AiSpendLedger::query()->counted()
            ->where('created_at', '>=', $localStart->utc())
            ->sum('cost_usd'), 4);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }
}
