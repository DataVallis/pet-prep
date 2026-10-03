<?php

namespace App\Services;

use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * PetDecayService — Core Game Loop Engine
 *
 * Applies metric decay to active pets based on their breed configuration's
 * decay rates. Respects parent-defined Quiet Hours (decay reduced by 90%).
 *
 * Decay is a pure function of (stored precise metrics, time elapsed since
 * `pets.last_decay_at`, breed config, quiet-hours schedule). Metrics are
 * stored as floats so fractional per-minute decay is never lost to rounding;
 * API and broadcast payloads round for display (Pet::displayMetric()).
 *
 * Decay Rates (per hour, non-quiet):
 *   Mutt:          Hunger -8%/hr,  Thirst -10%/hr
 *   Border Collie: Hunger -12%/hr, Thirst -15%/hr
 *   Hygiene:       -1.5%/hr gradual (interim; random drops are M1-05)
 *   Energy:        not decayed here (steps-based, M1-04)
 *
 * Frozen (M1-02): while hard-stopped, ill, inactive or game over, metrics
 * don't change and the decay clock is advanced so nothing is caught up later.
 *
 * Time Asymmetry: 1 real week = 1 virtual month.
 *   Total MVP: 12 real weeks = 12 virtual months (1 virtual year).
 *   At 12 weeks with satisfactory performance → Responsibility Certificate.
 */
class PetDecayService
{
    /**
     * The decay reduction factor during Quiet Hours (90% reduction).
     */
    public const QUIET_HOURS_DECAY_MULTIPLIER = 0.10;

    /**
     * Gradual hygiene decay per hour, outside quiet hours only.
     * Interim rule until random hygiene events land (M1-05).
     */
    public const HYGIENE_DECAY_PER_HOUR = 1.5;

    /**
     * Process metric decay for all pets the scheduler should tick.
     * Called every minute by the scheduler.
     *
     * Inactive / game-over pets are not loaded; Pet's `updating` hook restarts
     * their decay clock if they are ever re-activated.
     *
     * @return array{processed: int, updated: int}
     */
    public function processAllActivePets(): array
    {
        $pets = Pet::where('is_active', true)
            ->where('is_game_over', false)
            ->get();

        $processed = 0;
        $updated = 0;

        foreach ($pets as $pet) {
            $wasUpdated = $this->processPetDecay($pet);
            $processed++;
            if ($wasUpdated) {
                $updated++;
            }
        }

        return ['processed' => $processed, 'updated' => $updated];
    }

