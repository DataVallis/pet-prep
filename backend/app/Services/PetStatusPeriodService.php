<?php

namespace App\Services;

use App\Enums\PetStatusPeriodKind;
use App\Models\Pet;
use App\Models\PetStatusPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * History of the periods in which a pet could not be cared for (M2-06):
 * hard stop, illness, inactive (game over / admin). The routine ledger
 * excuses routines that overlap one of them.
 *
 * Called from the Pet `created` / `updated` model hooks, i.e. inside the
 * writer's transaction, for every non-quiet save: the parent's hard stop
 * toggle, EscalationService (illness, game over), Filament edits. Quiet
 * writes (saveQuietly) never change these flags except the end of an
 * illness, whose end is already stored.
 */
class PetStatusPeriodService
{
    public function recordCreated(Pet $pet): void
    {
        $now = now();

        if ($pet->is_hard_stopped) {
            $this->open($pet, PetStatusPeriodKind::HardStop, $now);
        }
        if ($pet->isPaymentLocked()) {
            $this->open($pet, PetStatusPeriodKind::PaymentLock, $this->paymentLockStart($pet, $now));
        }
        if (! $pet->is_active) {
            $this->open($pet, PetStatusPeriodKind::Inactive, $now);
        }
        if ($pet->illness_until !== null && $pet->illness_until->greaterThan($now)) {
            $this->open($pet, PetStatusPeriodKind::Illness, $pet->frozen_at ?? $now, $pet->illness_until);
        }
    }

    public function recordUpdated(Pet $pet): void
    {
        $now = now();

        if ($pet->wasChanged('is_hard_stopped')) {
            $pet->is_hard_stopped
                ? $this->open($pet, PetStatusPeriodKind::HardStop, $now)
                : $this->close($pet, PetStatusPeriodKind::HardStop, $now);
        }

        // M3-11: trial over, unpaid → payment lock (routines excused, training interrupted).
        if ($pet->wasChanged('payment_locked_at')) {
            $pet->isPaymentLocked()
                ? $this->open($pet, PetStatusPeriodKind::PaymentLock, $this->paymentLockStart($pet, $now))
                : $this->close($pet, PetStatusPeriodKind::PaymentLock, $now);
        }

        if ($pet->wasChanged('is_active')) {
            if ($pet->is_active) {
                $this->close($pet, PetStatusPeriodKind::Inactive, $now);
                // Back in play: the routine ledger must look at it again.
                DB::table('pets')->where('id', $pet->id)->update(['routines_next_close_at' => $now]);
            } else {
                $this->open($pet, PetStatusPeriodKind::Inactive, $now);
            }
        }

        if ($pet->wasChanged('illness_until')) {
            $until = $pet->illness_until;
            if ($until !== null && $until->greaterThan($now)) {
                // EscalationService stamps frozen_at = the illness start (the
                // planned start of a walk illness may lie a little earlier).
                $start = $pet->frozen_at !== null && $pet->frozen_at->lessThanOrEqualTo($now) ? $pet->frozen_at : $now;
                $this->open($pet, PetStatusPeriodKind::Illness, $start, $until);
            } else {
                // Cured early (admin) — cut the running illness short. The
                // normal recovery after illness_until changes nothing here.
                PetStatusPeriod::where('pet_id', $pet->id)
                    ->where('kind', PetStatusPeriodKind::Illness->value)
                    ->where('started_at', '<=', $now)
                    ->where('ended_at', '>', $now)
                    ->update(['ended_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /**
     * Start of a payment-lock period (M3-11b): `payment_locked_at` — the
     * instant the lock is due (ChallengeService::lock: the trial end for an
     * expired trial, so a late tick / scheduler outage never counts as
     * program time; the refund time for a refund re-lock) — clamped to
     * [birth, now] and never before the end of the previous payment lock
     * (periods must not overlap, the program clock sums them).
     */
    private function paymentLockStart(Pet $pet, CarbonInterface $now): CarbonInterface
    {
        $start = $pet->payment_locked_at ?? $now;
        if ($pet->born_at !== null && $start->lessThan($pet->born_at)) {
            $start = $pet->born_at;
        }
        $previousEnd = PetStatusPeriod::where('pet_id', $pet->id)
            ->where('kind', PetStatusPeriodKind::PaymentLock->value)
            ->whereNotNull('ended_at')
            ->max('ended_at');
        if ($previousEnd !== null) {
            $previousEnd = Carbon::parse($previousEnd, 'UTC');
            if ($start->lessThan($previousEnd)) {
                $start = $previousEnd;
            }
        }

        return $start->greaterThan($now) ? $now : $start;
    }

    private function open(Pet $pet, PetStatusPeriodKind $kind, CarbonInterface $start, ?CarbonInterface $end = null): void
    {
        $alreadyOpen = PetStatusPeriod::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', now()))
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        PetStatusPeriod::create([
            'pet_id' => $pet->id,
            'kind' => $kind->value,
            'started_at' => $start->copy()->startOfSecond(),
            'ended_at' => $end?->copy()->startOfSecond(),
        ]);
    }

    private function close(Pet $pet, PetStatusPeriodKind $kind, CarbonInterface $at): void
    {
        PetStatusPeriod::where('pet_id', $pet->id)
            ->where('kind', $kind->value)
            ->whereNull('ended_at')
            ->update(['ended_at' => $at->copy()->startOfSecond(), 'updated_at' => $at]);
    }
}
