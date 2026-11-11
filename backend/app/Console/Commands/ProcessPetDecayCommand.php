<?php

namespace App\Console\Commands;

use App\Services\ChallengeCertificateService;
use App\Services\ChallengeService;
use App\Services\EscalationService;
use App\Services\GameLoop\PetLoopGuard;
use App\Services\PetBirthdayService;
use App\Services\UpgradeReminderService;
use App\Services\PetDecayService;
use App\Services\RoutineLedgerService;
use App\Services\SystemHealth\SystemHealthService;
use Illuminate\Console\Command;
use Throwable;

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
     *
     * Isolation (David 2026-11-04, after PR #737): every per-pet loop below catches a
     * failing pet and hands it to PetLoopGuard (its unit rolled back, the next pet runs),
     * and every stage runs even when an earlier one threw as a whole (e.g. its ID query),
     * so a decay failure never skips escalation, the day close or the heartbeat.
     */
    public function handle(PetDecayService $decayService, EscalationService $escalationService, RoutineLedgerService $ledger, ChallengeService $challenges, ChallengeCertificateService $certificates, PetBirthdayService $birthdays, UpgradeReminderService $upgradeReminders, SystemHealthService $health, PetLoopGuard $guard): int
    {
        $guard->begin();
        $ok = true;

        // M3-11 / M3-13: payment locks first, so a pet that has to wait for a
        // purchase is frozen before this tick's decay / escalation.
        $ok = $this->stage('payment locks', function () use ($challenges): void {
            $trials = $challenges->processTrials();
            $this->info("Payment locks: {$trials['locked']}.");
        }) && $ok;

        $ok = $this->stage('decay', function () use ($decayService): void {
            $this->info('Processing pet metric decay...');
            $r = $decayService->processAllActivePets();
            $this->info("Decay: processed {$r['processed']} pets, updated {$r['updated']}, failed {$r['failed']}.");
        }) && $ok;

        $ok = $this->stage('escalation', function () use ($escalationService): void {
            $this->info('Processing escalation checks...');
            $r = $escalationService->processAllActivePets();
            $this->info("Escalation: processed {$r['processed']} pets, escalated {$r['escalated']}, failed {$r['failed']}.");
        }) && $ok;

        // Routine ledger (M2-06): materialise finished family-local days.
        // After decay + escalation, so the night's walk row exists.
        $ok = $this->stage('routines', function () use ($ledger): void {
            $r = $ledger->closeDueDays();
            $this->info("Routines: checked {$r['pets']} pets, closed {$r['days']} days.");
        }) && $ok;

        // M2-01d: children whose 12-week challenge just ended (84 program days, or
        // game over before that) get one certificate / report row + one parent push.
        // Last, so this tick's game over and closed days are already in.
        $ok = $this->stage('certificates', function () use ($certificates): void {
            $r = $certificates->processCompletions();
            $this->info("Certificates: checked {$r['pets']} pets, recorded {$r['created']} challenge ends.");
        }) && $ok;

        // M5-T13: pets that turn 1 today (family-local) — celebrated once, push held over quiet hours.
        $ok = $this->stage('birthdays', function () use ($birthdays): void {
            $r = $birthdays->processDue();
            $this->info("Birthdays: checked {$r['pets']} pets, celebrated {$r['celebrated']}.");
        }) && $ok;

        // M6-U02: a free pet's day-7 parent reminder (5 of 7 active days) — once, push held over quiet hours.
        $ok = $this->stage('upgrade reminders', function () use ($upgradeReminders): void {
            $r = $upgradeReminders->processDue();
            $this->info("Upgrade reminders: checked {$r['pets']} pets, sent {$r['sent']}.");
        }) && $ok;

        // One System health log line + at most one Discord alert per failing pet per day.
        $failed = $guard->failureCount();
        if ($failed > 0) {
            $this->warn("Failed pet units this run: {$failed} (System health).");
        }
        $guard->finish();

        // System health (David 2026-10-19): the scheduler heartbeat shown on
        // /admin/system-health (red when older than 5 min).
        $health->beat();

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Run one stage; a stage that throws as a whole is reported and the next stage still runs.
     */
    private function stage(string $name, callable $run): bool
    {
        try {
            $run();

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->error("Stage {$name} failed: ".$e::class);

            return false;
        }
    }
}