    /**
     * Process metric decay for a single pet (one scheduler tick).
     *
     * Elapsed time is measured only from `last_decay_at` — never `updated_at`,
     * so unrelated writes (child actions, webhooks, escalation) don't eat
     * decay. Missed ticks are caught up in full.
     *
     * A PetUpdated broadcast (via PetObserver) happens only when a displayed
     * (rounded) metric, the pet state or certificate eligibility changes.
     * All other writes are quiet.
     *
     * @return bool True if a displayed value changed (and was broadcast).
     */
    public function processPetDecay(Pet $pet): bool
    {
        // Whole seconds: the timestamp column stores no fractions, so the
        // value we compute elapsed time to must equal the value we persist.
        $now = now()->startOfSecond();

        // First tick for a pet without a decay clock: just start the clock.
        if ($pet->last_decay_at === null) {
            $this->advanceClock($pet, $now);

            return false;
        }

        // Frozen states (M1-02): no decay, but keep the clock current so
        // there is no catch-up burst after unfreezing.
        if ($this->isFrozen($pet)) {
            $this->advanceClock($pet, $now);

            return false;
        }

        $from = $pet->last_decay_at;
        // An illness lockout that ended between ticks: decay only from its end.
        if ($pet->illness_until !== null && $pet->illness_until->greaterThan($from)) {
            $from = $pet->illness_until;
        }
        if ($from->greaterThanOrEqualTo($now)) {
            return false;
        }

        $breedConfig = $pet->breedConfig();
        if (! $breedConfig) {
            Log::warning('PetDecayService: No breed config found for pet', [
                'pet_id' => $pet->id,
                'breed_type' => $pet->breed_type->value,
            ]);

            return false;
        }

        $quietHours = $pet->quietHours();
        $isQuiet = $quietHours?->isQuietNow($now) ?? false;

        // Split the elapsed interval into normal and quiet hours.
        ['normal' => $normalHours, 'quiet' => $quietHoursElapsed] = $this->splitElapsedHours($quietHours, $from, $now);

        // Hunger / thirst: full rate outside quiet hours, 10 % inside.
        $weightedHours = $normalHours + $quietHoursElapsed * self::QUIET_HOURS_DECAY_MULTIPLIER;

        $hungerDecayPerHour = (float) $breedConfig->hunger_decay_rate;
        $thirstDecayPerHour = $this->getThirstDecayRate($pet->breed_type->value);

        $newHunger = $this->decayMetric((float) $pet->hunger_level, $hungerDecayPerHour * $weightedHours);
        $newThirst = $this->decayMetric((float) $pet->thirst_level, $thirstDecayPerHour * $weightedHours);
        $newEnergy = (float) $pet->energy_level; // Energy comes from steps (M1-04), not decayed here
        // Hygiene: gradual decay outside quiet hours only (random drops are M1-05).
        $newHygiene = $this->decayMetric((float) $pet->hygiene_level, self::HYGIENE_DECAY_PER_HOUR * $normalHours);

        // Reset step counts at midnight
        $this->resetStepCountIfMidnight($pet);

        // Check for virtual age / certificate eligibility
        $certificateEligible = $this->checkCertificateEligibility($pet);

        // Track when metrics first hit 0% (precise value)
        $zeroUpdates = $this->trackZeroMetrics($pet, $newHunger, $newThirst, $newEnergy, $newHygiene);

        // Determine the appropriate pet state based on precise metrics
        $newPetState = $this->determinePetState($newHunger, $newThirst, $newEnergy, $newHygiene, $isQuiet);

        $newMetrics = [
            'hunger_level' => $newHunger,
            'thirst_level' => $newThirst,
            'energy_level' => $newEnergy,
            'hygiene_level' => $newHygiene,
        ];

        $displayChanged = $certificateEligible !== $pet->certificate_eligible
            || $newPetState !== $pet->pet_state;
        foreach ($newMetrics as $metric => $value) {
            if (Pet::displayValue($value) !== $pet->displayMetric($metric)) {
                $displayChanged = true;
            }
        }

        $updates = array_merge($zeroUpdates, $newMetrics, [
            'pet_state' => $newPetState->value,
            'last_decay_at' => $now,
        ]);

        if ($certificateEligible !== $pet->certificate_eligible) {
            $updates['certificate_eligible'] = $certificateEligible;
        }

        if ($displayChanged) {
            // PetObserver broadcasts exactly one PetUpdated for this write.
            $pet->update($updates);
        } else {
            $pet->updateQuietly($updates);
        }

        return $displayChanged;
    }

    /**
     * Metrics are frozen while hard-stopped, ill, inactive or game over.
     */
    private function isFrozen(Pet $pet): bool
    {
        return ! $pet->is_active
            || $pet->is_game_over
            || $pet->is_hard_stopped
            || $pet->isIll();
    }

    /**
     * Move the decay clock to $now without touching metrics or broadcasting.
     */
    private function advanceClock(Pet $pet, CarbonInterface $now): void
    {
        $pet->forceFill(['last_decay_at' => $now])->saveQuietly();
    }

    /**
     * Subtract decay and clamp to 0–100 (precise, no rounding).
     */
    private function decayMetric(float $current, float $decay): float
    {
        return max(0.0, min(100.0, $current - $decay));
    }

