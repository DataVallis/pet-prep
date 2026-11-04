<?php

namespace App\Enums;

/**
 * Kinds of admin alerts that can be posted to the admin Discord channel
 * (M5-T19, David 2026-10-24). AI budget and fal.ai balance kinds come from
 * AiBudgetMonitor; the app error kinds (M5-05) from SendAppErrorAlert — a NEW
 * mobile app error fingerprint, or one fingerprint reaching the hourly spike
 * threshold (at most one post per fingerprint per day). New kinds (e.g. failed
 * deploys) are added here with their severity and admin page, then sent with
 * App\Services\Alerts\AdminAlert + DiscordWebhook from a queued job.
 */
enum AdminAlertKind: string
{
    case AiBudgetWarning = 'ai_budget_warning';
    case AiBudgetBlocked = 'ai_budget_blocked';
    case FalBalanceLow = 'fal_balance_low';
    case FalBalanceExhausted = 'fal_balance_exhausted';
    case AppErrorNew = 'app_error_new';
    case AppErrorSpike = 'app_error_spike';
    /** QA #375 M1: app error posts above the global hourly cap, summed in one post. */
    case AppErrorSuppressed = 'app_error_suppressed';
    /** David 2026-10-28: a school / game clip failed for good; since 2026-11-02 the activity then runs in simple mode (informative — no admin action needed). */
    case ActivityMediaStuck = 'activity_media_stuck';
    /** David 2026-10-30 (QA #451 m2): a frugal reversal / ping-pong failed for good — made by fal instead. */
    case FrugalClipFallback = 'frugal_clip_fallback';
    /** M6-W03: AI content of »Želje« items given up after its automatic recovery (the items are shown without it — informative). */
    case ItemMediaStuck = 'item_media_stuck';
    /** David 2026-11-04: a state key image failed the AI still check after its automatic regenerations (needs review — informative). */
    case StillQualityRejected = 'still_quality_rejected';
    /** DEPLOYMENT.md D31 (incident 2026-11-04): the server's root disk is ≥ system_health.disk_alert_percent full (once per day, numbers only). */
    case DiskSpace = 'disk_space';
    /** David 2026-11-04 (after PR #737): a pet's game-loop work threw in `pets:process-decay` — the other pets were processed (once per pet per day; pet id + stage + exception class only). */
    case GameLoopPetError = 'game_loop_pet_error';
    /** DEPLOYMENT.md D34 (incident 2026-11-04): the `pets:process-decay` heartbeat is older than system_health.heartbeat_stale_minutes (once per stall episode, minutes only). */
    case GameLoopStalled = 'game_loop_stalled';
    case Test = 'test';

    public const COLOUR_INFO = 0x3B82F6;

    public const COLOUR_WARNING = 0xF5C518;

    public const COLOUR_DANGER = 0xDC2626;

    /** info | warning | danger */
    public function severity(): string
    {
        return match ($this) {
            self::AiBudgetWarning, self::FalBalanceLow, self::AppErrorNew, self::AppErrorSuppressed, self::ActivityMediaStuck, self::FrugalClipFallback, self::ItemMediaStuck, self::StillQualityRejected => 'warning',
            self::DiskSpace, self::GameLoopPetError, self::GameLoopStalled => 'danger',
            self::AiBudgetBlocked, self::FalBalanceExhausted, self::AppErrorSpike => 'danger',
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
            self::ActivityMediaStuck, self::FrugalClipFallback, self::StillQualityRejected => '/admin/pets',
            self::ItemMediaStuck => '/admin/item-media',
            self::FalBalanceLow, self::FalBalanceExhausted, self::AppErrorNew, self::AppErrorSpike, self::AppErrorSuppressed, self::DiskSpace, self::GameLoopPetError, self::GameLoopStalled, self::Test => '/admin/system-health',
        };
    }
}
