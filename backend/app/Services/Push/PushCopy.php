<?php

namespace App\Services\Push;

use App\Enums\PushType;
use App\Models\PushNotification;
use App\Support\RequestLocale;

/**
 * Push texts (M3-02, PRODUCT_SPEC §6/§7, DECISIONS 2026-10-05), per language
 * since M1-18: lang/<locale>/push.php. The caller passes the device's
 * language (`device_push_tokens.locale`); null or unsupported → the default
 * (config/locales.php, English).
 *
 * - Title is always "PetPrep"; texts never contain a child's or pet's name
 *   (Expo / APNs / FCM are third parties, and lock screens are public).
 * - Phase 1 / 2 hunger texts are the spec wording; the other metrics follow
 *   the same pattern. Energy is the daily walk, not on the phase ladder: one
 *   `walk_reminder` per day (PR #35) — no "zbolel v 30 minutah"
 *   (a walk can't be missed in 30 min, DECISIONS 2026-10-03).
 * - Phase 3 = the spec sentence for the parent plus what is missing.
 *
 * Metric keys: hunger | thirst | hygiene (walk reminder: energy); illness uses
 * hygiene | walk (its reason); game over has none.
 */
final class PushCopy
{
    private const METRICS = ['hunger', 'thirst', 'hygiene'];

    private const ILLNESS_REASONS = ['hygiene', 'walk'];

    public static function locale(?string $locale): string
    {
        return RequestLocale::isSupported($locale) ? (string) $locale : RequestLocale::default();
    }

    public static function title(?string $locale = null): string
    {
        return self::line('title', self::locale($locale));
    }

    public static function body(PushType $type, ?string $metric, string $audience, ?string $locale = null): string
    {
        $locale = self::locale($locale);
        $audience = $audience === PushNotification::AUDIENCE_PARENT
            ? PushNotification::AUDIENCE_PARENT
            : PushNotification::AUDIENCE_CHILD;
        $phaseMetric = in_array($metric, self::METRICS, true) ? $metric : 'hunger';

        return match ($type) {
            PushType::SoftWarning => self::line("soft.{$phaseMetric}", $locale),
            PushType::CriticalAlert => self::line("critical.{$phaseMetric}", $locale),
            PushType::WalkReminder => self::line('walk_reminder', $locale),
            PushType::ParentAlarm => trim(self::line('parent_alarm', $locale).' '.(in_array($metric, self::METRICS, true)
                ? self::line("parent_alarm_detail.{$metric}", $locale)
                : '')),
            PushType::Illness => self::line('illness.'.$audience.'.'.(in_array($metric, self::ILLNESS_REASONS, true) ? $metric : 'other'), $locale),
            PushType::GameOver => self::line("game_over.{$audience}", $locale),
            // M3-11: only parents get the trial reminder.
            PushType::TrialEnding => self::line('trial_ending', $locale),
            // P7: `no_trial` = the child already had its free trial — parents get no "trial ended".
            PushType::PaymentRequired => self::line('payment_required.'.$audience.($metric === 'no_trial' && $audience === PushNotification::AUDIENCE_PARENT ? '_no_trial' : ''), $locale),
        };
    }

    private static function line(string $key, string $locale): string
    {
        $line = trans("push.{$key}", [], $locale);

        return is_string($line) ? $line : '';
    }
}
