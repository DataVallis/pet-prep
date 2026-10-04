<?php

namespace App\Services;

use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Models\PetDailyWalk;
use App\Models\QuietHours;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
 * Rates come from breed_configs (M1-06), per hour outside quiet hours:
 *   Mutt:          Hunger -8%/hr,  Thirst -10%/hr
 *   Border Collie: Hunger -12%/hr, Thirst -15%/hr
 *   Hygiene:       no gradual decay — random events drop it to 0
 *                  (poops_per_day, HygieneEventService, M1-05)
 *   Energy:        not time-decayed — today's walk: steps /
 *                  daily_steps_required, back to 0 at the family's local
 *                  midnight (PetActivityService, M1-04). No hourly neglect
 *                  clock; the daily walk rule closes each day at midnight
 *                  (DailyWalkService, David 2026-10-03).
 *
 * Unborn (M1-07b): until the child signs the contract (`born_at` null) the
 * pet is skipped entirely — no decay, hygiene events or day close.
 *
 * Frozen (M1-02): while hard-stopped, ill, inactive or game over, metrics
 * don't change and the decay clock is advanced so nothing is caught up later.
 * When an illness ends the pet gets a fresh start (Pet::recoverFromIllnessIfDue).
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
     * Values within this distance of an integer are snapped to it, so float
     * accumulation (e.g. 2.27e-10 left after 750 mutt-hunger minutes) doesn't
     * delay reaching exactly 0 by a tick.
     */
    private const SNAP_EPSILON = 1e-6;

    public function __construct(
        private HygieneEventService $hygieneEvents,
        private DailyWalkService $dailyWalks,
    ) {}

    /**
     * Process metric decay for all pets the scheduler should tick.
     * Called every minute by the scheduler.
     *
     * Only IDs are loaded up front; every pet is re-read under a row lock
     * when its turn comes, so a write made in the meantime (child action,
     * admin edit) is never overwritten by a stale copy.
     *
     * Inactive / game-over pets are not loaded; Pet's `updating` hook restarts
     * their decay clock if they are ever re-activated. Unborn pets (waiting
     * for the contract, M1-07b) are not loaded either; birth starts the clock.
     *
     * @return array{processed: int, updated: int}
     */
    public function processAllActivePets(): array
    {
        $petIds = Pet::born()
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->orderBy('id')
            ->pluck('id');

        $processed = 0;
        $updated = 0;

        foreach ($petIds as $petId) {
            $result = $this->processPetDecayById($petId);
            if ($result === null) {
                continue; // deleted in the meantime
            }

            $processed++;
            if ($result) {
                $updated++;
            }
        }

        return ['processed' => $processed, 'updated' => $updated];
    }

    /**
     * Process metric decay for a single pet (one scheduler tick).
     *
     * The given model is only used for its ID: the row is re-read with
     * `SELECT … FOR UPDATE` inside a transaction and the result is copied back
     * into $pet. Any other code that changes metrics must take the same lock
     * (see backend/CLAUDE.md).
     *
     * @return bool True if a displayed value changed (and was broadcast).
     */
    public function processPetDecay(Pet $pet): bool
    {
        $result = $this->processPetDecayById($pet->id, $pet);

        return $result ?? false;
    }

    /**
     * @return bool|null Null if the pet no longer exists.
     */
    private function processPetDecayById(int $petId, ?Pet $target = null): ?bool
    {
        $work = function () use ($petId): array {
            $locked = Pet::whereKey($petId)->lockForUpdate()->first();
            if (! $locked) {
                return [null, false];
            }

            return [$locked, $this->decayLockedPet($locked)];
        };

        // The scheduler calls this outside any transaction → own transaction.
        // If a caller already opened one, the row lock lives in that
        // transaction until it commits; a nested savepoint would add nothing.
        [$locked, $changed] = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);

        if (! $locked) {
            return null;
        }

        if ($target) {
            $target->setRawAttributes($locked->getAttributes(), true);
        }

        // Broadcast after commit (no external I/O inside a transaction) and
        // only when something the parent sees changed: one per pet per tick.
        if ($changed && $locked->is_active) {
            PetUpdated::afterCommit($locked, 'metric_changed');
        }

        return $changed;
    }

    /**
     * Bring a pet the caller has already locked up to date (one tick, no
     * broadcast). Child actions (M1-07 feed / water) call this inside their
     * transaction before setting a metric to 100 %, so decay owed since the
     * last tick is applied to the old value — not to the new 100 % — and due
     * hygiene events / the midnight are seen. The caller broadcasts once.
     *
     * @return bool True if a displayed value / state changed.
     */
    public function catchUpLocked(Pet $locked): bool
    {
        return $this->decayLockedPet($locked);
    }

    /**
     * The pet state for the pet's current displayed metrics at $now (same
     * rules as the tick). Used by child actions so the video changes with
     * the action, not a minute later.
     */
    public function derivePetState(Pet $pet, CarbonInterface $now): PetStateEnum
    {
        $isQuiet = $pet->quietHours()?->isQuietNow($now) ?? false;
        $shown = $pet->displayMetrics();

        return $this->determinePetState(
            $shown['hunger_level'],
            $shown['thirst_level'],
            $shown['energy_level'],
            $shown['hygiene_level'],
            $isQuiet,
        );
    }

    /**
     * Apply one tick to a freshly locked pet row. All writes are quiet; the
     * caller broadcasts after commit.
     *
     * Elapsed time is measured only from `last_decay_at` — never `updated_at`,
     * so unrelated writes (child actions, webhooks, escalation) don't eat
     * decay. Missed ticks are caught up in full.
     *
     * @return bool True if a displayed (rounded) metric, the pet state or
     *              certificate eligibility changed.
     */
    private function decayLockedPet(Pet $pet): bool
    {
        // Whole seconds: the timestamp column stores no fractions, so the
        // value we compute elapsed time to must equal the value we persist.
        $now = now()->startOfSecond();

        // Unborn (contract not signed yet, M1-07b): nothing runs, nothing is
        // written — signing the contract starts every clock.
        if ($pet->isUnborn()) {
            return false;
        }

        // First tick for a pet without a decay clock: just start the clock.
        if ($pet->last_decay_at === null) {
            $this->advanceClock($pet, $now);

            return false;
        }

        // An illness that ended since the last tick: fresh start at
        // illness_until (hygiene 100 %, neglect clocks restart) — also while
        // a hard stop keeps the pet frozen.
        $recovered = $pet->recoverFromIllnessIfDue($now);

        // Frozen states (M1-02): no decay, but keep the clock current so
        // there is no catch-up burst after unfreezing. Hard stop / illness
        // also stamp frozen_at so the neglect clocks can be shifted on thaw.
        if ($this->isFrozen($pet)) {
            if ($pet->isFrozen()) {
                $pet->frozen_at ??= $now;

                // The day still ends at midnight (walk recorded, steps → 0),
                // but a day closed while frozen never makes the dog ill, and
                // a walk illness that comes due while frozen is dropped.
                $this->dailyWalks->closeDayIfNeeded($pet, $now, allowIllness: false);
                if ($pet->walk_illness_due_at !== null && $pet->walk_illness_due_at->lessThanOrEqualTo($now)) {
                    PetDailyWalk::where('pet_id', $pet->id)
                        ->where('illness_due_at', $pet->walk_illness_due_at)
                        ->update(['illness_skipped_at' => $now]);
                    $pet->walk_illness_due_at = null;
                }
            }
            $this->advanceClock($pet, $now);

            return $recovered;
        }

        // A hard stop lifted without a model event: shift the neglect clocks.
        $pet->thawIfDue($now);

        $from = $pet->last_decay_at;
        if ($from->greaterThanOrEqualTo($now)) {
            if ($recovered) {
                $pet->saveQuietly();
            }

            return $recovered;
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
        // What the child saw before this tick (the steps below modify $pet).
        $shownBefore = $pet->displayMetrics();

        // Split the elapsed interval into normal and quiet time.
        ['normal' => $normalSeconds, 'quiet' => $quietSeconds] = QuietHours::splitSecondsBetween($quietHours, $from, $now);

        // Hunger / thirst: full rate outside quiet hours, 10 % inside.
        $weightedHours = ($normalSeconds + $quietSeconds * self::QUIET_HOURS_DECAY_MULTIPLIER) / 3600;

        $newHunger = $this->decayMetric((float) $pet->hunger_level, $breedConfig->hunger_decay_rate * $weightedHours);
        $newThirst = $this->decayMetric((float) $pet->thirst_level, $breedConfig->thirst_decay_rate * $weightedHours);

        // Energy is not time-decayed (M1-04): it follows the step count, which
        // goes back to 0 at the family's local midnight (energy → 0 with it).
        // The midnight also closes the day for the daily walk rule.
        $this->dailyWalks->closeDayIfNeeded($pet, $now, allowIllness: true);
        $newEnergy = (float) $pet->energy_level;

        // Hygiene (M1-05): no gradual decay; scheduled random events that
        // fall into this interval drop it to 0. The neglect clock starts at
        // the event time, also when the tick runs late.
        $this->hygieneEvents->ensureScheduled($pet, $from, $now, $quietHours, $breedConfig);
        $messAt = $this->hygieneEvents->applyDue($pet, $from, $now, $quietHours);
        $newHygiene = (float) $pet->hygiene_level;
        if ($messAt !== null) {
            $newHygiene = 0.0;
            $pet->hygiene_zero_since ??= $messAt;
        }

        // Check for virtual age / certificate eligibility
        $certificateEligible = $this->checkCertificateEligibility($pet);

        // Thresholds follow what the child sees (decision 2026-10-03): the
        // rounded display value drives zero tracking and the pet state, while
        // the precise value keeps decaying underneath.
        $shownHunger = Pet::displayValue($newHunger);
        $shownThirst = Pet::displayValue($newThirst);
        $shownEnergy = Pet::displayValue($newEnergy);
        $shownHygiene = Pet::displayValue($newHygiene);

        // Track when metrics first show 0 % (not energy: daily walk rule)
        $zeroUpdates = $this->trackZeroMetrics($pet, $shownHunger, $shownThirst, $shownHygiene);

        // Determine the appropriate pet state from the displayed metrics
        $newPetState = $this->determinePetState($shownHunger, $shownThirst, $shownEnergy, $shownHygiene, $isQuiet);

        $newMetrics = [
            'hunger_level' => $newHunger,
            'thirst_level' => $newThirst,
            'energy_level' => $newEnergy,
            'hygiene_level' => $newHygiene,
        ];

        $displayChanged = $recovered
            || $certificateEligible !== $pet->certificate_eligible
            || $newPetState !== $pet->pet_state;
        foreach ($newMetrics as $metric => $value) {
            if (Pet::displayValue($value) !== $shownBefore[$metric]) {
                $displayChanged = true;
            }
        }

        $updates = array_merge($zeroUpdates, $newMetrics, [
            'pet_state' => $newPetState->value,
            'last_decay_at' => $now,
            'certificate_eligible' => $certificateEligible,
        ]);

        $pet->forceFill($updates)->saveQuietly();

        return $displayChanged;
    }

    /**
     * Decay is frozen while hard-stopped, ill, inactive or game over.
     */
    private function isFrozen(Pet $pet): bool
    {
        return ! $pet->is_active
            || $pet->is_game_over
            || $pet->isFrozen();
    }

    /**
     * Move the decay clock to $now without touching metrics or broadcasting.
     */
    private function advanceClock(Pet $pet, CarbonInterface $now): void
    {
        $pet->forceFill(['last_decay_at' => $now])->saveQuietly();
    }

    /**
     * Subtract decay and clamp to 0–100 (precise, no rounding except
     * snapping float noise onto integers).
     */
    private function decayMetric(float $current, float $decay): float
    {
        $value = max(0.0, min(100.0, $current - $decay));
        $nearest = round($value);

        return abs($value - $nearest) < self::SNAP_EPSILON ? $nearest : $value;
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
     * for neglect calculations (phase 3 > 1 h, hygiene illness ≥ 6 h, game
     * over 24 h). Takes displayed values: zero means "shows 0 %" (precise < 0.5).
     *
     * Energy has no neglect clock (daily walk rule, David 2026-10-03): 0 %
     * after midnight means "not walked yet"; `energy_zero_since` stays null.
     *
     * @return array<string, mixed> Updates to apply to the pet.
     */
    private function trackZeroMetrics(Pet $pet, int $hunger, int $thirst, int $hygiene): array
    {
        $updates = [];

        if ($pet->energy_zero_since !== null) {
            $updates['energy_zero_since'] = null;
        }

        $metrics = [
            'hunger_zero_since' => $hunger,
            'thirst_zero_since' => $thirst,
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
     * Determine the appropriate PetStateEnum from the displayed (rounded) metrics.
     */
    private function determinePetState(int $hunger, int $thirst, int $energy, int $hygiene, bool $isQuiet): PetStateEnum
    {
        // Sick takes priority. Energy 0 % is "not walked yet today", not
        // sickness (daily walk rule) — it shows as low energy below.
        if ($hygiene <= 0) {
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
