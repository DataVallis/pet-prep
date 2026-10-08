<?php

namespace App\Services;

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Enums\PushType;
use App\Events\PetUpdated;
use App\Models\Pet;
use App\Services\Media\PetMediaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pet side of the 12-week challenge (M3-11, PAYMENTS_SPEC; M3-13 — no
 * free trial any more, David 2026-10-08 10:28).
 *
 * Status is derived in one place, Pet::challengeStatus() (free → null;
 * challenge: paid › trial (only a pre-M3-13 trial still running) ›
 * payment_required). This service owns the writes:
 *
 *  - lockAtBirth() — PetActivityService::signContract: an unpaid challenge
 *    is payment-locked at the moment it is born (the challenge starts with
 *    a purchase; the free mutt is the free try-out).
 *  - processTrials() — first step of every `pets:process-decay` tick: locks
 *    every born, unpaid challenge that is payment_required but not locked
 *    yet (a pre-M3-13 trial that just ended; a pet born while payments were
 *    not enforced, once they are; admin-created pets). The lock runs BEFORE
 *    decay, so a late tick never charges decay for time after the trial.
 *    No "trial ends tomorrow" reminder any more (M3-13).
 *  - markPaid() / markRefunded() — called by ChallengeCreditService under
 *    the family + pet row locks.
 *
 * The payment lock is a freeze like the hard stop (Pet::isFrozen): every
 * write is a non-quiet save under the pet's row lock, so the Pet hooks stamp
 * `frozen_at`, record the `payment_lock` status period (routines excused,
 * training sessions interrupted) and, when it lifts, applyThaw() shifts the
 * neglect clocks and restarts the decay clock — the pause never counts
 * against the needs. The lock time is not program time either (M3-11b):
 * the 12-week clock and the dog's age stand still (Pet::programBirthAt,
 * from the `payment_lock` status periods). One PetUpdated after commit per change.
 */
class ChallengeService
{
    public const EVENT_LOCKED = 'payment_required';

    public const EVENT_PAID = 'challenge_paid';

    public const EVENT_REFUNDED = 'challenge_refunded';

    /** M5-F02: an unpaid mutt challenge became the free mutt (data migration). */
    public const EVENT_FREE_PLAN = 'plan_free';

    /** Push metric of a lock without a preceding free trial (parent copy without "the trial has ended"). */
    public const PUSH_NO_TRIAL = 'no_trial';

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function statusOf(Pet $pet, ?CarbonInterface $now = null): ?ChallengeStatus
    {
        return $pet->challengeStatus($now);
    }

    /** True when the pet's challenge started without a free trial (every birth since M3-13). */
    public static function startedWithoutTrial(Pet $pet): bool
    {
        return $pet->born_at !== null && $pet->trial_ends_at !== null && $pet->trial_ends_at->lessThanOrEqualTo($pet->born_at);
    }

    /**
     * M3-13: a challenge pet just born (contract signed) that nobody has paid
     * for is payment-locked at once — the lock, and with it the `payment_lock`
     * status period (program clock, M3-11b), starts at the birth. Caller holds
     * the pet row lock, has saved the birth and broadcasts the contract (that
     * one PetUpdated carries the locked state — no second broadcast here).
     * Parents + caretakers get the lock push (parent copy without "trial").
     * No-op with payments not enforced and for a free / paid pet. Returns
     * true when it locked.
     */
    public function lockAtBirth(Pet $pet, CarbonInterface $now): bool
    {
        if ($pet->isUnborn() || $pet->isPaymentLocked() || ! $pet->is_active || $pet->is_game_over
            || $pet->challengeStatus($now) !== ChallengeStatus::PaymentRequired) {
            return false;
        }

        $this->lock($pet, $now, $pet->born_at, broadcast: false);

        return true;
    }

