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
 * M6-S03: ONE Discord post per partner inquiry. The text carries the inquiry
 * type and the /admin link only — never the company, name, e-mail or message
 * (WEBSITE_SALES_SPEC §5.2); David reads the inquiry in /admin and answers himself.
 */
class SendPartnerInquiryAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $type)
    {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    public function handle(DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            return;
        }

        // The link also in the text, not only as the embed title link.
        $alert = new AdminAlert(
            AdminAlertKind::PartnerInquiry,
            __('admin.partner_inquiries.discord.title', ['type' => $this->type], 'en'),
            url(AdminAlertKind::PartnerInquiry->adminPath()),
        );

        try {
            $discord->send($alert);
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
