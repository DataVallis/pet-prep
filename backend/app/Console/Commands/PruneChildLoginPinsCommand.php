<?php

namespace App\Console\Commands;

use App\Models\ChildLoginPin;
use Illuminate\Console\Command;

/**
 * Daily cleanup of child login PINs (M2-02). Every PIN expires 15 minutes
 * after it is issued, so a row whose expiry is older than the retention
 * window is closed for good (used, revoked or simply expired) and only kept
 * this long for support questions ("my code didn't work yesterday").
 * Only HMACs are stored, but there is no reason to keep them forever.
 */
class PruneChildLoginPinsCommand extends Command
{
    public const RETENTION_DAYS = 7;

    protected $signature = 'pins:prune {--days= : Keep rows whose PIN expired within this many days (default 7)}';

    protected $description = 'Delete child login PINs that expired more than N days ago (used, revoked or unused)';

    public function handle(): int
    {
        $days = $this->option('days');
        $days = $days === null ? self::RETENTION_DAYS : (int) $days;

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::INVALID;
        }

        $deleted = ChildLoginPin::where('expires_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} child login PIN(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
