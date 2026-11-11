<?php

namespace App\Console\Commands;

use App\Services\UnassignedCreditMonitor;
use Illuminate\Console\Command;

/**
 * M6-U01 safety net (FREE_PET_UPGRADE_SPEC §5.3): one Discord alert per
 * challenge purchase that stayed unassigned > 10 min in a family with a pet
 * waiting for payment or a purchase intent (UnassignedCreditMonitor).
 */
class CheckUnassignedCreditsCommand extends Command
{
    protected $signature = 'billing:unassigned-credits-check';

    protected $description = 'Discord alert once per 12-week challenge purchase left unassigned > 10 min while the family waits for it';

    public function handle(UnassignedCreditMonitor $monitor): int
    {
        $ids = $monitor->check();
        $this->info($ids === [] ? 'No unassigned challenge purchase to report.' : 'Alert queued for credits '.implode(', ', $ids).'.');

        return self::SUCCESS;
    }
}
