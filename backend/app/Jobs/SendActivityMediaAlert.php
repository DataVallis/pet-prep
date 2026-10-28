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

/**
 * David 2026-10-28 (home first, school and games locked until their clips are ready):
 * ONE Discord post per `media:retry-activity-clips` run that found school / game clips
 * which failed for good — those activities stay locked until an admin acts. Counts only
 * (no pet, family or child; the command's --dry-run lists the pets). Low-priority
 * `alerts` queue, like the app error alerts.
 */
class SendActivityMediaAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $count)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if ($this->count < 1 || ! $discord->configured()) {
            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::ActivityMediaStuck,
                __('admin.activity_media.discord.title', ['count' => $this->count]),
                __('admin.activity_media.discord.body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
