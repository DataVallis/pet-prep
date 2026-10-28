<?php

namespace App\Console\Commands;

use App\Services\Media\PetMediaService;
use Illuminate\Console\Command;

/**
 * Hourly (M4-03): recover pet media left behind by a lost job or webhook —
 * submitted videos without a webhook after 2 h fail as `timed_out`; queued key /
 * pose images without a webhook are polled at fal from the first sweep ≥ 10 min
 * after the submit (free; fix 2026-10-28) and time out after 2 h; lost downloads and dead-worker claims are
 * queued again.
 */
class SweepPetMediaCommand extends Command
{
    protected $signature = 'media:sweep';

    protected $description = 'Recover pet media slots stuck after a lost job or fal webhook';

    public function handle(PetMediaService $media): int
    {
        $result = $media->sweepStale();

        $this->info("Timed out {$result['timed_out']}, re-queued {$result['downloads']} download(s) and {$result['reclaimed']} stale claim(s), resumed the state media of {$result['resumed']} pet(s), deleted {$result['temp_files']} stale temp file(s). Settled {$result['polled']} queued key / pose image(s) by polling fal.");

        return self::SUCCESS;
    }
}
