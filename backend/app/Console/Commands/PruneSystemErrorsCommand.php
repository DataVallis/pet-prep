<?php

namespace App\Console\Commands;

use App\Services\AppErrors\AppErrorReportService;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Command;

/**
 * System health (David 2026-10-19): delete resolved errors older than 30
 * days, every error not seen for 90 days, and hourly chart buckets older
 * than 14 days. M5-05: also mobile app error reports (fingerprints, per-version
 * rows and hourly buckets) not seen for 30 days.
 */
class PruneSystemErrorsCommand extends Command
{
    protected $signature = 'system-health:prune';

    protected $description = 'Delete old resolved / stale system errors and old hourly error buckets';

    public function handle(SystemHealthService $health, AppErrorReportService $appErrors): int
    {
        $result = $health->prune();
        $this->info("Deleted {$result['errors']} errors and {$result['hours']} hourly buckets.");

        $app = $appErrors->prune();
        $this->info("Deleted {$app['fingerprints']} app errors, {$app['reports']} app error versions and {$app['hours']} app error buckets.");

        return self::SUCCESS;
    }
}
