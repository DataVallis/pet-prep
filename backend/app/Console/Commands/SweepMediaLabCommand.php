<?php

namespace App\Console\Commands;

use App\Services\Media\MediaLabService;
use Illuminate\Console\Command;

/**
 * Hourly: fail AI Lab results stuck in `running` for over an hour (ledger untouched).
 */
class SweepMediaLabCommand extends Command
{
    protected $signature = 'media:sweep-lab {--minutes=60 : Age after which a running result is failed}';

    protected $description = 'Fail AI Lab results that have been running for too long';

    public function handle(MediaLabService $lab): int
    {
        $failed = $lab->sweepStuck(max(1, (int) $this->option('minutes')));

        $this->info("Marked {$failed} stuck lab result(s) as timed out.");

        return self::SUCCESS;
    }
}
