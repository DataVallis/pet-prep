<?php

namespace App\Services\Push;

use App\Enums\PushType;
use App\Models\PushNotification;

/**
 * Slovenian push texts (M3-02, PRODUCT_SPEC §6/§7, DECISIONS 2026-10-05).
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
    public const TITLE = 'PetPrep';

    private const SOFT = [
        'hunger' => 'Tvoj kuža te milo gleda in kaže na posodo s hrano.',
        'thirst' => 'Tvoj kuža te milo gleda in kaže na prazno posodo za vodo.',
        'hygiene' => 'Tvoj kuža te milo gleda in kaže na nered, ki ga je treba počistiti.',
    ];

    private const CRITICAL = [
        'hunger' => 'Če ga ne nahraniš v 30 minutah, bo zbolel.',
        'thirst' => 'Če mu ne daš vode v 30 minutah, bo zbolel.',
        'hygiene' => 'Kuža je naredil nered! Počisti ga čim prej, sicer bo zbolel.',
    ];

    /** Energy = the daily walk: one friendly reminder per day, no illness threat. */
    private const WALK_REMINDER = 'Tvoj kuža danes še ni bil na sprehodu in te čaka s povodcem. Gremo ven?';

    private const PARENT_ALARM = 'Tvoj otrok danes ni poskrbel za psa.';

    private const PARENT_ALARM_DETAIL = [
        'hunger' => 'Kuža je že več kot uro brez hrane.',
        'thirst' => 'Kuža je že več kot uro brez vode.',
        'hygiene' => 'Nered že več kot uro ni počiščen.',
    ];

    private const ILLNESS = [
        PushNotification::AUDIENCE_CHILD => [
            'hygiene' => 'Kuža je predolgo živel v neredu in je zbolel. 12 ur bo na opazovanju pri veterinarju.',
            'walk' => 'Kuža včeraj ni bil na sprehodu in je zbolel. 12 ur bo na opazovanju pri veterinarju.',
            'other' => 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.',
        ],
        PushNotification::AUDIENCE_PARENT => [
            'hygiene' => 'Kuža je zbolel, ker nered ni bil počiščen. 12 ur bo na opazovanju pri veterinarju.',
            'walk' => 'Kuža je zbolel, ker včeraj ni bil na sprehodu. 12 ur bo na opazovanju pri veterinarju.',
            'other' => 'Kuža je zbolel. 12 ur bo na opazovanju pri veterinarju.',
        ],
    ];

    private const GAME_OVER = [
        PushNotification::AUDIENCE_CHILD => 'Kuža je odšel v zavetišče, ker zanj predolgo ni nihče poskrbel. Pogovori se s starši.',
        PushNotification::AUDIENCE_PARENT => 'Kuža je odšel v zavetišče, ker 24 ur ni dobil nujne skrbi. V aplikaciji izberite, kako naprej.',
    ];

    public static function body(PushType $type, ?string $metric, string $audience): string
    {
        return match ($type) {
            PushType::SoftWarning => self::SOFT[$metric ?? 'hunger'] ?? self::SOFT['hunger'],
            PushType::CriticalAlert => self::CRITICAL[$metric ?? 'hunger'] ?? self::CRITICAL['hunger'],
            PushType::WalkReminder => self::WALK_REMINDER,
            PushType::ParentAlarm => trim(self::PARENT_ALARM.' '.(self::PARENT_ALARM_DETAIL[$metric ?? ''] ?? '')),
            PushType::Illness => self::ILLNESS[$audience][$metric ?? 'other'] ?? self::ILLNESS[$audience]['other'],
            PushType::GameOver => self::GAME_OVER[$audience],
        };
    }
}
