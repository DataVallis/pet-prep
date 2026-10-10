<?php

namespace App\Services;

use App\Enums\CatsAvailability;
use App\Enums\ClientFeature;
use App\Enums\Species;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;

/**
 * Which species a client may choose for a NEW pet (M5-R06-01, M5-R06_PLAN
 * T4 "cats are dark until finished"; M5-R06-09 admin switch, David
 * 2026-10-10):
 *  - dog: always;
 *  - cat: only while the cats switch allows the family (AppSettingsService:
 *    /admin → Funkcije `off` | `test_families` | `everyone`; env
 *    PETPREP_CATS_ENABLED=true forces `everyone`) AND the app build declared
 *    the `species_cat` client feature (old builds show any unknown breed as
 *    a mutt).
 *
 * The family is the requesting user's (GET /api/breeds, generate-pin) or the
 * PIN's (child pin-login). Used by GET /api/breeds (catalogue), POST
 * /api/parent/generate-pin (422 `species_unavailable`) and POST
 * /api/child/pin-login (a new cat needs the switch; any cat needs the child
 * app's `species_cat` → 422 `app_update_required`). Existing cats are never
 * gated by the switch.
 */
class SpeciesAvailability
{
    public function __construct(private readonly AppSettingsService $settings) {}

    /**
     * May this family create a new cat (switch only, app build aside)?
     */
    public function catsEnabledFor(?Family $family): bool
    {
        return $this->settings->catsEnabledForFamily($family);
    }

    /**
     * The switch is on for at least someone (test families or everyone) —
     * the admin may create a cat by hand (PetResource).
     */
    public function catsSwitchedOn(): bool
    {
        return $this->settings->catsMode() !== CatsAvailability::Off;
    }

    public static function familyOfUser(?User $user): ?Family
    {
        if ($user === null) {
            return null;
        }
        $familyId = FamilyMember::where('user_id', $user->id)->value('family_id');

        return $familyId !== null ? Family::find($familyId) : null;
    }

    /**
     * @param  list<string>  $features  known ClientFeature values of the app build
     */
    public function isAvailable(Species $species, array $features, ?Family $family): bool
    {
        return match ($species) {
            Species::Dog => true,
            Species::Cat => self::declaresCats($features) && $this->catsEnabledFor($family),
        };
    }

    /**
     * @param  list<string>  $features
     * @return list<Species>
     */
    public function available(array $features, ?Family $family): array
    {
        return array_values(array_filter(Species::cases(), fn (Species $s): bool => $this->isAvailable($s, $features, $family)));
    }

    /**
     * The app build can show this species at all (switch aside) — a child
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
