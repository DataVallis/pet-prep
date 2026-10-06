<?php

namespace App\Enums;

/**
 * What kind of mess a `pet_hygiene_events` row is (M5-R02, David
 * 2026-10-06). Mirrored by the pet_hygiene_events_kind_check and
 * pet_daily_routines_event_kind_check constraints.
 *
 * Every kind drops hygiene to 0 and is one `clean` routine (resolved within
 * 2 h counted outside quiet hours, PRODUCT_SPEC §11) — what resolves it
 * differs: poop and accident are cleaned (POST /api/child/pet/clean),
 * chewing is tidied up with a toy (POST /api/child/pet/resolve-chewing).
 */
enum HygieneEventKind: string
{
    /** Random daily "kakec" (M1-05, breed_configs.poops_per_day). */
    case Poop = 'poop';

    /** A puppy was not taken out within its hold time (~1 h per month of age, S30 / S31). */
    case Accident = 'accident';

    /** Teething puppy (S32 / S33) or yesterday's walk goal missed (boredom, S33 / S7). */
    case Chewing = 'chewing';

    /** The activity that resolves an event of this kind (routine actor attribution). */
    public function resolvingActivity(): ActivityType
    {
        return $this === self::Chewing ? ActivityType::ResolvedChewing : ActivityType::CleanedPoop;
    }

    /**
     * Kinds the cleaning mini-game resolves.
     *
     * @return list<string>
     */
    public static function cleanedByCleaning(): array
    {
        return [self::Poop->value, self::Accident->value];
    }
}
