<?php

namespace App\Console\Commands;

use App\Services\Media\ActivityClipRetryService;
use Illuminate\Console\Command;

/**
 * Hourly (David 2026-10-28): no dead end for a school / game locked by a failed clip —
 * transient failures of its pose images / phase clips are re-queued (capped), the rest
 * is reported to the admin once. `--dry-run` lists what it would do (pet ids, clips).
 */
class RetryActivityClipsCommand extends Command
{
    protected $signature = 'media:retry-activity-clips {--dry-run : List only, change nothing} {--limit=200}';

    protected $description = 'Re-queue failed school / game clips that keep an activity locked; report the ones that need an admin';

    public function handle(ActivityClipRetryService $retry): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $retry->run($dry, max(1, (int) $this->option('limit')));

        if ($dry && $result['items'] !== []) {
            $this->table(['pet', 'kind', 'clip', 'reason', 'attempts', 'action'], array_map(fn (array $i) => array_values($i), $result['items']));
        }

        $this->info(($dry ? '[dry run] ' : '')."Re-queued {$result['retried']}, reported {$result['reported']} to the admin, {$result['waiting']} waiting for the retry delay.");

        return self::SUCCESS;
    }
}
