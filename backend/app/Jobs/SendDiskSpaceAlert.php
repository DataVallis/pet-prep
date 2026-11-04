<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

/**
 * D31: the root disk is ≥ the alert threshold (queued by `system-health:disk-check`, at most
 * once per day). Numbers only. A failed post releases the day's claim, so the next hourly
 * check tries again. Low-priority `alerts` queue, like the other admin alerts.
 */
class SendDiskSpaceAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly float $usedPercent,
        public readonly int $freeBytes,
        public readonly int $totalBytes,
        public readonly int $thresholdPercent,
        public readonly string $claimKey,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            Cache::forget($this->claimKey);

            return;
        }

        $percent = number_format($this->usedPercent, 1).' %';

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::DiskSpace,
                __('admin.disk_space.discord.title', ['percent' => $percent]),
                __('admin.disk_space.discord.body', ['threshold' => $this->thresholdPercent]),
                [
                    __('admin.disk_space.discord.used') => $percent,
                    __('admin.disk_space.discord.free') => Number::fileSize($this->freeBytes, 1),
                    __('admin.disk_space.discord.total') => Number::fileSize($this->totalBytes, 1),
                    __('admin.disk_space.discord.threshold') => $this->thresholdPercent.' %',
                ],
            ));
        } catch (DiscordWebhookException $e) {
            Cache::forget($this->claimKey);
            report($e);
        }
    }
}
