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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * M5-05: post a mobile app error to the admin Discord channel — a NEW
 * fingerprint (`app_error_new`) or one that reached the hourly spike
 * threshold (`app_error_spike`). At most one post per fingerprint per day
 * (AppErrorReportService::claimAlert; a claimed alert is never released, a
 * failed post is report()ed). Only operational facts: error name, scrubbed
 * message, scope, versions, platforms, counts — no user, family or pet.
 *
 * QA #375 M1: runs on its own low-priority queue (`system_health.app_errors.alert_queue`,
 * `alerts`, last in the production worker's list — never `default`), and at most
 * `discord_per_hour` (10) app error posts go out per clock hour across all
 * fingerprints; the rest are counted and summed in ONE `app_error_suppressed` post
 * (SendAppErrorSuppressedNotice, delayed to the end of that hour).
 */
class SendAppErrorAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $fingerprint,
        public readonly AdminAlertKind $kind,
    ) {
        $this->onQueue((string) config('system_health.app_errors.alert_queue', 'alerts'));
    }

    /** Cache key of the posts sent in the clock hour of `$at`. */
    public static function sentKey(Carbon $at): string
    {
        return 'app-errors:discord:sent:'.$at->copy()->utc()->format('YmdH');
    }

    /** Cache key of the posts suppressed in the clock hour of `$at`. */
    public static function suppressedKey(Carbon $at): string
    {
        return 'app-errors:discord:suppressed:'.$at->copy()->utc()->format('YmdH');
    }

    public function handle(AppErrorReportService $errors, DiscordWebhook $discord): void
    {
        if (! $discord->configured()) {
            return;
        }

        $error = AppErrorFingerprint::query()->find($this->fingerprint);
        if ($error === null || ! $errors->claimAlert($this->fingerprint)) {
            return;
        }

        $now = Carbon::now();
        Cache::add(self::sentKey($now), 0, 7200);
        if ((int) Cache::increment(self::sentKey($now)) > (int) config('system_health.app_errors.discord_per_hour')) {
            Cache::add(self::suppressedKey($now), 0, 7200);
            if ((int) Cache::increment(self::suppressedKey($now)) === 1) {
                SendAppErrorSuppressedNotice::dispatch($now->copy()->utc()->format('YmdH'))
                    ->delay($now->copy()->utc()->startOfHour()->addHour()->addMinute());
            }

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
