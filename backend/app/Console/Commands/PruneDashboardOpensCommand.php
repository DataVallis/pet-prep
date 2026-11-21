<?php

namespace App\Console\Commands;

use App\Models\ParentDashboardOpen;
use App\Services\Validation\DashboardOpenService;
use Illuminate\Console\Command;

/**
 * Daily cleanup (H6, VALIDATION_PLAN §4.1, David 2026-11-21): parent dashboard opens older than
 * ParentDashboardOpen::RETENTION_DAYS (400) are deleted.
 */
class PruneDashboardOpensCommand extends Command
{
    protected $signature = 'dashboard-opens:prune';

    protected $description = 'Delete parent dashboard opens older than 400 days';

    public function handle(DashboardOpenService $opens): int
    {
        $deleted = $opens->prune();

        $this->info("Pruned {$deleted} dashboard open(s) older than ".ParentDashboardOpen::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
