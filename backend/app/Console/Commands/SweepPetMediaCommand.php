<?php

namespace App\Console\Commands;

use App\Services\Media\PetMediaService;
use Illuminate\Console\Command;

/**
 * Hourly (M4-03): recover pet media left behind by a lost job or webhook —
 * submitted videos without a webhook after 2 h fail as `timed_out`, lost
 * downloads and dead-worker claims are queued again.
 */
class SweepPetMediaCommand extends Command
{
    protected $signature = 'media:sweep';

    protected $description = 'Recover pet media slots stuck after a lost job or fal webhook';

    public function handle(PetMediaService $media): int
    {
        $result = $media->sweepStale();

        $this->info("Timed out {$result['timed_out']}, re-queued {$result['downloads']} download(s) and {$result['reclaimed']} stale claim(s), deleted {$result['temp_files']} stale temp file(s).");

        return self::SUCCESS;
    }
}