    /**
     * Payment locks due now (one transaction per pet). No "trial ends
     * tomorrow" reminder any more (M3-13).
     *
     * @return array{locked: int}
     */
    public function processTrials(?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfSecond();
        // Kill switch: no locks before purchases are live.
        if (! Pet::paymentsEnforced()) {
            return ['locked' => 0];
        }

        $due = Pet::born()
            ->where('plan', PetPlan::Challenge->value)
            ->whereNull('challenge_paid_at')
            ->where('is_active', true)
            ->where('is_game_over', false)
            ->whereNull('payment_locked_at')
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        $locked = 0;
        foreach ($due as $id) {
            $locked += $this->lockIfDue((int) $id, $now) ? 1 : 0;
        }

        return ['locked' => $locked];
    }

    private function lockIfDue(int $petId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($petId, $now): bool {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || ! $pet->is_active || $pet->is_game_over || $pet->isPaymentLocked()
                || $pet->challengeStatus($now) !== ChallengeStatus::PaymentRequired) {
                return false;
            }

            // M3-11b: an expired pre-M3-13 trial is locked since its end — a late
            // tick or a scheduler outage is not program time. A pet without a
            // trial that reaches the tick unlocked (born while payments were not
            // enforced, created born by an admin) has been playing: locked from now.
            $due = $pet->trial_ends_at->greaterThan($pet->born_at) ? $pet->trial_ends_at : $now;
            $this->lock($pet, $now, $due);

            return true;
        });
    }

    /**
     * Start the payment lock on a row the caller holds locked (non-quiet save:
     * hooks freeze + status period). Parents + caretakers get one push.
     *
     * `payment_locked_at` = $since, the instant the lock became due (≤ $now):
     * the trial end for an expired pre-M3-13 trial, the birth for a lock at
     * birth (M3-13), $now for a refund re-lock (the pet was paid — and
     * playing — until the refund). The `payment_lock` status period starts
     * there, so the program clock (M3-11b) excludes the gap between the trial
     * end and the tick. Needs are unaffected: the freeze (`frozen_at`) starts
     * now, and decay before the tick was never charged (the lock runs before
     * decay in the same tick). `$broadcast` false: the caller emits the one
     * PetUpdated of this change (lockAtBirth → the contract's broadcast).
     */
    private function lock(Pet $pet, CarbonInterface $now, ?CarbonInterface $since = null, bool $broadcast = true): void
    {
        $since = $since !== null && $since->lessThan($now) ? $since->copy()->startOfSecond() : $now;
        $pet->forceFill([
            'payment_locked_at' => $since,
            // Pre-M3-13 rows: the old "trial ends tomorrow" reminder is never sent after a lock.
            'trial_reminder_sent_at' => $pet->trial_reminder_sent_at ?? $now,
        ])->save();

        if ($broadcast) {
            PetUpdated::afterCommit($pet, self::EVENT_LOCKED);
        }
        // A challenge without a free trial (every birth since M3-13) gets the parent
        // copy without "the free trial has ended".
        $this->notifications->escalation($pet, PushType::PaymentRequired, self::startedWithoutTrial($pet) ? self::PUSH_NO_TRIAL : null);

        Log::info('Challenge: payment lock', ['pet_id' => $pet->id]);
    }

    /**
     * The challenge is paid (credit assigned). Lifts a payment lock; the
     * challenge clock resumes where it stopped at the lock (M3-11b: lock time
     * is not program time — Pet::programSecondsAt), no trial extension. Caller
     * holds the pet row lock. Returns false when it already was paid.
     */
    public function markPaid(Pet $pet, ChallengePaidSource $source, CarbonInterface $now): bool
    {
        if ($pet->challenge_paid_at !== null) {
            return false;
        }

        $pet->forceFill([
            'challenge_paid_at' => $now,
            'challenge_paid_source' => $source,
            'payment_locked_at' => null,
        ])->save();

        PetUpdated::afterCommit($pet, self::EVENT_PAID);

        // P6: a purchase entitles the pet to the full video set — queue what is
        // missing (born pets only; queueStateVideos skips stored / running slots).
        if ($source === ChallengePaidSource::Purchase) {
            $petId = $pet->id;
            DB::afterCommit(function () use ($petId): void {
                try {
                    $paid = Pet::find($petId);
                    if ($paid !== null) {
                        app(PetMediaService::class)->queueStateVideos($paid);
                    }
                } catch (\Throwable $e) {
                    Log::error('Challenge: full media set not queued', ['pet_id' => $petId, 'error' => $e->getMessage()]);
                    report($e);
                }
            });
        }

        return true;
    }

    /**
     * M5-F02 / M5-F03 (PAYMENTS_SPEC P4: the mutt is free forever): an UNPAID
     * challenge of a breed without premium (the mutt — trial, payment
     * required or not born yet) becomes the free plan, so it can never be
     * bought or locked. Paid challenges (purchase, grandfathered, admin) are
     * never touched (`plan.display_type` shows them as free).
     *
     * Under the row lock with a non-quiet save, so the Pet hooks treat a
     * lifted payment lock exactly like a payment (M3-11b): the open
     * `payment_lock` status period is closed now (the locked time stays
     * excluded from the program clock — `converted_to_free_at` keeps
     * Pet::paymentLockSpans() reading the periods), applyThaw() shifts the
     * neglect clocks, the decay clock restarts. No media change, no push.
     * One PetUpdated after commit. Returns false when nothing was converted.
     */
    public function convertUnpaidMuttToFree(int $petId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($petId, $now): bool {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || $pet->plan !== PetPlan::Challenge || $pet->challenge_paid_at !== null
                || $pet->breed_type->isPremium()) {
                return false;
            }

            $pet->forceFill([
                'plan' => PetPlan::Free,
                'trial_ends_at' => null,
                'payment_locked_at' => null,
                'converted_to_free_at' => $now,
            ])->save();

            PetUpdated::afterCommit($pet, self::EVENT_FREE_PLAN);
            Log::info('Challenge: unpaid mutt challenge converted to the free plan', ['pet_id' => $pet->id]);

            return true;
        });
    }

    /**
     * The pet's credit was refunded (PAYMENTS_SPEC §2): unless the 12-week
     * challenge is already finished, the pet is unpaid again — locked at once
     * (with the lock pushes); only a pre-M3-13 pet still inside its old 7-day
     * trial goes back to that trial. A mutt becomes the free plan instead (P4 — never locked).
     * Caller holds the pet row lock. Returns true if changed.
     */
    public function markRefunded(Pet $pet, CarbonInterface $now): bool
    {
        if ($pet->plan !== PetPlan::Challenge || $pet->challenge_paid_source !== ChallengePaidSource::Purchase) {
            return false;
        }
        if ($pet->hasReachedSimulationEnd()) {
            return false; // finished challenge: the record stays
        }

        // PAYMENTS_SPEC P4 / M5-F02: a refunded mutt (bought before M5-F03) is never
        // re-locked — it becomes the free mutt (same bookkeeping as the migration).
        if (! $pet->breed_type->isPremium()) {
            $pet->forceFill([
                'challenge_paid_at' => null,
                'challenge_paid_source' => null,
                'plan' => PetPlan::Free,
                'trial_ends_at' => null,
                'payment_locked_at' => null,
                'converted_to_free_at' => $now,
            ])->save();
            PetUpdated::afterCommit($pet, self::EVENT_FREE_PLAN);

            return true;
        }

        $pet->forceFill(['challenge_paid_at' => null, 'challenge_paid_source' => null])->save();

        if ($pet->is_active && ! $pet->is_game_over && $pet->challengeStatus($now) === ChallengeStatus::PaymentRequired) {
            $this->lock($pet, $now);
        } else {
            PetUpdated::afterCommit($pet, self::EVENT_REFUNDED);
        }

        return true;
    }
}
