<?php

namespace App\Services\Push;

use App\Enums\PushType;
use App\Enums\Species;
use App\Models\PushNotification;
use App\Support\RequestLocale;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

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
 *
 * Species (M5-R06-06, plan T8): a cat gets `push.cat.<key>` (EN + SL, the
 * Slovenian "muca" is feminine, so separate texts — not a swapped noun). A key
 * without a cat text is dog-only (walk, chewing — a cat never gets those) and
 * falls back to the dog text; `CatPushTextsTest` keeps that list explicit. A cat
 * key missing in a language falls back to the cat key of the default language
 * (logged), never to a dog text.
 * The cat-only keys (play / litter reminders, scratcher variants) live only
 * under `cat`. Dog output is byte-identical to before (DogPushTextSnapshotTest).
 */
final class PushCopy
{
    private const METRICS = ['hunger', 'thirst', 'hygiene'];

    private const ILLNESS_REASONS = ['hygiene', 'walk'];

    /** M3-12 copy variants of phase 1 / 2 reminders whose action is refused right now. */
    public const VARIANT_WAIT = 'wait';

    public const VARIANT_CLEAN_FIRST = 'clean_first';

    /**
     * M5-R06-06 (QA m3 of R06-05): food / water are refused while a cat's scratching is
     * open, but cleaning does not resolve it (POST /pet/clean leaves it) — "first carry
     * it to the scratcher"; with a mess next to the tray as well, "clean and scratcher".
     */
    public const VARIANT_SCRATCHER_FIRST = 'scratcher_first';

    public const VARIANT_CLEAN_AND_SCRATCHER_FIRST = 'clean_and_scratcher_first';

    /**
     * M5-R06-06 (QA m1): cat only — the same first step, but the meal / water stays refused
     * until :time even once the mess is gone (no "then you can feed it").
     */
    public const WAIT_SUFFIX = '_wait';

    public const VARIANT_CLEAN_FIRST_WAIT = 'clean_first_wait';

    public const VARIANT_SCRATCHER_FIRST_WAIT = 'scratcher_first_wait';

    public const VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT = 'clean_and_scratcher_first_wait';

    private const VARIANTS = [
        self::VARIANT_WAIT, self::VARIANT_CLEAN_FIRST, self::VARIANT_SCRATCHER_FIRST, self::VARIANT_CLEAN_AND_SCRATCHER_FIRST,
        self::VARIANT_CLEAN_FIRST_WAIT, self::VARIANT_SCRATCHER_FIRST_WAIT, self::VARIANT_CLEAN_AND_SCRATCHER_FIRST_WAIT,
    ];

    /** M3-12 hygiene variants while a chewed item is open (only chewing / chewing + another mess). */
    public const VARIANT_TIDY = 'tidy';

    public const VARIANT_CLEAN_AND_TIDY = 'clean_and_tidy';

    /** M5-R06-05 hygiene variants while the cat's scratching is open (resolved at the scratcher, not cleaned). */
    public const VARIANT_SCRATCHER = 'scratcher';

    public const VARIANT_CLEAN_AND_SCRATCHER = 'clean_and_scratcher';

    private const HYGIENE_VARIANTS = [self::VARIANT_TIDY, self::VARIANT_CLEAN_AND_TIDY, self::VARIANT_SCRATCHER, self::VARIANT_CLEAN_AND_SCRATCHER];

    /** Cleaning is never refused (outside locks), so only food and water have variants. */
    private const VARIANT_METRICS = ['hunger', 'thirst'];

