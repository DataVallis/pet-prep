<?php

namespace App\Enums;

/**
 * Animal species (M5-R06, CAT_SPEC §1, M5-R06_PLAN T2). Care rules branch by
 * species, not by breed. Mirrored in the CHECK constraints
 * `pets_species_check` and `breed_configs_species_check`.
 *
 * Cats are "dark" until M5-R06-09 (T4): see SpeciesAvailability.
 */
enum Species: string
{
    case Dog = 'dog';
    case Cat = 'cat';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * The breed a pet of this species gets when nobody chose one (old app
     * builds, no profile, a breed the plan does not allow): the free breed
     * of the species (`breed_configs.premium_unlock` false, lowest
     * `sort_order`), falling back to the seeded default when breed configs
     * are missing (empty test database).
     */
    public function freeBreed(): BreedType
    {
        return BreedType::freeBreedFor($this);
    }
}
