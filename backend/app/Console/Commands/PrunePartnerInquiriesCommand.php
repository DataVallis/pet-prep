<?php

namespace App\Console\Commands;

use App\Models\PartnerInquiry;
use Illuminate\Console\Command;

/**
 * Daily cleanup (M6-S03, WEBSITE_SALES_SPEC §5.2): partner inquiries hold a
 * business contact's name and e-mail, so they are deleted 12 months after
 * they were sent.
 */
class PrunePartnerInquiriesCommand extends Command
{
    protected $signature = 'partner-inquiries:prune';

    protected $description = 'Delete partner inquiries older than 12 months';

    public function handle(): int
    {
        $deleted = PartnerInquiry::where('created_at', '<', now()->subMonths(PartnerInquiry::RETENTION_MONTHS))->delete();

        $this->info("Pruned {$deleted} partner inquiry(ies) older than ".PartnerInquiry::RETENTION_MONTHS.' months.');

        return self::SUCCESS;
    }
}
