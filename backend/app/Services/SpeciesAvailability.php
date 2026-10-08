<?php

namespace App\Services;

use App\Enums\ClientFeature;
use App\Enums\Species;

/**
 * Which species a client may choose for a NEW pet (M5-R06-01, M5-R06_PLAN
 * T4 "cats are dark until finished"):
 *  - dog: always;
 *  - cat: only while `petprep.cats_enabled` (env PETPREP_CATS_ENABLED,
 *    default false) AND the app build declared the `species_cat` client
 *    feature (old builds show any unknown breed as a mutt).
 *
 * Used by GET /api/breeds (catalogue), POST /api/parent/generate-pin (422
 * `species_unavailable`) and POST /api/child/pin-login (a new cat needs the
 * flag; any cat needs the child app's `species_cat` → 422
 * `app_update_required`).
 */
class SpeciesAvailability
{
    public static function catsEnabled(): bool
    {
        return (bool) config('petprep.cats_enabled', false);
    }

    /**
     * @param  list<string>  $features  known ClientFeature values of the app build
     */
    public function isAvailable(Species $species, array $features): bool
    {
        return match ($species) {
            Species::Dog => true,
            Species::Cat => self::catsEnabled() && self::declaresCats($features),
        };
    }

    /**
     * @param  list<string>  $features
     * @return list<Species>
     */
    public function available(array $features): array
    {
        return array_values(array_filter(Species::cases(), fn (Species $s): bool => $this->isAvailable($s, $features)));
    }

    /**
     * The app build can show this species at all (flag aside) — a child
     * signing in to an existing cat needs `species_cat`.
     *
     * @param  list<string>  $features
     */
    public static function appSupports(Species $species, array $features): bool
    {
        return $species === Species::Dog || self::declaresCats($features);
    }

    /**
     * @param  list<string>  $features
     */
    private static function declaresCats(array $features): bool
    {
        return in_array(ClientFeature::SpeciesCat->value, $features, true);
    }
}
