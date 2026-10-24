<?php

namespace App\Enums;

/**
 * Kinds of admin alerts that can be posted to the admin Discord channel
 * (M5-T19, David 2026-10-24). Only the AI budget and fal.ai balance kinds are
 * wired today (AiBudgetMonitor); new kinds (e.g. System health errors, failed
 * deploys) are added here with their severity and admin page, then sent with
 * App\Services\Alerts\AdminAlert + DiscordWebhook from a queued job.
 */
enum AdminAlertKind: string
{
    case AiBudgetWarning = 'ai_budget_warning';
    case AiBudgetBlocked = 'ai_budget_blocked';
    case FalBalanceLow = 'fal_balance_low';
    case FalBalanceExhausted = 'fal_balance_exhausted';
    case Test = 'test';

    public const COLOUR_INFO = 0x3B82F6;

    public const COLOUR_WARNING = 0xF5C518;

    public const COLOUR_DANGER = 0xDC2626;

    /** info | warning | danger */
    public function severity(): string
    {
        return match ($this) {
            self::AiBudgetWarning, self::FalBalanceLow => 'warning',
            self::AiBudgetBlocked, self::FalBalanceExhausted => 'danger',
            self::Test => 'info',
        };
    }

    /** Discord embed colour: yellow for a warning, red for a blocked / exhausted state. */
    public function colour(): int
    {
        return match ($this->severity()) {
            'warning' => self::COLOUR_WARNING,
            'danger' => self::COLOUR_DANGER,
            default => self::COLOUR_INFO,
        };
    }

    /** The /admin page the alert links to. */
    public function adminPath(): string
    {
        return match ($this) {
            self::AiBudgetWarning, self::AiBudgetBlocked => '/admin/ai-budget',
            self::FalBalanceLow, self::FalBalanceExhausted, self::Test => '/admin/system-health',
        };
    }
}
