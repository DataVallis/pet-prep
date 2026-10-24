<?php

namespace App\Console\Commands;

use App\Services\CareReminderPushService;
use App\Services\CareTaskActivationService;
use Illuminate\Console\Command;

/**
 * Breed care tasks bookkeeping (M5-R14 – R20 follow-up, David 2026-10-24),
 * every 5 minutes:
 *  1. existing dogs whose children's and a parent's apps now declare
 *     `care_tasks` are switched on from the next family-local midnight
 *     (CareTaskActivationService — idempotent, never retroactive);
 *  2. the parent reminder push of every groomer / bath period that started
 *     (CareReminderPushService — once per period).
 * `--dry-run` only counts the dogs step 1 would switch on (no writes, no pushes).
 */
class CareTasksCommand extends Command
{
    protected $signature = 'pets:care-tasks {--dry-run : Count the dogs that would be switched on; change nothing, send nothing}';

    protected $description = 'Switch breed care tasks on for existing dogs (from the next midnight) and push the parent groomer / bath reminders';

    public function handle(CareTaskActivationService $activation, CareReminderPushService $reminders): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $activation->run(dryRun: $dry);
        $this->info(($dry ? '[dry run] ' : '')."Care tasks: {$result['candidates']} candidate dog(s), {$result['activated']} switched on, {$result['waiting']} waiting for the apps.");
        if ($dry) {
            return self::SUCCESS;
        }

        $pushes = $reminders->processDue();
        $this->info("Care reminders: checked {$pushes['pets']} dog(s), {$pushes['pushed']} push decision(s).");

        return self::SUCCESS;
    }
}
