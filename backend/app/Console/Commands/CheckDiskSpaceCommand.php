<?php

namespace App\Console\Commands;

use App\Jobs\SendDiskSpaceAlert;
use App\Services\Alerts\DiscordWebhook;
use App\Services\SystemHealth\DiskSpace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * DEPLOYMENT.md D31 (incident 2026-11-04 — the root disk reached 100 %, Postgres went down):
 * hourly check of the server's root disk (the storage volume lives on it). At ≥
 * system_health.disk_alert_percent it queues ONE Discord `disk_space` alert per day
 * (Europe/Ljubljana; claimed with Cache::add, released again when the post fails so
 * the next hourly run retries). Numbers only. No Discord webhook → nothing is claimed.
 */
class CheckDiskSpaceCommand extends Command
{
    public const CLAIM_PREFIX = 'system_health:disk_alert:';

    protected $signature = 'system-health:disk-check';

    protected $description = 'Post one Discord admin alert per day when the root disk is ≥ the alert threshold (default 80 %)';

    public function handle(DiskSpace $disk, DiscordWebhook $discord): int
    {
        $usage = $disk->usage();
        if ($usage === null) {
            $this->warn('Disk size not available.');

            return self::SUCCESS;
        }

        $threshold = (int) config('system_health.disk_alert_percent', 80);
        $this->info(sprintf('Disk %.1f %% used (%d bytes free of %d), alert at %d %%.', $usage['used_percent'], $usage['free'], $usage['total'], $threshold));

        if ($usage['used_percent'] < $threshold || ! $discord->configured()) {
            return self::SUCCESS;
        }

        $key = self::claimKey();
        if (! Cache::add($key, true, now()->addHours(48))) {
            $this->info('Disk alert already sent today.');

            return self::SUCCESS;
        }

        SendDiskSpaceAlert::dispatch($usage['used_percent'], $usage['free'], $usage['total'], $threshold, $key);
        $this->info('Disk alert queued.');

        return self::SUCCESS;
    }

    public static function claimKey(): string
    {
        $tz = (string) config('system_health.disk_alert_timezone', 'Europe/Ljubljana');

        return self::CLAIM_PREFIX.Date::now()->setTimezone($tz)->toDateString();
    }
}
