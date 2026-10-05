<?php

namespace App\Console\Commands;

use App\Services\Media\ReferenceImageRetryService;
use Illuminate\Console\Command;

/**
 * Daily: re-queue reference images and state videos blocked by the production
 * budget or an exhausted fal balance, as far as today's budget allows
 * (PR #22 review; videos since M4-03).
 */
class RetryReferenceImagesCommand extends Command
{
    protected $signature = 'media:retry {--limit=100 : Maximum media items to re-queue}';

    /** @var list<string> */
    protected $aliases = ['media:retry-references'];

    protected $description = 'Re-queue AI reference images and state videos blocked by the AI budget or fal balance';

    public function handle(ReferenceImageRetryService $retries): int
    {
        $queued = $retries->retryDueWithVideos(max(1, (int) $this->option('limit')));

        $this->info("Re-queued {$queued['images']} reference image(s) and {$queued['videos']} state video(s).");

        return self::SUCCESS;
    }
}
