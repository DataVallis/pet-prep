<?php

namespace App\Console\Commands;

use App\Services\ChallengeCertificateService;
use App\Services\ChallengeService;
use App\Services\EscalationService;
use App\Services\PetDecayService;
use App\Services\RoutineLedgerService;
use Illuminate\Console\Command;

class ProcessPetDecayCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pets:process-decay';

    /**
     * The console command description.
     */
    protected $description = 'Process minutely metric decay, escalation checks, the routine ledger and challenge ends for all pets';

    /**
     * Execute the console command.
     */
    public function handle(PetDecayService $decayService, EscalationService $escalationService, RoutineLedgerService $ledger, ChallengeService $challenges, ChallengeCertificateService $certificates): int
    {
        // M3-11 / M3-13: payment locks first, so a pet that has to wait for a
        // purchase is frozen before this tick's decay / escalation.
        $trials = $challenges->processTrials();
        $this->info("Payment locks: {$trials['locked']}.");

        $this->info('Processing pet metric decay...');

        $decayResult = $decayService->processAllActivePets();
        $this->info("Decay: processed {$decayResult['processed']} pets, updated {$decayResult['updated']}.");

        $this->info('Processing escalation checks...');

        $escalationResult = $escalationService->processAllActivePets();
        $this->info("Escalation: processed {$escalationResult['processed']} pets, escalated {$escalationResult['escalated']}.");

        // Routine ledger (M2-06): materialise finished family-local days.
        // After decay + escalation, so the night's walk row exists.
        $ledgerResult = $ledger->closeDueDays();
        $this->info("Routines: checked {$ledgerResult['pets']} pets, closed {$ledgerResult['days']} days.");

        // M2-01d: children whose 12-week challenge just ended (84 program days, or
        // game over before that) get one certificate / report row + one parent push.
        // Last, so this tick's game over and closed days are already in.
        $certificateResult = $certificates->processCompletions();
        $this->info("Certificates: checked {$certificateResult['pets']} pets, recorded {$certificateResult['created']} challenge ends.");

        return self::SUCCESS;
    }
}
