<?php

namespace App\Enums;

/**
 * What kind of mess a `pet_hygiene_events` row is (M5-R02, David
 * 2026-10-06). Mirrored by the pet_hygiene_events_kind_check and
 * pet_daily_routines_event_kind_check constraints.
 *
 * Every MESS kind drops hygiene to 0 and is one `clean` routine (resolved
 * within 2 h counted outside quiet hours, PRODUCT_SPEC §11) — what resolves
 * it differs: poop, accident and the cat's litter accident are cleaned
 * (POST /api/child/pet/clean), chewing is tidied up with a toy
 * (POST /api/child/pet/resolve-chewing), the cat's scratching is resolved by
 * "carry to the scratcher + praise within 3 s" (POST
 * /api/child/pet/scratching/start|finish).
 *
 * M5-R06-05 (cats, CAT_SPEC Q3): `litter_use` is NOT a mess — the cat used
 * the tray properly; it is one `litter_scoop` routine with its own deadline
 * (`due_at`, 4 h / 2 h outside quiet hours) and leaves hygiene alone.
 */
enum HygieneEventKind: string
{
    /** Random daily "kakec" (M1-05, breed_configs.poops_per_day). */
    case Poop = 'poop';

    /** A puppy was not taken out within its hold time (~1 h per month of age, S30 / S31). */
    case Accident = 'accident';

    /** Teething puppy (S32 / S33) or yesterday's walk goal missed (boredom, S33 / S7). */
    case Chewing = 'chewing';

    /** M5-R06-05: the cat used the litter tray — not a mess; scoop it before `due_at` (CAT_SPEC Q3). */
    case LitterUse = 'litter_use';

    /** M5-R06-05: a litter use was not scooped in time → a mess next to the tray (dog hygiene ladder). */
    case LitterAccident = 'litter_accident';

    /** M5-R06-05: "scratched the sofa" — the day after a missed cat play routine (CAT_SPEC Q2 / Q10). */
    case Scratching = 'scratching';

    /** The activity that resolves an event of this kind (routine actor attribution). */
    public function resolvingActivity(): ActivityType
    {
        return match ($this) {
            self::Chewing => ActivityType::ResolvedChewing,
            self::Scratching => ActivityType::ResolvedScratching,
            self::LitterUse => ActivityType::ScoopedLitter,
            default => ActivityType::CleanedPoop,
        };
    }

    /** A mess (hygiene 0 while open, `clean` routine) — every kind but the litter use. */
    public function isMess(): bool
    {
        return $this !== self::LitterUse;
    }

    /**
     * Kinds the cleaning mini-game resolves.
     *
     * @return list<string>
     */
    public static function cleanedByCleaning(): array
    {
        return [self::Poop->value, self::Accident->value, self::LitterAccident->value];
    }

    /**
     * Every mess kind (M5-R06-05: all but `litter_use`).
     *
     * @return list<string>
     */
    public static function messes(): array
    {
        return array_values(array_map(fn (self $k) => $k->value, array_filter(self::cases(), fn (self $k) => $k->isMess())));
    }
}
