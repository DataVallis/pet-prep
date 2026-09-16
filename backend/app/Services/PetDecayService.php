<?php

namespace App\Services;

use App\Enums\PetStateEnum;
use App\Models\Pet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PetDecayService — Core Game Loop Engine
 *
 * Computes minutely metric decay for all active pets based on their
 * breed configuration's decay rates. Respects parent-defined Quiet Hours
 * (decay reduced by 90% during quiet periods).
 *
 * Decay Rates (per hour, non-quiet):
 *   Mutt:         Hunger -8%/hr,  Thirst -10%/hr,  Hygiene random daily drop to 0%
 *   Border Collie: Hunger -12%/hr, Thirst -15%/hr,  Hygiene random drop to 0% twice daily
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
     * Process metric decay for all active pets.
     * Called every minute by the scheduler.
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
     * Process metric decay for a single pet.
     *
     * Uses elapsed-time-based decay: calculates the time since the pet's
     * last update and applies the total accumulated decay at once.
     * This is resilient to missed cron ticks and avoids fractional
     * rounding errors from per-minute fixed decay.
     *
     * @return bool True if the pet's metrics were updated.
     */
    public function processPetDecay(Pet $pet): bool
    {
        // Skip inactive or game-over pets
        if (! $pet->is_active || $pet->is_game_over) {
            return false;
        }

        // Skip pets in illness lockout (metrics frozen during vet state)
        if ($pet->isIll()) {
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

        // Calculate minutes elapsed since last update
        $lastUpdate = $pet->updated_at ?? now();
        $minutesElapsed = max(1, (int) $lastUpdate->diffInMinutes(now()));

        $quietHours = $pet->quietHours();
        $isQuiet = $quietHours?->isQuietNow() ?? false;

        // Calculate per-hour decay rates
        $hungerDecayPerHour = $breedConfig->hunger_decay_rate;
        $thirstDecayPerHour = $this->getThirstDecayRate($pet->breed_type->value);

        // Apply quiet hours reduction (90% less decay)
        if ($isQuiet) {
            $hungerDecayPerHour *= self::QUIET_HOURS_DECAY_MULTIPLIER;
            $thirstDecayPerHour *= self::QUIET_HOURS_DECAY_MULTIPLIER;
        }

        // Calculate total decay over the elapsed period
        $hungerDecay = ($hungerDecayPerHour / 60) * $minutesElapsed;
        $thirstDecay = ($thirstDecayPerHour / 60) * $minutesElapsed;

        // Calculate new metric values (clamped to 0–100)
        $newHunger = max(0, (int) round($pet->hunger_level - $hungerDecay));
        $newThirst = max(0, (int) round($pet->thirst_level - $thirstDecay));
        $newEnergy = $pet->energy_level; // Energy is restored via walking, not decayed here
        $newHygiene = $pet->hygiene_level; // Hygiene drops gradually

        // Handle hygiene gradual decay (outside quiet hours only)
        $newHygiene = $this->processHygieneDecay($pet, $newHygiene, $minutesElapsed, $isQuiet);

        // Reset step counts at midnight
        $this->resetStepCountIfMidnight($pet);

        // Check for virtual age / certificate eligibility
        $certificateEligible = $this->checkCertificateEligibility($pet);

        // Track when metrics first hit 0%
        $updates = $this->trackZeroMetrics($pet, $newHunger, $newThirst, $newEnergy, $newHygiene);

        // Determine the appropriate pet state based on metrics
        $newPetState = $this->determinePetState($newHunger, $newThirst, $newEnergy, $newHygiene, $isQuiet);

        // Only update if something actually changed
        $changed = $newHunger !== $pet->hunger_level
            || $newThirst !== $pet->thirst_level
            || $newHygiene !== $pet->hygiene_level
            || $certificateEligible !== $pet->certificate_eligible
            || $newPetState->value !== $pet->pet_state->value;

        if (! $changed && empty($updates)) {
            return false;
        }

        $updates = array_merge($updates, [
            'hunger_level' => $newHunger,
            'thirst_level' => $newThirst,
            'energy_level' => $newEnergy,
            'hygiene_level' => $newHygiene,
            'pet_state' => $newPetState->value,
        ]);

        if ($certificateEligible !== $pet->certificate_eligible) {
            $updates['certificate_eligible'] = $certificateEligible;
        }

        $pet->update($updates);

        return true;
    }

    /**
     * Get the thirst decay rate per hour for a breed.
     * Mutt: -10%/hr, Border Collie: -15%/hr
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
     * Process hygiene decay — gradual reduction.
     * Outside quiet hours only.
     */
    private function processHygieneDecay(Pet $pet, int $currentHygiene, int $minutesElapsed, bool $isQuiet): int
    {
        if ($isQuiet || $currentHygiene === 0) {
            return $currentHygiene;
        }

        // Gradual hygiene decay: ~1.5%/hr
        $hygieneDecay = (1.5 / 60) * $minutesElapsed;

        return max(0, (int) round($currentHygiene - $hygieneDecay));
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
     *
     * @return array<string, mixed> Updates to apply to the pet.
     */
    private function trackZeroMetrics(Pet $pet, int $hunger, int $thirst, int $energy, int $hygiene): array
    {
        $updates = [];

        // Track hunger hitting 0
        if ($hunger === 0 && $pet->hunger_zero_since === null) {
            $updates['hunger_zero_since'] = now();
        } elseif ($hunger > 0 && $pet->hunger_zero_since !== null) {
            $updates['hunger_zero_since'] = null;
        }

        // Track thirst hitting 0
        if ($thirst === 0 && $pet->thirst_zero_since === null) {
            $updates['thirst_zero_since'] = now();
        } elseif ($thirst > 0 && $pet->thirst_zero_since !== null) {
            $updates['thirst_zero_since'] = null;
        }

        // Track energy hitting 0
        if ($energy === 0 && $pet->energy_zero_since === null) {
            $updates['energy_zero_since'] = now();
        } elseif ($energy > 0 && $pet->energy_zero_since !== null) {
            $updates['energy_zero_since'] = null;
        }

        // Track hygiene hitting 0
        if ($hygiene === 0 && $pet->hygiene_zero_since === null) {
            $updates['hygiene_zero_since'] = now();
        } elseif ($hygiene > 0 && $pet->hygiene_zero_since !== null) {
            $updates['hygiene_zero_since'] = null;
        }

        return $updates;
    }

    /**
     * Determine the appropriate PetStateEnum based on current metrics.
     */
    private function determinePetState(int $hunger, int $thirst, int $energy, int $hygiene, bool $isQuiet): PetStateEnum
    {
        // Sick takes priority
        if ($hygiene === 0 || $energy === 0) {
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
