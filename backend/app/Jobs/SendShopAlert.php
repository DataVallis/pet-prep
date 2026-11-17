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
 * M6-S09: the website shop needs an admin's eye (partial refund, dispute, e-mail that
 * failed every retry). Ids only — never a code, an e-mail address or a name. Dispatched
 * after commit (DiscordWebhook refuses to run inside a transaction). `alerts` queue.
 */
class SendShopAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const REASON_PARTIAL_REFUND = 'partial_refund';

    public const REASON_DISPUTE_OPENED = 'dispute_opened';

    public const REASON_DISPUTE_LOST = 'dispute_lost';

    public const REASON_EMAIL_FAILED = 'email_failed';

    public const REASON_REJECTED_PAYMENT = 'rejected_payment';

    public const REASON_SMTP_DOWN = 'smtp_down';

    public int $tries = 1;

    public function __construct(
        public readonly string $reason,
        public readonly ?int $orderId,
        public readonly ?int $emailId = null,
        /** A Stripe session id (rejected payment) or a short error class (SMTP down); never personal data. */
        public readonly ?string $ref = null,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            return;
        }
        $key = 'admin.shop.discord';
        try {
            $discord->send(new AdminAlert(
                AdminAlertKind::ShopIssue,
                __($key.'.'.$this->reason.'.title'),
                __($key.'.'.$this->reason.'.body'),
                array_filter([
                    __($key.'.order') => $this->orderId === null ? '—' : (string) $this->orderId,
                    __($key.'.email') => $this->emailId === null ? null : (string) $this->emailId,
                    __($key.'.ref') => $this->ref,
                ]),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
