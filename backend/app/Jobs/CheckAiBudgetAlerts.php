<?php

namespace App\Jobs;

use App\Services\Media\AiBudgetMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * M5-T15: refresh the fal.ai balance (free Platform API read, at most every
 * 15 min) and announce newly reached AI budget thresholds to the superadmins.
 * Dispatched by `ai:budget-check` every 5 minutes; unique, so a slow run is
 * never doubled. HTTP and notifications run here, never in a transaction.
 */
class CheckAiBudgetAlerts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function handle(AiBudgetMonitor $monitor): void
    {
        try {
            $monitor->refreshFalBalance();
        } catch (Throwable $e) {
            // The balance read is best effort — the budget warnings must still go out.
            report($e);
        }

        $monitor->check();
    }
}
