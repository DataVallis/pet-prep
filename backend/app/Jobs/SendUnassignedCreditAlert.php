<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\UnassignedCreditMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * M6-U01 safety net: challenge purchases that stayed unassigned (queued by
 * `billing:unassigned-credits-check`, once per purchase — UnassignedCreditMonitor).
 * Ids and counts only. A failed post releases the claims, so the next run retries.
 * Low-priority `alerts` queue, like the other admin alerts.
 */
class SendUnassignedCreditAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  list<int>  $creditIds
     * @param  list<int>  $familyIds
     * @param  list<string>  $environments
     */
    public function __construct(
        public readonly array $creditIds,
        public readonly array $familyIds,
        public readonly array $environments,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            UnassignedCreditMonitor::release($this->creditIds);

            return;
        }

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::UnassignedChallengeCredit,
                __('admin.unassigned_credit.discord.title', ['count' => count($this->creditIds), 'minutes' => UnassignedCreditMonitor::AFTER_MINUTES]),
                __('admin.unassigned_credit.discord.body'),
                [
                    __('admin.unassigned_credit.discord.credits') => implode(', ', $this->creditIds),
                    __('admin.unassigned_credit.discord.families') => implode(', ', $this->familyIds),
                    __('admin.unassigned_credit.discord.environments') => implode(', ', $this->environments),
                ],
            ));
        } catch (DiscordWebhookException $e) {
            UnassignedCreditMonitor::release($this->creditIds);
            report($e);
        }
    }
}
