<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Events\PetUpdated;
use App\Models\ActivityLog;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 *   Illness State: hygiene at 0% for >=6 hours counted outside quiet hours,
 *     or no walk at all yesterday (daily walk rule: DailyWalkService plans
 *     the start at the end of the night's quiet hours)
 *     → Pet state = SICK, 12-hour action lockout; afterwards a fresh start
 *       (Pet::recoverFromIllnessIfDue: hygiene 100 %, clocks restart)
 *
 *   Game Over / Virtual Shelter Protocol: hunger, thirst or hygiene at 0% for
 *     24 continuous hours → Lock pet session, is_active = false, notify parent
 *
 * Energy (daily walk, David 2026-10-03) has no hourly neglect clock: no
 * phase 3, no 6 h illness and no game over from energy. Low energy gives
 * phase 1 / 2 only outside quiet hours.
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
        // IDs only: every pet is re-read under its row lock when its turn
        // comes, so a child action or hard stop made in the meantime is seen.
        // Unborn pets (contract not signed, M1-07b) have nothing to escalate.
        $petIds = Pet::born()
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->orderBy('id')
            ->pluck('id');

        $processed = 0;
        $escalated = 0;

        foreach ($petIds as $petId) {
            $wasEscalated = $this->processPetEscalationById($petId);
            if ($wasEscalated === null) {
                continue; // deleted in the meantime
            }

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
     * Same row-lock rule as the decay tick (backend/CLAUDE.md): the given
     * model is only used for its ID — the row is re-read with
     * `SELECT … FOR UPDATE` in a per-pet transaction and every decision is
     * made from that fresh row, so a concurrent clean() / feed / hard stop is
     * never overwritten or ignored. The result is copied back into $pet.
     * Each escalation step taken emits exactly one PetUpdated after commit
     * (M1-08); a tick takes at most one step per pet.
     *
     * @return bool True if an escalation action was taken.
     */
    public function processPetEscalation(Pet $pet): bool
    {
        return $this->processPetEscalationById($pet->id, $pet) ?? false;
    }

    /**
     * @return bool|null Null if the pet no longer exists.
     */
    private function processPetEscalationById(int $petId, ?Pet $target = null): ?bool
    {
        $work = function () use ($petId): array {
            $locked = Pet::whereKey($petId)->lockForUpdate()->first();
            if (! $locked) {
                return [null, false];
            }

            return [$locked, $this->escalateLockedPet($locked)];
        };

        // Own transaction from the scheduler; inside a caller's transaction
        // the lock lives until that one commits (see PetDecayService).
        [$locked, $escalated] = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);

        if (! $locked) {
            return null;
        }

        if ($target) {
            $target->setRawAttributes($locked->getAttributes(), true);
        }

        return $escalated;
    }

    /**
     * Escalation for a pet whose row the caller holds locked.
     */
    private function escalateLockedPet(Pet $pet): bool
    {
        if (! $pet->is_active || $pet->is_game_over || $pet->isUnborn()) {
            return false;
        }

        // M1-02: hard stop and illness freeze the neglect clocks — no new
        // escalation, illness or game over while frozen. (Illness still ends
        // by itself when illness_until passes.)
        if ($pet->isFrozen()) {
            return false;
        }

        // An illness that ended without a model event: fresh start
        // (recovery); a stale frozen_at: shift *_zero_since.
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
        $isQuiet = $pet->quietHours()?->isQuietNow() ?? false;

        return $this->checkEscalationMatrix($pet, $isQuiet);
    }

    // ──────────────────────────────────────────────────────────────
    //  3-Tier Escalation Matrix
    // ──────────────────────────────────────────────────────────────

    /**
     * Check and trigger the appropriate escalation tier based on metric levels.
     *
     * @return bool True if an escalation was triggered or changed.
     */
    private function checkEscalationMatrix(Pet $pet, bool $isQuiet): bool
    {
        // Thresholds compare the value the child sees (rounded half up,
        // Pet::displayMetric): 30.4 shows 30 % → phase 1 (decision 2026-10-03).
        // Energy counts only outside quiet hours (daily walk rule).
        $lowestMetric = $this->lowestDisplayedMetric($pet, includeEnergy: ! $isQuiet);
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
            PetUpdated::afterCommit($pet, 'escalation_reset');
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

        PetUpdated::afterCommit($pet, 'soft_warning');

        // Push to every caretaker child of the pet once push exists (M3);
        // recipients: FamilyService::caretakerRecipients (M2-01).
        // SendSoftWarningNotification::dispatch($pet);

        Log::info('EscalationService: Phase 1 soft warning triggered', [
            'pet_id' => $pet->id,
            'child_recipient_ids' => $this->families()->caretakerRecipients($pet)->pluck('id')->all(),
            'lowest_metric' => $this->lowestDisplayedMetric($pet, includeEnergy: true),
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

        PetUpdated::afterCommit($pet, 'critical_alert');

        // Critical push to every caretaker child (M3), see Phase 1.
        // SendCriticalAlertNotification::dispatch($pet);

        Log::info('EscalationService: Phase 2 critical alert triggered', [
            'pet_id' => $pet->id,
            'child_recipient_ids' => $this->families()->caretakerRecipients($pet)->pluck('id')->all(),
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
        PetUpdated::afterCommit($pet, 'parent_intervention_alarm');

        // Every parent of the family is alarmed (M2-01): the realtime channel
        // already reaches them; push / e-mail (M3) use the same recipients.
        Log::warning('EscalationService: Phase 3 parent intervention alarm triggered', [
            'pet_id' => $pet->id,
            'parent_recipient_ids' => $this->families()->parentRecipients($pet)->pluck('id')->all(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Severe Neglect: Illness State
    // ──────────────────────────────────────────────────────────────

    /**
     * Check if the pet should enter illness state.
     * Conditions: a walk illness that came due (no walk at all yesterday,
     * planned by DailyWalkService), or hygiene at 0 % for ≥ 6 hours counted
     * outside quiet hours.
     *
     * @return bool True if illness state was triggered.
     */
    private function checkIllness(Pet $pet): bool
    {
        // Already ill — no re-trigger
        if ($pet->isIll()) {
            return false;
        }

        // Daily walk rule: the illness starts at the planned instant (end of
        // the night's quiet hours), also when this tick runs a bit late.
        $walkIllnessAt = $pet->walk_illness_due_at;
        if ($walkIllnessAt !== null && $walkIllnessAt->lessThanOrEqualTo(now())) {
            // So late that the whole 12 h would already be over (scheduler
            // down): skip it instead of an illness that ends instantly and
            // would trigger the recovery side effects.
            if ($walkIllnessAt->copy()->addHours(self::ILLNESS_LOCKOUT_HOURS)->lessThanOrEqualTo(now())) {
                $pet->forceFill(['walk_illness_due_at' => null])->saveQuietly();

                PetDailyWalk::where('pet_id', $pet->id)
                    ->where('illness_due_at', $walkIllnessAt)
                    ->update(['illness_skipped_at' => now()]);

                Log::warning('EscalationService: walk illness skipped — evaluated after its 12 h had passed', [
                    'pet_id' => $pet->id,
                    'illness_due_at' => $walkIllnessAt->toIso8601String(),
                ]);
            } else {
                $this->triggerIllnessState($pet, $walkIllnessAt, 'missed_walk');

                PetDailyWalk::where('pet_id', $pet->id)
                    ->where('illness_due_at', $walkIllnessAt)
                    ->update(['illness_started_at' => $walkIllnessAt]);

                return true;
            }
        }

        $quietHours = $pet->quietHours();
        $isQuiet = $quietHours?->isQuietNow() ?? false;

        // Only count neglect outside quiet hours
        if ($isQuiet) {
            return false;
        }

        $illnessTriggered = false;

        // Hygiene at 0 % for ≥ 6 h counted outside quiet hours only — the
        // illness clock pauses during school / bedtime (PRODUCT_SPEC §7).
        // Example: mess at 07:30, quiet 22–06 and 8–13 → 07:30–08 + 13–18:30.
        $zeroSince = $pet->hygiene_zero_since;
        if ($zeroSince && $this->neglectSecondsOutsideQuietHours($quietHours, $zeroSince) >= self::ILLNESS_HOURS * 3600) {
            $illnessTriggered = true;
        }

        if ($illnessTriggered) {
            $this->triggerIllnessState($pet, now(), 'hygiene');

            return true;
        }

        return false;
    }

    /**
     * Put the pet into illness/vet state with a 12-hour action lockout
     * starting at $start (now, or the planned start of a walk illness).
     */
    private function triggerIllnessState(Pet $pet, CarbonInterface $start, string $reason): void
    {
        $start = $start->copy()->startOfSecond();
        $illnessUntil = $start->copy()->addHours(self::ILLNESS_LOCKOUT_HOURS);

        $pet->forceFill([
            'illness_until' => $illnessUntil,
            'walk_illness_due_at' => null,
            'pet_state' => 'sick',
            'frozen_at' => $start,
        ])->save();

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => -1, // Special value indicating illness trigger
        ]);

        // Broadcast illness state to parent and child
        PetUpdated::afterCommit($pet, 'illness_triggered');

        Log::warning('EscalationService: Pet entered illness state', [
            'pet_id' => $pet->id,
            'parent_recipient_ids' => $this->families()->parentRecipients($pet)->pluck('id')->all(),
            'illness_until' => $illnessUntil->toIso8601String(),
            'reason' => $reason,
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

        // Hunger, thirst or hygiene 24 continuous hours at 0 % (not energy)
        foreach ($this->neglectClocks($pet) as $zeroSince) {
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
            'walk_illness_due_at' => null,
        ]);

        ActivityLog::create([
            'pet_id' => $pet->id,
            'activity_type' => ActivityType::IgnoredWarning->value,
            'value' => -2, // Special value indicating game over
        ]);

        // Broadcast game over to parent and child via Reverb
        PetUpdated::afterCommit($pet, 'game_over_virtual_shelter');

        Log::critical('EscalationService: VIRTUAL SHELTER PROTOCOL triggered — Game Over', [
            'pet_id' => $pet->id,
            'parent_recipient_ids' => $this->families()->parentRecipients($pet)->pluck('id')->all(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Notification recipients (all parents / all caretakers) are resolved
     * in one place: FamilyService (M2-01).
     */
    private function families(): FamilyService
    {
        return app(FamilyService::class);
    }

    /**
     * Seconds since $zeroSince that fall outside quiet hours (family-local
     * clock). Frozen time is already excluded: thawing shifts *_zero_since
     * forward by the frozen duration (Pet::applyThaw); illness recovery restarts them.
     */
    private function neglectSecondsOutsideQuietHours(?QuietHours $quietHours, CarbonInterface $zeroSince): float
    {
        return QuietHours::splitSecondsBetween($quietHours, $zeroSince, now())['normal'];
    }

    /**
     * Lowest metric as displayed to the child (integer 0–100). Energy only
     * outside quiet hours (daily walk rule).
     */
    private function lowestDisplayedMetric(Pet $pet, bool $includeEnergy): int
    {
        $metrics = $pet->displayMetrics();
        if (! $includeEnergy) {
            unset($metrics['energy_level']);
        }

        return min($metrics);
    }

    /**
     * The neglect clocks that drive phase 3, illness and game over. Energy is
     * not one of them (daily walk rule, David 2026-10-03).
     *
     * @return list<CarbonInterface|null>
     */
    private function neglectClocks(Pet $pet): array
    {
        return [
            $pet->hunger_zero_since,
            $pet->thirst_zero_since,
            $pet->hygiene_zero_since,
        ];
    }

    /**
     * Check if any metric has been at 0% for at least the given number of hours.
     * *_zero_since is stamped when a metric first *shows* 0 % (PetDecayService).
     */
    private function hasMetricAtZeroForHours(Pet $pet, float $hours): bool
    {
        foreach ($this->neglectClocks($pet) as $zeroSince) {
            if ($zeroSince && $zeroSince->diffInHours(now()) >= $hours) {
                return true;
            }
        }

        return false;
    }
}
