<?php

namespace App\Enums;

use App\Services\BreedCatalogService;

/**
 * Breeds (M5-R06_PLAN T1, T9): the enum stays (it is used in payments and
 * ~20 files), the free / paid rule is data — `breed_configs.premium_unlock`
 * is the single source of truth (read through BreedCatalogService, cached).
 * Every new case needs: slug(), species(), defaultPremium(), the
 * `pets_breed_type_check` / `pets_species_breed_check` constraints and a
 * BreedConfigsSeeder row.
 */
enum BreedType: string
{
    case Mutt = 'mutt';
    case BorderCollie = 'border_collie';
    case DomesticCat = 'domestic_cat';
    case MaineCoon = 'maine_coon';

    /**
     * Get the breed slug as used in the breed_configs table.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Mutt => 'mutt',
            self::BorderCollie => 'border-collie',
            self::DomesticCat => 'domestic-cat',
            self::MaineCoon => 'maine-coon',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->slug() === $slug) {
                return $case;
            }
        }

        return null;
    }

    public function species(): Species
    {
        return match ($this) {
            self::Mutt, self::BorderCollie => Species::Dog,
            self::DomesticCat, self::MaineCoon => Species::Cat,
        };
    }

    /**
     * Determine if this breed requires a premium unlock (the paid 12-week
     * challenge). Reads `breed_configs.premium_unlock` (M5-R06-01: the one
     * source of truth, cached); defaultPremium() only when the breed has no
     * config row.
     */
    public function isPremium(): bool
    {
        return app(BreedCatalogService::class)->isPremium($this);
    }

    /**
     * Fallback for a breed without a `breed_configs` row (fresh / test
     * database before seeding). Must equal the BreedConfigsSeeder values —
     * BreedCatalogTest checks it. Never use it for a rule directly; call
     * isPremium().
     */
    public function defaultPremium(): bool
    {
        return match ($this) {
            self::Mutt, self::DomesticCat => false,
            self::BorderCollie, self::MaineCoon => true,
        };
    }

    /**
     * The free breed of a species (see Species::freeBreed()).
     */
    public static function freeBreedFor(Species $species): self
    {
        return app(BreedCatalogService::class)->freeBreedFor($species);
    }

    /**
     * @return list<self>
     */
    public static function forSpecies(Species $species): array
    {
        return array_values(array_filter(self::cases(), fn (self $b) => $b->species() === $species));
    }
}
