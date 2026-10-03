<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * EscalationService — 3-Tier Escalation Matrix & Neglect Mechanics
 *
 * Phase 1 (Soft Warning at 30%): Standard push notification to child
 *   "Your pet is looking at its food bowl..."
 *
 * Phase 2 (Critical Alert at 10%): High-priority push with sound/vibration
 *   "Critical warning: Your pet is starving!..."
 *
 * Phase 3 (Parent Intervention at 0% for >1 hour): WebSocket alarm to parent
 *   "Your child has neglected their pet!"
 *
 * Severe Neglect:
 *   Illness State: hygiene or energy at 0% for >=6 hours counted outside quiet hours
 *     → Pet state = SICK, 12-hour action lockout
 *
 *   Game Over / Virtual Shelter Protocol: any metric at 0% for 24 continuous hours
 *     → Lock pet session, is_active = false, notify parent via WebSocket
 */
class EscalationService
{
    /**
     * Escalation level thresholds (percentage of metric).
     */
    public const SOFT_WARNING_THRESHOLD = 30;

    public const CRITICAL_ALERT_THRESHOLD = 10;

    public const PARENT_INTERVENTION_HOURS = 1;

    /**
     * Neglect thresholds (hours at 0%).
     */
    public const ILLNESS_HOURS = 6;

    public const GAME_OVER_HOURS = 24;

    /**
     * Illness lockout duration in hours.
     */
    public const ILLNESS_LOCKOUT_HOURS = 12;

    /**
     * Process escalation checks for all active pets.
     * Called every minute by the scheduler after decay processing.
     *
     * @return array{processed: int, escalated: int}
     */
    public function processAllActivePets(): array
    {
        $pets = Pet::where('is_active', true)
            ->where('is_game_over', false)
            ->get();

        $processed = 0;
        $escalated = 0;

        foreach ($pets as $pet) {
            $wasEscalated = $this->processPetEscalation($pet);
            $processed++;
            if ($wasEscalated) {
                $escalated++;
            }
        }

        return ['processed' => $processed, 'escalated' => $escalated];
    }

    /**
     * Process escalation checks for a single pet.
     *
     * @return bool True if an escalation action was taken.
     */
    public function processPetEscalation(Pet $pet): bool
    {
        if (! $pet->is_active || $pet->is_game_over) {
            return false;
        }

        // M1-02: hard stop and illness freeze the neglect clocks — no new
        // escalation, illness or game over while frozen. (Illness still ends
        // by itself when illness_until passes.)
        if ($pet->isFrozen()) {
            return false;
        }

        // A freeze that ended without a model event (illness expiry) shifts
        // *_zero_since forward by the frozen duration before we evaluate.
        $pet->thawIfDue();

        // Check for game over first (highest priority)
        if ($this->checkGameOver($pet)) {
            return true;
        }

        // Check for illness state
        if ($this->checkIllness($pet)) {
            return true;
        }

        // Check 3-tier escalation matrix
        return $this->checkEscalationMatrix($pet);
    }

    // ──────────────────────────────────────────────────────────────
    //  3-Tier Escalation Matrix
    // ──────────────────────────────────────────────────────────────

    /**
     * Check and trigger the appropriate escalation tier based on metric levels.
     *
     * @return bool True if an escalation was triggered or changed.
     */
    private function checkEscalationMatrix(Pet $pet): bool
    {
        // Thresholds compare the value the child sees (rounded half up,
        // Pet::displayMetric): 30.4 shows 30 % → phase 1 (decision 2026-10-03).
        $lowestMetric = $this->lowestDisplayedMetric($pet);
        $currentLevel = $pet->escalation_level;

        // Phase 3: 0% for >1 hour — Parent WebSocket alarm
        if ($this->hasMetricAtZeroForHours($pet, self::PARENT_INTERVENTION_HOURS)) {
            if ($currentLevel < 3) {
                $this->triggerPhase3ParentAlarm($pet);

                return true;
            }

            return false;
        }

        // Phase 2: Critical Alert at 10%
        if ($lowestMetric <= self::CRITICAL_ALERT_THRESHOLD) {
            if ($currentLevel < 2) {
                $this->triggerPhase2CriticalAlert($pet);

                return true;
            }

            return false;
        }

        // Phase 1: Soft Warning at 30%
        if ($lowestMetric <= self::SOFT_WARNING_THRESHOLD) {
            if ($currentLevel < 1) {
                $this->triggerPhase1SoftWarning($pet);

                return true;
            }

            return false;
        }

        // No escalation needed — reset level if metrics recovered
        if ($currentLevel > 0) {
            $pet->update(['escalation_level' => 0]);
            Log::info('EscalationService: Escalation level reset — metrics recovered', [
                'pet_id' => $pet->id,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Trigger Phase 1: Soft Warning push notification to child.
     */
    private function triggerPhase1SoftWarning(Pet $pet): void
    {
        $pet->update(['escalation_level' => 1]);

        // Log the warning event
        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => 30,
        ]);

        // Dispatch push notification via queue (implementation for Phase 2+ push service)
        // SendSoftWarningNotification::dispatch($pet);

        Log::info('EscalationService: Phase 1 soft warning triggered', [
            'pet_id' => $pet->id,
            'lowest_metric' => $this->lowestDisplayedMetric($pet),
        ]);
    }

    /**
     * Trigger Phase 2: Critical Alert push notification with sound/vibration.
     */
    private function triggerPhase2CriticalAlert(Pet $pet): void
    {
        $pet->update(['escalation_level' => 2]);

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => 10,
        ]);

        // Dispatch critical push notification via queue
        // SendCriticalAlertNotification::dispatch($pet);

        Log::info('EscalationService: Phase 2 critical alert triggered', [
            'pet_id' => $pet->id,
        ]);
    }

