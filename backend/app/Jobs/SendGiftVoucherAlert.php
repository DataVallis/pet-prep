<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * M6-S01 (CONTRACT §6.4): a website gift voucher needs an admin's eye. Ids only —
 * never a code, an e-mail or a name. Dispatched after commit (DiscordWebhook
 * refuses to run inside a transaction). Low-priority `alerts` queue.
 */
class SendGiftVoucherAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const REASON_ACTIVATED_ELSEWHERE = 'activated_elsewhere';

    public const REASON_PARTIAL_REFUND = 'partial_refund';

    public const REASON_INSTANCE_MISMATCH = 'instance_mismatch';

    public int $tries = 1;

    public function __construct(
        public readonly string $reason,
        public readonly ?int $voucherId,
        public readonly ?int $lsOrderId,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            return;
        }

        $key = 'admin.gift_voucher.discord';
        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::GiftVoucherIssue,
                __($key.'.'.$this->reason.'.title'),
                __($key.'.'.$this->reason.'.body'),
                [
                    __($key.'.voucher') => $this->voucherId === null ? '—' : (string) $this->voucherId,
                    __($key.'.order') => $this->lsOrderId === null ? '—' : (string) $this->lsOrderId,
                ],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
