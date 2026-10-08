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
 * - M3-12 (David 2026-10-07): a push never asks for an action the app refuses
 *   at that moment. NotificationService::deliver() asks CareScheduleService
 *   and passes a variant: `wait` ("next meal at 17:00", "water again at
 *   15:30") or `clean_first` (hygiene 0 % blocks food and water); when the
 *   action is not possible again today the reminder is not sent at all.
 *   Hygiene reminders say `tidy` / `clean_and_tidy` while a chewed item is
 *   open (it is tidied up with a toy, not scrubbed), and (cat, M5-R06-05)
 *   `scratcher` / `clean_and_scratcher` while a scratching is open (carried
 *   to the scratcher + praise).
 *
 * Metric keys: hunger | thirst | hygiene (walk reminder: energy); illness uses
 * hygiene | walk (its reason); game over has none.
 */
final class PushCopy
{
    private const METRICS = ['hunger', 'thirst', 'hygiene'];

    private const ILLNESS_REASONS = ['hygiene', 'walk'];

    /** M3-12 copy variants of phase 1 / 2 reminders whose action is refused right now. */
    public const VARIANT_WAIT = 'wait';

    public const VARIANT_CLEAN_FIRST = 'clean_first';

    private const VARIANTS = [self::VARIANT_WAIT, self::VARIANT_CLEAN_FIRST];

    /** M3-12 hygiene variants while a chewed item is open (only chewing / chewing + another mess). */
    public const VARIANT_TIDY = 'tidy';

    public const VARIANT_CLEAN_AND_TIDY = 'clean_and_tidy';

    /** M5-R06-05 hygiene variants while the cat's scratching is open (resolved at the scratcher, not cleaned). */
    public const VARIANT_SCRATCHER = 'scratcher';

    public const VARIANT_CLEAN_AND_SCRATCHER = 'clean_and_scratcher';

    private const HYGIENE_VARIANTS = [self::VARIANT_TIDY, self::VARIANT_CLEAN_AND_TIDY, self::VARIANT_SCRATCHER, self::VARIANT_CLEAN_AND_SCRATCHER];

    /** Cleaning is never refused (outside locks), so only food and water have variants. */
    private const VARIANT_METRICS = ['hunger', 'thirst'];

    public static function locale(?string $locale): string
    {
        return RequestLocale::isSupported($locale) ? (string) $locale : RequestLocale::default();
    }

    public static function title(?string $locale = null): string
    {
        return self::line('title', self::locale($locale));
    }

    /**
     * @param  string|null  $variant  M3-12, phase 1 / 2 hunger / thirst only: `wait` (the action is
     *                                refused now — "next meal at :time") or `clean_first` (hygiene 0 %
     *                                blocks feeding / water); null = the plain "feed / water now" text.
     * @param  array<string, string>  $replace  e.g. ['time' => '17:00'] for `wait`.
     */
    public static function body(PushType $type, ?string $metric, string $audience, ?string $locale = null, ?string $variant = null, array $replace = []): string
    {
        $locale = self::locale($locale);
        $audience = $audience === PushNotification::AUDIENCE_PARENT
            ? PushNotification::AUDIENCE_PARENT
            : PushNotification::AUDIENCE_CHILD;
        $phaseMetric = in_array($metric, self::METRICS, true) ? $metric : 'hunger';

        // M3-12: a reminder never asks for an action the app refuses right now.
        if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)
            && in_array($variant, self::VARIANTS, true)
            && in_array($phaseMetric, self::VARIANT_METRICS, true)) {
            return self::line("{$variant}.{$phaseMetric}", $locale, $replace);
        }
        if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)
            && $phaseMetric === 'hygiene'
            && in_array($variant, self::HYGIENE_VARIANTS, true)) {
            return self::line($variant.'.'.($type === PushType::SoftWarning ? 'soft' : 'critical'), $locale);
        }

        return match ($type) {
            PushType::SoftWarning => self::line("soft.{$phaseMetric}", $locale),
            PushType::CriticalAlert => self::line("critical.{$phaseMetric}", $locale),
            PushType::WalkReminder => self::line('walk_reminder', $locale),
            // M5-R06-04: the cat's daily play reminder (final cat texts: M5-R06-06).
            PushType::PlayReminder => self::line('play_reminder', $locale),
            // M5-R06-05: an open litter use is due within the hour (draft; final cat texts: M5-R06-06).
            PushType::LitterReminder => self::line('litter_reminder', $locale),
            PushType::ParentAlarm => trim(self::line('parent_alarm', $locale).' '.(in_array($metric, self::METRICS, true)
                ? self::line("parent_alarm_detail.{$metric}", $locale)
                : '')),
            PushType::Illness => self::line('illness.'.$audience.'.'.(in_array($metric, self::ILLNESS_REASONS, true) ? $metric : 'other'), $locale),
            PushType::GameOver => self::line("game_over.{$audience}", $locale),
            // M3-11: only parents got the trial reminder (not sent since M3-13; stored rows still render).
            PushType::TrialEnding => self::line('trial_ending', $locale),
            // `no_trial` = the challenge started without a free trial (every birth since
            // M3-13) — parents get no "the free trial has ended".
            PushType::PaymentRequired => self::line('payment_required.'.$audience.($metric === 'no_trial' && $audience === PushNotification::AUDIENCE_PARENT ? '_no_trial' : ''), $locale),
        };
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, string $locale, array $replace = []): string
    {
        $line = trans("push.{$key}", $replace, $locale);

        return is_string($line) ? $line : '';
    }
}
