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
 * David 2026-11-28: ONE Discord post per window (StillQualityService::ALERT_WINDOW_MINUTES) with the
 * number of life-stage images that still failed the »looks older« check after their automatic
 * remakes (enforce mode) — the last image of each was kept and is shown. Counts only — no pet,
 * look, family or child.
 */
class SendStageImageAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** `$count` null = take the count of the window that ends now (StillQualityService::countStageAlert). */
    public function __construct(public readonly ?int $count = null)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        $count = $this->count ?? StillQualityService::takeStageAlertCount();

        if ($count < 1 || ! $discord->configured()) {
            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::StageImageNotOlder,
                __('admin.stage_check.discord.title', ['count' => $count]),
                __('admin.stage_check.discord.body'),
                [],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
