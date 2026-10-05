<?php

namespace App\Enums;

/**
 * Escalation pushes (M3-02, PRODUCT_SPEC §6/§7). The values equal the
 * PetUpdated event types of the same escalation step, so the app can treat a
 * push tap and a live event alike. Mirrored in the
 * `push_notifications_type_check` constraint.
 */
enum PushType: string
{
    /** Phase 1 — gentle reminder to the caretaker children. */
    case SoftWarning = 'soft_warning';

    /** Phase 2 — critical reminder (high priority, sound, alarm channel). */
    case CriticalAlert = 'critical_alert';

    /** Phase 3 — parent alarm (all parents of the family). */
    case ParentAlarm = 'parent_intervention_alarm';

    /** The pet went to the vet for 12 h — parents + caretakers. */
    case Illness = 'illness_triggered';

    /** Virtual Shelter Intervention — parents + caretakers. */
    case GameOver = 'game_over_virtual_shelter';

    /** Phase 2 and above: high priority, sound, Android channel "alarm". */
    public function isUrgent(): bool
    {
        return $this !== self::SoftWarning;
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
            self::SoftWarning, self::CriticalAlert => 3600,
            self::ParentAlarm => 3 * 3600,
            self::Illness, self::GameOver => 12 * 3600,
        };
    }
}
