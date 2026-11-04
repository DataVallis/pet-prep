<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\Media\StillQualityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * David 2026-11-04: ONE Discord post per window (StillQualityService::ALERT_WINDOW_MINUTES, QA #736
 * m1) with the number of state KEY images that failed the AI still check after their automatic
 * regenerations — those states get no new loop until an admin approves / regenerates them (the pet
 * keeps what it shows). Counts only — no pet, family or child.
 * Pose images are reported by ActivityClipRetryService (simple mode), item files by the item
 * media alert.
 */
class SendStillQualityAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** `$count` null = take the count of the window that ends now (StillQualityService::countAlert). */
    public function __construct(public readonly ?int $count = null)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        $count = $this->count ?? StillQualityService::takeAlertCount();

        if ($count < 1 || ! $discord->configured()) {
            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::StillQualityRejected,
                __('admin.still_check.discord.title', ['count' => $count]),
                __('admin.still_check.discord.body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
