<?php

namespace App\Enums;

/**
 * Features an app build can show, sent as `features` with POST
 * /api/parent/generate-pin (parent device) and POST /api/child/pin-login
 * (child device) — M5-R02, PR #42 review, orchestrator decision 2026-10-06.
 * A game rule that needs UI an older build lacks is switched on for a NEW
 * pet only when BOTH devices declared it, so neither an old parent nor an
 * old child app ever gets a dog whose events it cannot show / resolve.
 * Unknown values are ignored (forward compatible).
 */
enum ClientFeature: string
{
    /**
     * Puppy accidents + "Pelji ven", chewing + "Pospravi in daj igračo"
     * → `pets.behaviour_events_enabled`.
     */
    case BehaviourEvents = 'behaviour_events';

    /**
     * Training mini-game (M5-R03): POST /api/child/pet/training/start|finish,
     * the `training` payload → `pets.training_enabled`.
     */
    case Training = 'training';

    /**
     * Cats (M5-R06-01, plan T4): the build can show a cat (species step,
     * cat HUD). Unlike the features above it does not switch a game rule on
     * for a pet — it GATES cats: the parent needs it to see / choose a cat
     * (`GET /api/breeds`, `generate-pin`), the child to sign in to a cat
     * (`pin-login` → 422 `app_update_required`). Also needs the server flag
     * `petprep.cats_enabled` (SpeciesAvailability).
     */
    case SpeciesCat = 'species_cat';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }

    /**
     * The known values of a client's list, deduplicated, in case order.
     * Unknown values (a newer app build) are dropped — forward compatible.
     *
     * @param  array<mixed>  $features
     * @return list<string>
     */
    public static function known(array $features): array
    {
        return array_values(array_filter(self::values(), fn (string $v) => in_array($v, $features, true)));
    }
}