    /**
     * Trigger Phase 3: Parent WebSocket alarm via Reverb.
     */
    private function triggerPhase3ParentAlarm(Pet $pet): void
    {
        $pet->update(['escalation_level' => 3]);

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => 0,
        ]);

        // Broadcast parent alarm via Reverb
        broadcast(new PetUpdated($pet, 'parent_intervention_alarm'));

        Log::warning('EscalationService: Phase 3 parent intervention alarm triggered', [
            'pet_id' => $pet->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Severe Neglect: Illness State
    // ──────────────────────────────────────────────────────────────

    /**
     * Check if the pet should enter illness state.
     * Condition: hygiene or energy at 0% for >6 hours outside quiet hours.
     *
     * @return bool True if illness state was triggered.
     */
    private function checkIllness(Pet $pet): bool
    {
        // Already ill — no re-trigger
        if ($pet->isIll()) {
            return false;
        }

        $quietHours = $pet->quietHours();
        $isQuiet = $quietHours?->isQuietNow() ?? false;

        // Only count neglect outside quiet hours
        if ($isQuiet) {
            return false;
        }

        $illnessTriggered = false;

        // Hygiene or energy at 0 % for ≥ 6 h counted outside quiet hours
        // only — the illness clock pauses during school / bedtime (PRODUCT_SPEC
        // §7). Example: energy back to 0 at local midnight, quiet 22–06 and
        // 8–13 → the 6 h are 06–08 + 13–17, ill at 17:00 without steps.
        foreach ([$pet->hygiene_zero_since, $pet->energy_zero_since] as $zeroSince) {
            if ($zeroSince && $this->neglectSecondsOutsideQuietHours($quietHours, $zeroSince) >= self::ILLNESS_HOURS * 3600) {
                $illnessTriggered = true;
            }
        }

        if ($illnessTriggered) {
            $this->triggerIllnessState($pet);

            return true;
        }

        return false;
    }

    /**
     * Put the pet into illness/vet state with a 12-hour action lockout.
     */
    private function triggerIllnessState(Pet $pet): void
    {
        $illnessUntil = now()->addHours(self::ILLNESS_LOCKOUT_HOURS);

        $pet->update([
            'illness_until' => $illnessUntil,
            'pet_state' => 'sick',
        ]);

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => -1, // Special value indicating illness trigger
        ]);

        // Broadcast illness state to parent and child
        broadcast(new PetUpdated($pet->fresh(), 'illness_triggered'));

        Log::warning('EscalationService: Pet entered illness state', [
            'pet_id' => $pet->id,
            'illness_until' => $illnessUntil->toIso8601String(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Severe Neglect: Game Over / Virtual Shelter Protocol
    // ──────────────────────────────────────────────────────────────

    /**
     * Check if the pet should enter game over state.
     * Condition: any metric at 0% for 24 continuous hours.
     *
     * @return bool True if game over was triggered.
     */
    private function checkGameOver(Pet $pet): bool
    {
        if ($pet->is_game_over) {
            return false;
        }

        $gameOverTriggered = false;

        // Check each metric for 24 continuous hours at 0%
        $zeroMetrics = [
            $pet->hunger_zero_since,
            $pet->thirst_zero_since,
            $pet->energy_zero_since,
            $pet->hygiene_zero_since,
        ];

        foreach ($zeroMetrics as $zeroSince) {
            if ($zeroSince && $zeroSince->diffInHours(now()) >= self::GAME_OVER_HOURS) {
                $gameOverTriggered = true;
                break;
            }
        }

        if ($gameOverTriggered) {
            $this->triggerGameOver($pet);

            return true;
        }

        return false;
    }

    /**
     * Trigger the Virtual Shelter Protocol — lock the pet session.
     */
    private function triggerGameOver(Pet $pet): void
    {
        $pet->update([
            'is_game_over' => true,
            'is_active' => false,
            'pet_state' => 'sick',
            'escalation_level' => 3,
        ]);

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => -2, // Special value indicating game over
        ]);

        // Broadcast game over to parent and child via Reverb
        broadcast(new PetUpdated($pet->fresh(), 'game_over_virtual_shelter'));

        Log::critical('EscalationService: VIRTUAL SHELTER PROTOCOL triggered — Game Over', [
            'pet_id' => $pet->id,
            'user_id' => $pet->user_id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Seconds since $zeroSince that fall outside quiet hours (family-local
     * clock). Frozen time is already excluded: thawing shifts *_zero_since
     * forward by the frozen duration (Pet::applyThaw).
     */
    private function neglectSecondsOutsideQuietHours(?QuietHours $quietHours, CarbonInterface $zeroSince): float
    {
        return QuietHours::splitSecondsBetween($quietHours, $zeroSince, now())['normal'];
    }

    /**
     * Lowest metric as displayed to the child (integer 0–100).
     */
    private function lowestDisplayedMetric(Pet $pet): int
    {
        return min($pet->displayMetrics());
    }

    /**
     * Check if any metric has been at 0% for at least the given number of hours.
     * *_zero_since is stamped when a metric first *shows* 0 % (PetDecayService).
     */
    private function hasMetricAtZeroForHours(Pet $pet, float $hours): bool
    {
        $zeroMetrics = [
            $pet->hunger_zero_since,
            $pet->thirst_zero_since,
            $pet->energy_zero_since,
            $pet->hygiene_zero_since,
        ];

        foreach ($zeroMetrics as $zeroSince) {
            if ($zeroSince && $zeroSince->diffInHours(now()) >= $hours) {
                return true;
            }
        }

        return false;
    }
}