    /** Top-level groups that exist only under `push.cat` (the cat's own pushes and variants). */
    public const CAT_ONLY_GROUPS = [
        'play_reminder', 'litter_reminder', 'scratcher', 'clean_and_scratcher', 'scratcher_first', 'clean_and_scratcher_first',
        'clean_first_wait', 'scratcher_first_wait', 'clean_and_scratcher_first_wait',
    ];

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
     *                                blocks feeding / water) or, for a cat, `scratcher_first` /
     *                                `clean_and_scratcher_first` (an open scratching blocks them);
     *                                null = the plain "feed / water now" text.
     * @param  array<string, string>  $replace  e.g. ['time' => '17:00'] for `wait`.
     * @param  Species  $species  M5-R06-06: the pet's species (cat texts under `push.cat`).
     */
    public static function body(PushType $type, ?string $metric, string $audience, ?string $locale = null, ?string $variant = null, array $replace = [], Species $species = Species::Dog): string
    {
        $locale = self::locale($locale);
        $line = fn (string $key, array $with = []): string => self::line($key, $locale, $with, $species);
        $audience = $audience === PushNotification::AUDIENCE_PARENT
            ? PushNotification::AUDIENCE_PARENT
            : PushNotification::AUDIENCE_CHILD;
        $phaseMetric = in_array($metric, self::METRICS, true) ? $metric : 'hunger';

        // M3-12: a reminder never asks for an action the app refuses right now.
        if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)
            && in_array($variant, self::VARIANTS, true)
            && in_array($phaseMetric, self::VARIANT_METRICS, true)) {
            return $line("{$variant}.{$phaseMetric}", $replace);
        }
        if (in_array($type, [PushType::SoftWarning, PushType::CriticalAlert], true)
            && $phaseMetric === 'hygiene'
            && in_array($variant, self::HYGIENE_VARIANTS, true)) {
            return $line($variant.'.'.($type === PushType::SoftWarning ? 'soft' : 'critical'));
        }

        return match ($type) {
            PushType::SoftWarning => $line("soft.{$phaseMetric}"),
            PushType::CriticalAlert => $line("critical.{$phaseMetric}"),
            PushType::WalkReminder => $line('walk_reminder'),
            // M5-R06-04: the cat's daily play reminder (push.cat.play_reminder).
            PushType::PlayReminder => $line('play_reminder'),
            // M5-R06-05: an open litter use is due within the hour (push.cat.litter_reminder).
            PushType::LitterReminder => $line('litter_reminder'),
            PushType::ParentAlarm => trim($line('parent_alarm').' '.(in_array($metric, self::METRICS, true)
                ? $line("parent_alarm_detail.{$metric}")
                : '')),
            // A cat is never sick from a missed walk (CAT_SPEC Q2): a stray `walk` reason reads as `other`.
            PushType::Illness => $line('illness.'.$audience.'.'.(in_array($metric, self::ILLNESS_REASONS, true) && ! ($species === Species::Cat && $metric === 'walk') ? $metric : 'other')),
            PushType::GameOver => $line("game_over.{$audience}"),
            // M3-11: only parents got the trial reminder (not sent since M3-13; stored rows still render).
            PushType::TrialEnding => $line('trial_ending'),
            // `no_trial` = the challenge started without a free trial (every birth since
            // M3-13) — parents get no "the free trial has ended".
            PushType::PaymentRequired => $line('payment_required.'.$audience.($metric === 'no_trial' && $audience === PushNotification::AUDIENCE_PARENT ? '_no_trial' : '')),
        };
    }

    /**
     * The text for $key: a cat's own `push.cat.<key>` when it exists in that language
     * (cat-only groups always resolve there), else the dog key (dog-only keys).
     *
     * @param  array<string, string>  $replace
     */
    private static function line(string $key, string $locale, array $replace = [], Species $species = Species::Dog): string
    {
        $catOnly = in_array(explode('.', $key)[0], self::CAT_ONLY_GROUPS, true);
        if ($species === Species::Cat || $catOnly) {
            if (Lang::has("push.cat.{$key}", $locale, false)) {
                $key = "cat.{$key}";
            } elseif (($default = RequestLocale::default()) !== $locale && Lang::has("push.cat.{$key}", $default, false)) {
                // A language without this cat text: the cat text in the default language, never a dog text.
                Log::warning('Push: cat text missing in this language, using the default language', ['key' => $key, 'locale' => $locale]);
                $key = "cat.{$key}";
                $locale = $default;
            }
        }
        $line = trans("push.{$key}", $replace, $locale);

        return is_string($line) ? $line : '';
    }
}
