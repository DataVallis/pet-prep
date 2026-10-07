<?php

namespace App\Services;

use App\Enums\ChallengePaidSource;
use App\Enums\ChallengeStatus;
use App\Enums\PetPlan;
use App\Enums\PushType;
use App\Events\PetUpdated;
use App\Models\Pet;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pet side of the 12-week challenge (M3-11, PAYMENTS_SPEC P2/P3).
 *
 * Status is derived in one place, Pet::challengeStatus() (free → null;
 * challenge: paid › trial › payment_required). This service owns the
 * writes:
 *
 *  - processTrials() — first step of every `pets:process-decay` tick:
 *      · trial day 6 (≥ 24 h before `trial_ends_at`): one `trial_ending`
 *        push to the parents, once per pet (`trial_reminder_sent_at`);
 *      · trial over and unpaid: lock (`payment_locked_at`). The lock runs
 *        BEFORE decay, so a late tick never charges decay for time after
 *        the trial ended (kinder to the child; a normal tick is ≤ 1 min).
 *  - markPaid() / markRefunded() — called by ChallengeCreditService under
 *    the family + pet row locks.
 *
 * The payment lock is a freeze like the hard stop (Pet::isFrozen): every
 * write is a non-quiet save under the pet's row lock, so the Pet hooks stamp
 * `frozen_at`, record the `payment_lock` status period (routines excused,
 * training sessions interrupted) and, when it lifts, applyThaw() shifts the
 * neglect clocks and restarts the decay clock — the pause never counts
 * against the needs. One PetUpdated after commit per change.
 */
class ChallengeService
{
    public const EVENT_LOCKED = 'payment_required';

    public const EVENT_PAID = 'challenge_paid';

    public const EVENT_REFUNDED = 'challenge_refunded';

    /** The parent's reminder goes out this long before the trial ends ("tomorrow"). */
    public const REMINDER_HOURS_BEFORE = 24;

    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function statusOf(Pet $pet, ?CarbonInterface $now = null): ?ChallengeStatus
    {
        return $pet->challengeStatus($now);
    }

    /**
     * Trial reminders and payment locks due now (one transaction per pet).
     *
     * @return array{reminded: int, locked: int}
     */
    public function processTrials(?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->copy()->startOfSecond();
        $base = fn () => Pet::born()
            ->where('plan', PetPlan::Challenge->value)
            ->whereNull('challenge_paid_at')
            ->where('is_active', true)
            ->where('is_game_over', false);

        $locked = 0;
        foreach ($base()->whereNull('payment_locked_at')->where('trial_ends_at', '<=', $now)->orderBy('id')->pluck('id') as $id) {
            $locked += $this->lockIfDue((int) $id, $now) ? 1 : 0;
        }

        $reminded = 0;
        $reminderEdge = $now->copy()->addHours(self::REMINDER_HOURS_BEFORE);
        foreach ($base()->whereNull('trial_reminder_sent_at')->whereNull('payment_locked_at')
            ->where('trial_ends_at', '>', $now)->where('trial_ends_at', '<=', $reminderEdge)
            ->orderBy('id')->pluck('id') as $id) {
            $reminded += $this->remindIfDue((int) $id, $now) ? 1 : 0;
        }

        return ['reminded' => $reminded, 'locked' => $locked];
    }

    private function lockIfDue(int $petId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($petId, $now): bool {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || ! $pet->is_active || $pet->is_game_over || $pet->isPaymentLocked()
                || $pet->challengeStatus($now) !== ChallengeStatus::PaymentRequired) {
                return false;
            }

            $this->lock($pet, $now);

            return true;
        });
    }

    private function remindIfDue(int $petId, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($petId, $now): bool {
            $pet = Pet::whereKey($petId)->lockForUpdate()->first();
            if ($pet === null || $pet->trial_reminder_sent_at !== null
                || $pet->challengeStatus($now) !== ChallengeStatus::Trial || $pet->trial_ends_at === null) {
                return false;
            }

            // Decided once, whether or not a device gets it (push disabled, no devices).
            $pet->forceFill(['trial_reminder_sent_at' => $now])->saveQuietly();
            $this->notifications->escalation($pet, PushType::TrialEnding);

            return true;
        });
    }

    /**
     * Start the payment lock on a row the caller holds locked (non-quiet save:
     * hooks freeze + status period). Parents + caretakers get one push.
     */
    private function lock(Pet $pet, CarbonInterface $now): void
    {
        $pet->forceFill([
            'payment_locked_at' => $now,
            // A reminder a late tick never sent is not sent after the lock.
            'trial_reminder_sent_at' => $pet->trial_reminder_sent_at ?? $now,
        ])->save();

        PetUpdated::afterCommit($pet, self::EVENT_LOCKED);
        $this->notifications->escalation($pet, PushType::PaymentRequired);

        Log::info('Challenge: payment lock', ['pet_id' => $pet->id]);
    }

    /**
     * The challenge is paid (credit assigned). Lifts a payment lock; the
     * challenge clock keeps running from birth (no trial extension). Caller
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

        return true;
    }

    /**
     * The pet's credit was refunded (PAYMENTS_SPEC §2): unless the 12-week
     * challenge is already finished, the pet is unpaid again — back in its
     * trial if still inside the 7 days, otherwise locked at once (with the
     * lock pushes). Caller holds the pet row lock. Returns true if changed.
     */
    public function markRefunded(Pet $pet, CarbonInterface $now): bool
    {
        if ($pet->plan !== PetPlan::Challenge || $pet->challenge_paid_source !== ChallengePaidSource::Purchase) {
            return false;
        }
        if ($pet->hasReachedSimulationEnd()) {
            return false; // finished challenge: the record stays
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
