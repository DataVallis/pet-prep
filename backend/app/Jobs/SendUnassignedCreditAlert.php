<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\UnassignedCreditMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * M6-U01 safety net: challenge purchases that need an admin's eye. Ids and counts only.
 * Low-priority `alerts` queue, like the other admin alerts.
 *
 *  - REASON_UNASSIGNED: credits that stayed unassigned (queued by `billing:unassigned-credits-check`,
 *    claimed once per credit — UnassignedCreditMonitor). ANY failure (Discord down, a bug, a worker
 *    that gives up — `failed()`) releases the claims, so the next run retries: a credit is never
 *    left un-alerted for good (QA #978 c).
 *  - REASON_INTENT_OVERRIDDEN (QA #978 b): a purchase with an upgrade intent for a free pet went to
 *    the family's only payment-locked pet instead (the old rule — never a paid-for pet left
 *    locked); the parent may have wanted the upgrade. Sent once from the webhook, no claim.
 */
class SendUnassignedCreditAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const REASON_UNASSIGNED = 'unassigned';

    public const REASON_INTENT_OVERRIDDEN = 'intent_overridden';

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
        public readonly string $reason = self::REASON_UNASSIGNED,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            $this->releaseClaims();

            return;
        }

        $overridden = $this->reason === self::REASON_INTENT_OVERRIDDEN;
        $key = $overridden ? 'admin.unassigned_credit.overridden' : 'admin.unassigned_credit.discord';

        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::UnassignedChallengeCredit,
                __($key.'.title', ['count' => count($this->creditIds), 'minutes' => UnassignedCreditMonitor::AFTER_MINUTES]),
                __($key.'.body'),
                [
                    __('admin.unassigned_credit.discord.credits') => implode(', ', $this->creditIds),
                    __('admin.unassigned_credit.discord.families') => implode(', ', $this->familyIds),
                    __('admin.unassigned_credit.discord.environments') => implode(', ', $this->environments),
                ],
            ));
        } catch (Throwable $e) {
            $this->releaseClaims();
            report($e);
        }
    }

    /** The worker gave up on the job (timeout, crash): the next run retries. */
    public function failed(?Throwable $e = null): void
    {
        $this->releaseClaims();
    }

    /** Free the per-credit claims (only the monitor's alerts have them). */
    public function releaseClaims(): void
    {
        if ($this->reason === self::REASON_UNASSIGNED) {
            UnassignedCreditMonitor::release($this->creditIds);
        }
    }
}
