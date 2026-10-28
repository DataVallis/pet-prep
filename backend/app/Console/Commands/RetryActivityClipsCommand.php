<?php

namespace App\Console\Commands;

use App\Services\Media\ActivityClipRetryService;
use Illuminate\Console\Command;

/**
 * Hourly (David 2026-10-28, confirmed): no dead end for a school / game locked by a failed
 * clip — transient failures of its reference image / pose images / phase clips are re-queued
 * (2 automatic retries per failure chain, within today's AI budget), the rest is reported to
 * the admin once per chain. `--dry-run` lists what it would do (pet ids, clips), changes nothing.
 */
class RetryActivityClipsCommand extends Command
{
    protected $signature = 'media:retry-activity-clips {--dry-run : List only, change nothing} {--limit=200 : Actions (retries + reports) per run}';

    protected $description = 'Re-queue failed school / game clips that keep an activity locked; report the ones that need an admin';

    public function handle(ActivityClipRetryService $retry): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $retry->run($dry, max(1, (int) $this->option('limit')));

        if ($dry && $result['items'] !== []) {
            $this->table(['pet', 'kind', 'clip', 'reason', 'auto retries', 'action'], array_map(fn (array $i) => array_values($i), $result['items']));
        }

        $this->info(($dry ? '[dry run] ' : '')."Re-queued {$result['retried']}, reported {$result['reported']} to the admin, {$result['skipped_budget']} left for later (today's AI budget).");

        return self::SUCCESS;
    }
}