    /**
     * Split [from, to) into hours outside and inside quiet hours.
     *
     * Quiet hours have minute granularity (H:i), so the interval is walked in
     * segments aligned to minute boundaries. This keeps catch-up after missed
     * scheduler ticks exact even when the gap crosses a quiet-hours boundary.
     *
     * @return array{normal: float, quiet: float}
     */
    private function splitElapsedHours(?QuietHours $quietHours, CarbonInterface $from, CarbonInterface $to): array
    {
        $totalSeconds = max(0.0, (float) $from->diffInSeconds($to, false));

        if (! $quietHours || ! $quietHours->is_active) {
            return ['normal' => $totalSeconds / 3600, 'quiet' => 0.0];
        }

        $normalSeconds = 0.0;
        $quietSeconds = 0.0;
        $cursor = Carbon::instance($from);
        $end = Carbon::instance($to);

        while ($cursor->lessThan($end)) {
            $next = $cursor->copy()->startOfMinute()->addMinute();
            if ($next->greaterThan($end)) {
                $next = $end->copy();
            }

            $seconds = (float) $cursor->diffInSeconds($next, false);
            if ($quietHours->isQuietNow($cursor)) {
                $quietSeconds += $seconds;
            } else {
                $normalSeconds += $seconds;
            }

            $cursor = $next;
        }

        return ['normal' => $normalSeconds / 3600, 'quiet' => $quietSeconds / 3600];
    }

    /**
     * Get the thirst decay rate per hour for a breed.
     * Mutt: -10%/hr, Border Collie: -15%/hr
     * (Moves to breed_configs in M1-06.)
     */
    private function getThirstDecayRate(string $breedType): float
    {
        return match ($breedType) {
            'mutt' => 10.0,
            'border_collie' => 15.0,
            default => 10.0,
        };
    }

    /**
     * Reset the daily step count if it's past midnight (a new day).
     */
    private function resetStepCountIfMidnight(Pet $pet): void
    {
        if (! $pet->last_step_reset_at || ! $pet->last_step_reset_at->isToday()) {
            Pet::where('id', $pet->id)->update([
                'daily_step_count' => 0,
                'last_step_reset_at' => now(),
            ]);
            $pet->daily_step_count = 0;
            $pet->last_step_reset_at = now();
            $pet->syncOriginalAttributes(['daily_step_count', 'last_step_reset_at']);
        }
    }

    /**
     * Check if the pet is eligible for a Responsibility Certificate.
     * Must have reached 12 virtual months (12 real weeks) with satisfactory performance.
     */
    private function checkCertificateEligibility(Pet $pet): bool
    {
        if ($pet->certificate_eligible) {
            return true; // Already eligible
        }

        // Must be 12 virtual months old
        if (! $pet->hasReachedSimulationEnd()) {
            return false;
        }

        // Satisfactory performance: no game over, not currently in escalation level 3
        if ($pet->is_game_over || $pet->escalation_level >= 3) {
            return false;
        }

        return true;
    }

    /**
     * Track when each metric first hits 0% — used by the EscalationService
     * for neglect calculations (illness >6hrs, game over >24hrs).
     * Uses the precise value: zero means <= 0.
     *
     * @return array<string, mixed> Updates to apply to the pet.
     */
    private function trackZeroMetrics(Pet $pet, float $hunger, float $thirst, float $energy, float $hygiene): array
    {
        $updates = [];

        $metrics = [
            'hunger_zero_since' => $hunger,
            'thirst_zero_since' => $thirst,
            'energy_zero_since' => $energy,
            'hygiene_zero_since' => $hygiene,
        ];

        foreach ($metrics as $column => $value) {
            if ($value <= 0 && $pet->{$column} === null) {
                $updates[$column] = now();
            } elseif ($value > 0 && $pet->{$column} !== null) {
                $updates[$column] = null;
            }
        }

        return $updates;
    }

    /**
     * Determine the appropriate PetStateEnum based on current (precise) metrics.
     */
    private function determinePetState(float $hunger, float $thirst, float $energy, float $hygiene, bool $isQuiet): PetStateEnum
    {
        // Sick takes priority
        if ($hygiene <= 0 || $energy <= 0) {
            return PetStateEnum::Sick;
        }

        // Sleeping during quiet hours
        if ($isQuiet) {
            return PetStateEnum::Sleeping;
        }

        // Hungry takes priority over low energy
        if ($hunger <= 30 || $thirst <= 30) {
            return PetStateEnum::Hungry;
        }

        // Low energy
        if ($energy <= 30) {
            return PetStateEnum::LowEnergy;
        }

        // High energy = playing
        if ($energy >= 80 && $hunger >= 60) {
            return PetStateEnum::Playing;
        }

        return PetStateEnum::Idle;
    }
}
