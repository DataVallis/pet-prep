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
 * QA #1139 M2 (David 2026-11-15, the general media rule): the daily watchdog of
 * `media:item-media` — `$count` active pets have treats left but »Priboljšek« has been disabled
 * (»se pripravlja«) for more than `$hours` hours, whatever the cause (AI budget wait, a given-up
 * chain whose own alert was lost, a pet picture that never came, a stuck request). ONE Discord
 * post per Ljubljana day while it lasts (claim in ItemMediaService::watch()). Counts only — no
 * pet, family or child.
 */
class SendTreatsWaitingAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $count, public readonly int $hours)
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
                __('admin.item_media.discord.waiting_title', ['count' => $this->count, 'hours' => $this->hours]),
                __('admin.item_media.discord.waiting_body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
