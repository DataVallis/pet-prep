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

    /** Phase 2 and above (not the walk reminder): high priority, Android channel "alarm". */
    public function isUrgent(): bool
    {
        return $this !== self::SoftWarning && $this !== self::WalkReminder;
    }

    /**
     * Sent even while the pet is hard-stopped / inactive / game over / ill,
     * and held (not dropped) over quiet hours: the news itself is the lock.
     */
    public function isLockNews(): bool
    {
        return $this === self::Illness || $this === self::GameOver;
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
            self::Illness, self::GameOver => 12 * 3600,
        };
    }
}
