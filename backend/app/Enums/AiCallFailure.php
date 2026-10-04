<?php

namespace App\Enums;

/**
 * Why a fal.ai call did not produce media (M4-07). Mirrored in CHECK
 * constraints on ai_spend_ledger.error_reason, media_lab_results.error_reason
 * and pets.media_error.
 */
enum AiCallFailure: string
{
    /** The daily estimated spend cap would be exceeded (AI_DAILY_BUDGET_USD). */
    case BudgetDaily = 'budget_daily';

    /** The monthly estimated spend cap would be exceeded (AI_MONTHLY_BUDGET_USD). */
    case BudgetMonthly = 'budget_monthly';

    /** One AI Lab run would cost more than AI_LAB_MAX_RUN_USD. */
    case BudgetRun = 'budget_run';

    /** fal.ai refused the call because the account balance is exhausted (HTTP 402 / 403 "Exhausted balance"). */
    case FalBalance = 'fal_balance';

    /** fal.ai answered with another non-2xx status or the request failed in transit. */
    case HttpError = 'http_error';

    /** 2xx without a usable media URL / request id. */
    case InvalidResponse = 'invalid_response';

    /** FAL_AI_API_KEY is not configured or the profile is disabled. */
    case Disabled = 'disabled';

    /** fal.ai reported the generation as failed (webhook ERROR / status poll). */
    case GenerationFailed = 'generation_failed';

    /**
     * Only transient failures are worth a queue retry; budget and balance
     * failures wait for the next budget day / month or a top-up.
     */
    public function retryable(): bool
    {
        return in_array($this, [self::HttpError, self::InvalidResponse], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::BudgetDaily => 'Daily AI budget reached',
            self::BudgetMonthly => 'Monthly AI budget reached',
            self::BudgetRun => 'Run exceeds the per-run lab limit',
            self::FalBalance => 'fal.ai balance exhausted — top up at fal.ai/dashboard/billing',
            self::HttpError => 'fal.ai request failed',
            self::InvalidResponse => 'fal.ai response had no usable media',
            self::Disabled => 'fal.ai disabled (no API key or profile disabled)',
            self::GenerationFailed => 'fal.ai reported a failed generation',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
