<?php

namespace App\Jobs;

use App\Enums\AdminAlertKind;
use App\Models\AppErrorFingerprint;
use App\Models\AppErrorReport;
use App\Services\Alerts\AdminAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\Alerts\DiscordWebhookException;
use App\Services\AppErrors\AppErrorReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * M5-05: post a mobile app error to the admin Discord channel — a NEW
 * fingerprint (`app_error_new`) or one that reached the hourly spike
 * threshold (`app_error_spike`). At most one post per fingerprint per day
 * (AppErrorReportService::claimAlert; a claimed alert is never released, a
 * failed post is report()ed). Only operational facts: error name, scrubbed
 * message, scope, versions, platforms, counts — no user, family or pet.
 */
class SendAppErrorAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $fingerprint,
        public readonly AdminAlertKind $kind,
    ) {}

    public function handle(AppErrorReportService $errors, DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            return;
        }

        $error = AppErrorFingerprint::query()->find($this->fingerprint);
        if ($error === null || ! $errors->claimAlert($this->fingerprint)) {
            return;
        }

        $reports = AppErrorReport::query()->where('fingerprint', $this->fingerprint)->get(['app_version', 'platform']);

        $title = $this->kind === AdminAlertKind::AppErrorSpike
            ? __('admin.app_errors.discord.spike_title', ['name' => $error->error_name, 'count' => (int) config('system_health.app_errors.spike_per_hour')])
            : __('admin.app_errors.discord.new_title', ['name' => $error->error_name]);

        try {
            $discord->send(new AdminAlert(
                $this->kind,
                $title,
                Str::limit($error->message, 500),
                [
                    __('admin.app_errors.scope') => $error->scope,
                    __('admin.app_errors.versions') => $reports->pluck('app_version')->unique()->sort()->implode(', '),
                    __('admin.app_errors.platforms') => $reports->pluck('platform')->unique()->sort()->implode(', '),
                    __('admin.app_errors.last_24h') => (string) $errors->countSince($this->fingerprint, 24),
                    __('admin.app_errors.fingerprint') => substr($this->fingerprint, 0, 12),
                ],
            ));
        } catch (DiscordWebhookException $e) {
            report($e);
        }
    }
}
