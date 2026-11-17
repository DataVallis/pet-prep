<?php

namespace App\Console\Commands;

use App\Services\Mail\OutboundEmailSender;
use Illuminate\Console\Command;

/** M6-S09: send the due e-mails of the `outbound_emails` queue (every minute). */
class SendQueuedEmails extends Command
{
    protected $signature = 'emails:send {--limit= : At most this many e-mails in this run}';

    protected $description = 'Send due e-mails from the outbound_emails queue (retries with back-off, alert after the last failure)';

    public function handle(OutboundEmailSender $sender): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $counts = $sender->run($limit);
        if (array_sum($counts) > 0) {
            $this->info(sprintf('sent %d, retry %d, failed %d', $counts['sent'], $counts['retry'], $counts['failed']));
        }

        return self::SUCCESS;
    }
}
