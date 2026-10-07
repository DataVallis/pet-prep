<?php

namespace App\Enums;

/**
 * Escalation pushes (M3-02, PRODUCT_SPEC §6/§7). The values equal the
 * PetUpdated event types of the same escalation step (except walk_reminder,
 * which replaces phase 1 / 2 when energy is the cause), so the app can treat a
 * push tap and a live event alike. Mirrored in the
 * `push_notifications_type_check` constraint.
 */
enum PushType: string
{
    /** Phase 1 — gentle reminder to the caretaker children. */
    case SoftWarning = 'soft_warning';

    /** Phase 2 — critical reminder (high priority, sound, alarm channel). */
    case CriticalAlert = 'critical_alert';

    /**
     * Phase 1 / 2 caused by energy = the daily walk (PR #35 review): a normal
     * reminder, never alarm style, at most one per family-local day and not
     * earlier than 2 h after the day's last quiet stretch ended.
     */
    case WalkReminder = 'walk_reminder';

    /** Phase 3 — parent alarm (all parents of the family). */
    case ParentAlarm = 'parent_intervention_alarm';

    /** The pet went to the vet for 12 h — parents + caretakers. */
    case Illness = 'illness_triggered';

    /** Virtual Shelter Intervention — parents + caretakers. */
    case GameOver = 'game_over_virtual_shelter';

    /**
     * M3-11: trial day 6 of an unpaid challenge — "the trial ends tomorrow",
     * parents only, once per pet (ChallengeService::processTrials).
     */
    case TrialEnding = 'trial_ending';

    /**
     * M3-11: the trial is over and the pet waits for the parent (lock reason
     * `payment_required`) — parents + caretakers (kind child copy), once per
     * lock start.
     */
    case PaymentRequired = 'payment_required';

    /** Phase 2 and above (not the walk reminder, not billing news): high priority, Android channel "alarm". */
    public function isUrgent(): bool
    {
        return ! in_array($this, [self::SoftWarning, self::WalkReminder, self::TrialEnding, self::PaymentRequired], true);
    }

    /**
     * Sent even while the pet is hard-stopped / inactive / game over / ill /
     * payment-locked, and held (not dropped) over quiet hours: the news itself
     * is the lock (or, for the trial reminder, a once-only billing notice).
     */
    public function isLockNews(): bool
    {
        return in_array($this, [self::Illness, self::GameOver, self::TrialEnding, self::PaymentRequired], true);
    }

    /**
     * Care reminders that ask the child to do something (feed, water, clean,
     * walk) — M3-12: only children who can act right now get them (not a
     * caretaker who still has to sign their contract).
     */
    public function asksChildToAct(): bool
    {
        return in_array($this, [self::SoftWarning, self::CriticalAlert, self::WalkReminder], true);
    }

    /** Android notification channel (created by the app, M3-02). */
    public function androidChannel(): string
    {
        return $this->isUrgent() ? 'alarm' : 'default';
    }

    /**
     * Seconds Expo / APNs / FCM keep trying to deliver: a reminder that
     * arrives hours late is wrong, an illness / game over is still news.
     */
    public function ttlSeconds(): int
    {
        return match ($this) {
            self::SoftWarning, self::CriticalAlert, self::WalkReminder => 3600,
            self::ParentAlarm => 3 * 3600,
            self::Illness, self::GameOver, self::TrialEnding, self::PaymentRequired => 12 * 3600,
        };
    }
}
