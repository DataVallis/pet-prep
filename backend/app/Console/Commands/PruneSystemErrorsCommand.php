<?php

namespace App\Console\Commands;

use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Command;

/**
 * System health (David 2026-10-19): delete resolved errors older than 30
 * days, every error not seen for 90 days, and hourly chart buckets older
 * than 14 days.
 */
class PruneSystemErrorsCommand extends Command
{
    protected $signature = 'system-health:prune';

    protected $description = 'Delete old resolved / stale system errors and old hourly error buckets';

    public function handle(SystemHealthService $health): int
    {
        $result = $health->prune();
        $this->info("Deleted {$result['errors']} errors and {$result['hours']} hourly buckets.");

        return self::SUCCESS;
    }
}
