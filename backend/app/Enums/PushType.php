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

    /**
     * M5-R06-04: the cat's counterpart of the walk reminder — the play meter
     * (energy column, CAT_SPEC §5.2) shows ≤ 30 %. Same rules: normal
     * priority, at most one per family-local day, not before the walk
     * reminder floor; dropped when the wand game cannot start now (M3-12).
     */
    case PlayReminder = 'play_reminder';

    /**
     * M5-R06-05: the cat's litter is waiting — an open litter use whose scoop
     * deadline is less than an hour away (outside quiet hours). Caretaker
     * children, normal priority, once per litter use; dropped when the tray
     * was scooped meanwhile (the app can always scoop outside locks — M3-12).
     */
    case LitterReminder = 'litter_reminder';

    /** Phase 3 — parent alarm (all parents of the family). */
    case ParentAlarm = 'parent_intervention_alarm';

    /** The pet went to the vet for 12 h — parents + caretakers. */
    case Illness = 'illness_triggered';

    /** Virtual Shelter Intervention — parents + caretakers. */
    case GameOver = 'game_over_virtual_shelter';

    /**
     * M3-11: trial day 6 of an unpaid challenge — "the trial ends tomorrow",
     * parents only. **No longer sent since M3-13** (no free trial, David
     * 2026-10-08); kept for stored rows and the DB CHECK.
     */
    case TrialEnding = 'trial_ending';

    /**
     * M3-11 / M3-13: the pet waits for the parent (lock reason
     * `payment_required` — at birth, or when a pre-M3-13 trial ends) —
     * parents + caretakers (kind child copy), once per lock start.
     */
    case PaymentRequired = 'payment_required';

    /** Phase 2 and above (not the walk reminder, not billing news): high priority, Android channel "alarm". */
    public function isUrgent(): bool
    {
        return ! in_array($this, [self::SoftWarning, self::WalkReminder, self::PlayReminder, self::LitterReminder, self::TrialEnding, self::PaymentRequired], true);
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
        return in_array($this, [self::SoftWarning, self::CriticalAlert, self::WalkReminder, self::PlayReminder, self::LitterReminder], true);
    }

    /**
     * The once-a-day reminders outside the phase ladder: the dog's walk and
     * (M5-R06-04) the cat's play — one per family-local day, not before the
     * walk-reminder floor, dropped once the day is over.
     */
    public function isDailyReminder(): bool
    {
        return $this === self::WalkReminder || $this === self::PlayReminder;
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
            self::SoftWarning, self::CriticalAlert, self::WalkReminder, self::PlayReminder, self::LitterReminder => 3600,
            self::ParentAlarm => 3 * 3600,
            self::Illness, self::GameOver, self::TrialEnding, self::PaymentRequired => 12 * 3600,
        };
    }
}
