<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Events\PetUpdated;
use App\Jobs\RegeneratePetStageMedia;
use App\Models\ActivityLog;
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
 * Life stage (M5-R01): every tick writes today's stage (LifeStageService::
 * syncStage — rules switch at the family-local midnight after the weekly
 * birthday). A change between two known stages queues one
 * RegeneratePetStageMedia after commit (the row lock makes it once).
 * Meals whose window lies entirely in quiet hours are done by the parent:
 * at the window start the tick sets hunger to 100 % and writes one
 * `parent_fed_pet` row (CareScheduleService::dueParentMeals).
 *
 * Time Asymmetry: 1 program week = 1 virtual month (program time = real
 *   time minus payment-lock time, M3-11b — Pet::programSecondsAt).
 *   Total MVP: 12 program weeks = 12 virtual months (1 virtual year).
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
        private LifeStageService $lifeStages,
        private CareScheduleService $schedule,
        private BehaviourEventService $behaviour,
        private TrainingService $training,
        private PlayService $play,
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
                return [null, false, false];
            }

            // M5-R05: the dog's play invitation as the apps last saw it (at the
            // previous tick, with the metrics before this one) …
            $offeredBefore = $this->play->offeredInvitationId($locked, $locked->last_decay_at ?? now()->startOfSecond());
            $changed = $this->decayLockedPet($locked);
            // … and now: a flip (shown / gone) is broadcast once.
            $playFlipped = $offeredBefore !== $this->play->offeredInvitationId($locked, now()->startOfSecond());

            return [$locked, $changed, $playFlipped];
        };

        // The scheduler calls this outside any transaction → own transaction.
        // If a caller already opened one, the row lock lives in that
        // transaction until it commits; a nested savepoint would add nothing.
        [$locked, $changed, $playFlipped] = DB::transactionLevel() > 0 ? $work() : DB::transaction($work);

        if (! $locked) {
            return null;
        }

        if ($target) {
            $target->setRawAttributes($locked->getAttributes(), true);
        }

        // Broadcast after commit (no external I/O inside a transaction) and
        // only when something the parent sees changed: one per pet per tick.
        // A play invitation appearing / ending alone is `play` (its payload
        // rides along with any metric_changed anyway — one event per tick).
        if ($changed && $locked->is_active) {
            PetUpdated::afterCommit($locked, 'metric_changed');
        } elseif ($playFlipped && $locked->is_active) {
            PetUpdated::afterCommit($locked, 'play');
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

        // Life stage of today (M5-R01): written with this tick's save; a
        // change between two stages queues the new stage images once.
        // QA PR #67 M1: a payment-locked pet doesn't grow (and costs no AI media)
        // while it waits; the next unfrozen tick catches the stage up once.
        // Since M3-11b the lock time is not program time at all (the age stands
        // still); the skip still keeps a stage switch that falls on the first
        // locked midnight from queueing media before payment.
        $transition = $pet->isPaymentLocked() ? null : $this->lifeStages->syncStage($pet, $now);
        if ($transition !== null) {
            $this->queueStageMedia($pet, $transition);
        }
        $staged = $transition !== null;

        // First tick for a pet without a decay clock: just start the clock.
        if ($pet->last_decay_at === null) {
            $this->advanceClock($pet, $now);

            return $staged;
        }

        // An illness that ended since the last tick: fresh start at
        // illness_until (hygiene 100 %, neglect clocks restart) — also while
        // a hard stop keeps the pet frozen.
        $recovered = $pet->recoverFromIllnessIfDue($now);
        if ($recovered) {
            // M5-R02: the puppy's bladder clock starts at the recovery (fresh start).
            $this->behaviour->holdClockWhileFrozen($pet, $pet->last_decay_at);
        }

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

                // M5-R02: the puppy's bladder clock does not run while frozen.
                $this->behaviour->holdClockWhileFrozen($pet, $now);
                // M5-R05: an invitation whose time comes during a freeze is dropped.
                $this->play->skipWhileFrozen($pet, $now);
            }
            $this->advanceClock($pet, $now);

            return $recovered || $staged;
        }

        // A hard stop lifted without a model event: shift the neglect clocks.
        $pet->thawIfDue($now);

        $from = $pet->last_decay_at;
        if ($from->greaterThanOrEqualTo($now)) {
            if ($recovered || $pet->isDirty('life_stage')) {
                $pet->saveQuietly();
            }

            return $recovered || $staged;
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

        $newThirst = $this->decayMetric((float) $pet->thirst_level, $breedConfig->thirst_decay_rate * $weightedHours);

        // Meals in quiet hours are done by the parent (M5-R01, David
        // 2026-10-05): fed at the window start, one parent_fed_pet row each.
        // Hunger decays piecewise — up to each meal, 100 % at the meal, then
        // on to $now — so a late tick (scheduler outage) gives the same value
        // as ticks in real time. Without a meal this is the single interval.
        $hunger = (float) $pet->hunger_level;
        $cursor = $from;
        foreach ($this->schedule->dueParentMeals($pet, $breedConfig, $from, $now, $quietHours) as [$mealAt]) {
            $hunger = $this->decayMetric($hunger, $breedConfig->hunger_decay_rate * $this->weightedHours($quietHours, $cursor, $mealAt));
            ActivityLog::withoutEvents(fn () => (new ActivityLog)->forceFill([
                'pet_id' => $pet->id,
                'actor_user_id' => null,
                'activity_type' => ActivityType::ParentFedPet,
                'value' => Pet::displayValue($hunger),
                'created_at' => $mealAt->utc(),
            ])->save());
            $hunger = 100.0;
            $cursor = $mealAt;
        }
        $newHunger = $cursor === $from
            ? $this->decayMetric($hunger, $breedConfig->hunger_decay_rate * $weightedHours)
            : $this->decayMetric($hunger, $breedConfig->hunger_decay_rate * $this->weightedHours($quietHours, $cursor, $now));

        // Energy is not time-decayed (M1-04): it follows the step count, which
        // goes back to 0 at the family's local midnight (energy → 0 with it).
        // The midnight also closes the day for the daily walk rule.
        $this->dailyWalks->closeDayIfNeeded($pet, $now, allowIllness: true);
        $newEnergy = (float) $pet->energy_level;

        // Training (M5-R03): a finished day whose training routine was missed
        // costs every command some progress (once per day, pointer on the pet).
        $this->training->applyDecay($pet, $now);

        // Hygiene (M1-05): no gradual decay; scheduled random events that
        // fall into this interval drop it to 0. The neglect clock starts at
        // the event time, also when the tick runs late.
        // Behaviour events (M5-R02) are messes too: the day's chewing event is
        // decided after the midnight close (yesterday's walk is known) and
        // applied with the hygiene events; puppy accidents come due on the
        // bladder clock. The earliest mess starts the neglect clock.
        $this->hygieneEvents->ensureScheduled($pet, $from, $now, $quietHours, $breedConfig);
        $this->behaviour->ensureChewingScheduled($pet, $from, $now, $quietHours);
        // Play & cuddle (M5-R05): decide today's invitations, end the old ones.
        // Mood only — nothing here touches a metric.
        $this->play->ensureInvitationsScheduled($pet, $from, $now, $quietHours);
        $this->play->expireDue($pet, $now);
        $messAt = $this->hygieneEvents->applyDue($pet, $from, $now, $quietHours);
        $accidentAt = $this->behaviour->applyDueAccidents($pet, $from, $now, $quietHours);
        if ($accidentAt !== null && ($messAt === null || $accidentAt->lessThan($messAt))) {
            $messAt = $accidentAt;
        }
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
            || $staged
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
     * New reference image (+ state videos) for the new life stage, queued
     * after the tick's commit (M5-R01).
     *
     * @param  array{from: LifeStage, to: LifeStage}  $transition
     */
    private function queueStageMedia(Pet $pet, array $transition): void
    {
        $petId = $pet->id;
        Log::info('PetDecayService: life stage changed', ['pet_id' => $petId, 'from' => $transition['from']->value, 'to' => $transition['to']->value]);

        DB::afterCommit(fn () => RegeneratePetStageMedia::dispatch($petId));
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
     * Decay hours between two instants: full rate outside quiet hours, 10 % inside.
     */
    private function weightedHours(?QuietHours $quietHours, CarbonInterface $from, CarbonInterface $to): float
    {
        ['normal' => $normal, 'quiet' => $quiet] = QuietHours::splitSecondsBetween($quietHours, $from, $to);

        return ($normal + $quiet * self::QUIET_HOURS_DECAY_MULTIPLIER) / 3600;
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
     * Must have reached 12 virtual months (12 program weeks — payment-lock time
     * excluded, M3-11b) with satisfactory performance.
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
