<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\Media\ItemMediaService;
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
 *
 * David 2026-11-19: `$count` null = the durable-item rows given up AT ONCE after their check tries
 * in the window that ends now (ItemMediaService::countGiveUp() — one post per 15-min window).
 */
class SendItemMediaAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?int $count = null, public readonly int $locked = 0)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        $count = $this->count ?? ItemMediaService::takeGiveUpCount();

        if ($count < 1 || ! $discord->configured()) {
            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::ItemMediaStuck,
                __('admin.item_media.discord.title', ['count' => $count]),
                $this->locked > 0
                    ? __('admin.item_media.discord.body_locked', ['locked' => $this->locked, 'others' => max(0, $count - $this->locked)])
                    : __('admin.item_media.discord.body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
