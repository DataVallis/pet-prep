<?php

namespace App\Console\Commands;

use App\Jobs\CheckAiBudgetAlerts;
use Illuminate\Console\Command;

/**
 * M5-T15: queue the AI budget / fal balance check (scheduled every 5 minutes).
 */
class CheckAiBudgetCommand extends Command
{
    protected $signature = 'ai:budget-check';

    protected $description = 'Queue the AI budget warning check (80 % / 100 % of every cap, fal.ai balance)';

    public function handle(): int
    {
        CheckAiBudgetAlerts::dispatch();
        $this->info('AI budget check queued.');

        return self::SUCCESS;
    }
}
