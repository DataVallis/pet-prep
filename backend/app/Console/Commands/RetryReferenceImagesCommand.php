<?php

namespace App\Console\Commands;

use App\Services\Media\ReferenceImageRetryService;
use Illuminate\Console\Command;

/**
 * Daily: re-queue reference images blocked by the production budget or an
 * exhausted fal balance, as far as today's budget allows (PR #22 review).
 */
class RetryReferenceImagesCommand extends Command
{
    protected $signature = 'media:retry-references {--limit=100 : Maximum pets to re-queue}';

    protected $description = 'Re-queue reference images blocked by the AI budget or fal balance';

    public function handle(ReferenceImageRetryService $retries): int
    {
        $queued = $retries->retryDue(max(1, (int) $this->option('limit')));

        $this->info("Re-queued {$queued} reference image(s).");

        return self::SUCCESS;
    }
}
