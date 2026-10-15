<?php

namespace App\Services\Media;

use App\Enums\AiCallFailure;
use App\Enums\AiSpendPurpose;
use App\Models\AiSpendLedger;
use App\Services\AppSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Caps on ESTIMATED fal.ai spend (M4-07).
 *
 * Two separate budgets (PR #22 review):
 *  - production (reference_image, state_video): AI_DAILY_BUDGET_USD / AI_MONTHLY_BUDGET_USD,
 *    counted only over production rows — overridden by the superadmin in
 *    /admin → AI budget (M4-07b, AppSettingsService `ai_budget`);
 *  - AI Lab (lab): AI_LAB_DAILY_USD / AI_LAB_MONTHLY_USD, counted only over lab rows,
 *    so lab runs can never starve new pets' reference images.
 *
 * reserve() runs BEFORE every fal call in its own short transaction (never
 * around the HTTP call): it serialises concurrent callers with a PostgreSQL
 * advisory lock, sums the matching budget's reserved + committed rows and
 * either inserts a `reserved` row or refuses (fail closed). The caller settles
 * the row: commit() when the request reached fal (even if the answer was lost —
 * fal may still charge), void() only when fal never got it or refused it.
 */
class AiSpendGuard
{
    private const ADVISORY_LOCK_KEY = 4_407_202_610;

    public function __construct(private readonly AppSettingsService $settings) {}

    /** Production daily cap: the /admin value, else the env cap. */
    public function dailyCapUsd(): float
    {
        return $this->settings->storedAiBudget()['daily_usd'] ?? $this->envDailyCapUsd();
    }

    /** Production monthly cap: the /admin value, else the env cap. */
    public function monthlyCapUsd(): float
    {
        return $this->settings->storedAiBudget()['monthly_usd'] ?? $this->envMonthlyCapUsd();
    }

    /** AI_DAILY_BUDGET_USD — the default when /admin has no value. */
    public function envDailyCapUsd(): float
    {
        return max(0.0, (float) config('media.budget.daily_usd', 5));
    }

    /** AI_MONTHLY_BUDGET_USD — the default when /admin has no value. */
    public function envMonthlyCapUsd(): float
    {
        return max(0.0, (float) config('media.budget.monthly_usd', 50));
    }

    /** AI Lab daily cap: the /admin value (M4-07c), else AI_LAB_DAILY_USD. */
    public function labDailyCapUsd(): float
    {
        return $this->settings->storedAiLabBudget()['daily_usd'] ?? $this->envLabDailyCapUsd();
    }

    /** AI Lab monthly cap: the /admin value (M4-07c), else AI_LAB_MONTHLY_USD. */
    public function labMonthlyCapUsd(): float
    {
        return $this->settings->storedAiLabBudget()['monthly_usd'] ?? $this->envLabMonthlyCapUsd();
    }

    /** Most one AI Lab run may cost: the /admin value (M4-07c), else AI_LAB_MAX_RUN_USD. */
    public function labMaxRunUsd(): float
    {
        return $this->settings->storedAiLabBudget()['max_run_usd'] ?? $this->envLabMaxRunUsd();
    }

    public function envLabDailyCapUsd(): float
    {
        return max(0.0, (float) config('media.lab.daily_usd', 3));
    }

    public function envLabMonthlyCapUsd(): float
    {
        return max(0.0, (float) config('media.lab.monthly_usd', 30));
    }

    public function envLabMaxRunUsd(): float
    {
        return max(0.0, (float) config('media.lab.max_run_usd', 3));
    }

    public function timezone(): string
    {
        return (string) config('media.budget.timezone', 'UTC');
    }

    /** Production spend today (lab excluded), or lab spend today with $lab = true. */
    public function spentTodayUsd(bool $lab = false): float
    {
        return $this->spentSince($this->now()->startOfDay(), $lab);
    }

    public function spentThisMonthUsd(bool $lab = false): float
    {
        return $this->spentSince($this->now()->startOfMonth(), $lab);
    }

    public function spentThisMonthUsdFor(AiSpendPurpose $purpose): float
    {
        return round((float) AiSpendLedger::query()->counted()
            ->where('purpose', $purpose->value)
            ->where('created_at', '>=', $this->now()->startOfMonth()->utc())
            ->sum('cost_usd'), 4);
    }

    /**
     * Could an estimated amount still be spent now for this purpose?
     * (UI pre-check / retry sweep; reserve() is the real gate.)
     */
    public function refusalFor(float $costUsd, AiSpendPurpose $purpose): ?AiCallFailure
    {
        if ($purpose === AiSpendPurpose::Lab) {
            return ($this->spentTodayUsd(true) + $costUsd > $this->labDailyCapUsd() + 1e-9
                || $this->spentThisMonthUsd(true) + $costUsd > $this->labMonthlyCapUsd() + 1e-9)
                ? AiCallFailure::BudgetLab
                : null;
        }

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
    public function reserve(ModelProfile $profile, AiSpendPurpose $purpose, ?int $petId = null, ?int $labResultId = null, ?int $petMediaId = null): AiSpendLedger
    {
        $cost = $profile->estimatedCostUsd();

        // M4-07b: warm the admin budget before the advisory lock, so the read
        // inside it is a cache hit (no cache write / slow store while locked).
        $this->settings->storedAiBudget();
        $this->settings->storedAiLabBudget();

        return DB::transaction(function () use ($profile, $purpose, $petId, $labResultId, $petMediaId, $cost) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY]);
            }

            $refusal = $this->refusalFor($cost, $purpose);

            if ($refusal !== null) {
                throw new AiCallException($refusal, $this->describe($refusal, $cost, $profile->key));
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
                'pet_media_id' => $petMediaId,
                'media_lab_result_id' => $labResultId,
            ]);
        });
    }

    /**
     * The request reached fal: the cost counts. $reason records a call that
     * reached fal but whose answer we lost (timeout / reset → http_error).
     */
    public function commit(AiSpendLedger $entry, ?string $requestId = null, ?AiCallFailure $reason = null): void
    {
        $entry->update([
            'status' => AiSpendLedger::STATUS_COMMITTED,
            'request_id' => $requestId ?? $entry->request_id,
            'error_reason' => $reason?->value,
        ]);
    }

    /** fal never got the request (connect / DNS failure) or explicitly refused it: the cost does not count. */
    public function void(AiSpendLedger $entry, AiCallFailure $reason): void
    {
        $entry->update(['status' => AiSpendLedger::STATUS_VOID, 'error_reason' => $reason->value]);
    }

    public function describe(AiCallFailure $refusal, float $cost, string $what): string
    {
        if ($refusal === AiCallFailure::BudgetLab) {
            return sprintf(
                '%s: lab today $%.2f of $%.2f, this month $%.2f of $%.2f, needs ~$%.4f (%s).',
                $refusal->label(),
                $this->spentTodayUsd(true),
                $this->labDailyCapUsd(),
                $this->spentThisMonthUsd(true),
                $this->labMonthlyCapUsd(),
                $cost,
                $what,
            );
        }

        return sprintf(
            '%s: today $%.2f of $%.2f, this month $%.2f of $%.2f, needs ~$%.4f (%s).',
            $refusal->label(),
            $this->spentTodayUsd(),
            $this->dailyCapUsd(),
            $this->spentThisMonthUsd(),
            $this->monthlyCapUsd(),
            $cost,
            $what,
        );
    }

    private function spentSince(CarbonImmutable $localStart, bool $lab): float
    {
        return round((float) AiSpendLedger::query()->counted()
            ->when($lab,
                fn (Builder $q) => $q->where('purpose', AiSpendPurpose::Lab->value),
                fn (Builder $q) => $q->where('purpose', '!=', AiSpendPurpose::Lab->value))
            ->where('created_at', '>=', $localStart->utc())
            ->sum('cost_usd'), 4);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }
}
