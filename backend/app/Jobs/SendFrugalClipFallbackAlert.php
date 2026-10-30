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
 * Frugal clips (David 2026-10-30, QA #451 m2): a server-made reversal (sleeping_to_idle) or
 * ping-pong loop failed for good, so that clip is now generated the normal way through fal
 * (it costs the usual price). Counts only — no pet, family or child. Low-priority `alerts`
 * queue, like the other admin alerts.
 */
class SendFrugalClipFallbackAlert implements ShouldQueue
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
                AdminAlertKind::FrugalClipFallback,
                __('admin.frugal_clips.discord.title', ['count' => $this->count]),
                __('admin.frugal_clips.discord.body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
