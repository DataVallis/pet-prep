<?php

namespace App\Console\Commands;

use App\Models\SummaryLead;
use Illuminate\Console\Command;

/**
 * Daily cleanup (M6-S05): a parent's e-mail from the page »Koliko dela je
 * pes« is deleted 24 months after the last visit.
 */
class PruneSummaryLeadsCommand extends Command
{
    protected $signature = 'summary-leads:prune';

    protected $description = 'Delete summary leads not seen for 24 months';

    public function handle(): int
    {
        $deleted = SummaryLead::where('last_seen_at', '<', now()->subMonths(SummaryLead::RETENTION_MONTHS))->delete();

        $this->info("Pruned {$deleted} summary lead(s) not seen for ".SummaryLead::RETENTION_MONTHS.' months.');

        return self::SUCCESS;
    }
}
