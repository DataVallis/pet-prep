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
 * M6-W03: ONE Discord post per `media:item-media` run whose self-healing gave up item content
 * (catalogue pictures, item images / clips). Counts only — no pet, family or child. Informative:
 * durable items are already shown without that content; /admin can Regenerate. `$locked` of them
 * are ACTION item rows (treats, David 2026-11-15): »Priboljšek« stays disabled for those pets
 * until the row is regenerated — the alert then asks for it.
 */
class SendItemMediaAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $count, public readonly int $locked = 0)
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
                AdminAlertKind::ItemMediaStuck,
                __('admin.item_media.discord.title', ['count' => $this->count]),
                __('admin.item_media.discord.body').($this->locked > 0 ? "\n\n".__('admin.item_media.discord.locked', ['count' => $this->locked]) : ''),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
