<?php

namespace App\Console\Commands;

use App\Services\EscalationService;
use App\Services\PetDecayService;
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
    protected $description = 'Process minutely metric decay and escalation checks for all active pets';

    /**
     * Execute the console command.
     */
    public function handle(PetDecayService $decayService, EscalationService $escalationService): int
    {
        $this->info('Processing pet metric decay...');

        $decayResult = $decayService->processAllActivePets();
        $this->info("Decay: processed {$decayResult['processed']} pets, updated {$decayResult['updated']}.");

        $this->info('Processing escalation checks...');

        $escalationResult = $escalationService->processAllActivePets();
        $this->info("Escalation: processed {$escalationResult['processed']} pets, escalated {$escalationResult['escalated']}.");

        return self::SUCCESS;
    }
}
