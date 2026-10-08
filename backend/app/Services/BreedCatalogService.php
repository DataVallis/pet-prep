<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Models\BreedConfig;
use Illuminate\Support\Facades\Cache;

/**
 * The breed catalogue (M5-R06-01, M5-R06_PLAN T1 / T3): one cached read of
 * `breed_configs`, the single source of truth for
 *  - free / paid (`premium_unlock`) — BreedType::isPremium() and the
 *    "free breed of a species" rule used by pairing, payments and the plan
 *    payload (replaces the hard-coded "mutt = free" rules);
 *  - the picker catalogue of GET /api/breeds (species, i18n label key,
 *    search keywords, sort order).
 *
 * Only rows whose slug is a BreedType are part of the catalogue (a pet can
 * only have an enum breed). A breed without a row falls back to
 * BreedType::defaultPremium() (fresh / test database before seeding).
 *
 * Cache: CACHE_SECONDS in the app cache + a per-instance memo (the service is
 * scoped: one per request / queued job). BreedConfig saved / deleted and
 * BreedConfigsSeeder call forget(). An empty table is never cached, so a
 * database seeded after the first read is seen at once.
 */
class BreedCatalogService
{
    public const CACHE_KEY = 'breed-catalog:v1';

    public const CACHE_SECONDS = 300;

    /** @var array<string, array{breed: string, slug: string, species: string, premium: bool, label_key: string, search_keywords: list<string>, sort_order: int}>|null */
    private ?array $memo = null;

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        if (app()->resolved(self::class)) {
            app(self::class)->memo = null;
        }
    }

    /**
     * Catalogue rows keyed by enum value, ordered: species (enum order), free
     * breeds first, then sort_order, then slug.
     *
     * @return array<string, array{breed: string, slug: string, species: string, premium: bool, label_key: string, search_keywords: list<string>, sort_order: int}>
     */
    public function entries(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $this->memo = $cached;
        }

        $entries = $this->load();
        if ($entries !== []) {
            Cache::put(self::CACHE_KEY, $entries, self::CACHE_SECONDS);
            $this->memo = $entries;
        }

        return $entries;
    }

    public function isPremium(BreedType $breed): bool
    {
        return $this->entries()[$breed->value]['premium'] ?? $breed->defaultPremium();
    }

    public function hasConfig(BreedType $breed): bool
    {
        return isset($this->entries()[$breed->value]);
    }

    /**
     * The free breed of a species: the first configured breed of the species
     * without premium (catalogue order), else the first enum breed whose
     * default is free (no config rows yet).
     */
    public function freeBreedFor(Species $species): BreedType
    {
        foreach ($this->entries() as $entry) {
            if ($entry['species'] === $species->value && ! $entry['premium']) {
                return BreedType::from($entry['breed']);
            }
        }

        foreach (BreedType::forSpecies($species) as $breed) {
            if (! $breed->defaultPremium()) {
                return $breed;
            }
        }

        throw new \LogicException("Species {$species->value} has no free breed.");
    }

    /**
     * The picker catalogue for GET /api/breeds: only species in
     * $availableSpecies, optionally one species.
     *
     * @param  list<Species>  $availableSpecies
     * @return list<array{breed: string, slug: string, species: string, premium: bool, free_plan_allowed: bool, challenge_allowed: bool, label_key: string, search_keywords: list<string>, sort_order: int}>
     */
    public function catalogue(array $availableSpecies, ?Species $species = null): array
    {
        $allowed = array_map(fn (Species $s) => $s->value, $availableSpecies);
        $rows = [];

        foreach ($this->entries() as $entry) {
            if (! in_array($entry['species'], $allowed, true)) {
                continue;
            }
            if ($species !== null && $entry['species'] !== $species->value) {
                continue;
            }

            $rows[] = $entry + [
                // PairingService::breedAllowed: the free plan takes only a free breed; the
                // 12-week challenge only a paid one (M5-F03, assertPlanAllowed).
                'free_plan_allowed' => ! $entry['premium'],
                'challenge_allowed' => $entry['premium'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, array{breed: string, slug: string, species: string, premium: bool, label_key: string, search_keywords: list<string>, sort_order: int}>
     */
    private function load(): array
    {
        $speciesOrder = array_flip(Species::values());

        $rows = BreedConfig::query()->get()
            ->map(function (BreedConfig $config): ?array {
                $breed = BreedType::fromSlug($config->breed_slug);
                if ($breed === null) {
                    return null;
                }

                $keywords = is_array($config->search_keywords) ? array_values(array_filter($config->search_keywords, 'is_string')) : [];

                return [
                    'breed' => $breed->value,
                    'slug' => $config->breed_slug,
                    // The enum decides the species (pets_species_breed_check); the
                    // column is the catalogue copy, kept equal by BreedConfig::saving.
                    'species' => $breed->species()->value,
                    'premium' => (bool) $config->premium_unlock,
                    'label_key' => (string) ($config->label_key ?: 'breeds.'.$breed->value),
                    'search_keywords' => $keywords,
                    'sort_order' => (int) $config->sort_order,
                ];
            })
            ->filter()
            ->sort(fn (array $a, array $b): int => [$speciesOrder[$a['species']], $a['premium'], $a['sort_order'], $a['slug']]
                <=> [$speciesOrder[$b['species']], $b['premium'], $b['sort_order'], $b['slug']])
            ->values();

        return $rows->keyBy('breed')->all();
    }
}
