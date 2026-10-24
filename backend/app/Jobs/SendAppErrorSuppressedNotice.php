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

/**
 * M5-05 (QA #375 M1): ONE Discord post »N more app error alerts suppressed« for a
 * clock hour in which SendAppErrorAlert reached `discord_per_hour`. Dispatched once
 * per hour (the first suppressed post), delayed to the end of that hour; reads and
 * forgets the hour's counter. Same low-priority queue as SendAppErrorAlert.
 */
class SendAppErrorSuppressedNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  string  $hour  `YmdH` (UTC) of the hour whose posts were suppressed */
    public function __construct(public readonly string $hour)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        $key = 'app-errors:discord:suppressed:'.$this->hour;
        $count = (int) Cache::pull($key, 0);
        if ($count < 1 || ! $discord->configured()) {
            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::AppErrorSuppressed,
                __('admin.app_errors.discord.suppressed_title', ['count' => $count]),
                __('admin.app_errors.discord.suppressed_body', ['max' => (int) config('system_health.app_errors.discord_per_hour')]),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
