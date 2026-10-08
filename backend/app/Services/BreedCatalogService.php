<?php

namespace App\Services;

use App\Enums\BreedType;
use App\Enums\Species;
use App\Models\BreedConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * BreedConfigsSeeder call forget(); a manual SQL edit needs
 * `php artisan cache:forget breed-catalog:v1` (PRODUCTION_DEPLOYMENT.md). An
 * empty table is never cached, so a database seeded after the first read is
 * seen at once. A cache outage (Redis / database store down) never breaks a
 * caller: every cache error falls back to a direct read (QA PR #91 m3).
 *
 * Invariant (QA PR #91 M1): every species with configured enum breeds has
 * EXACTLY ONE free breed (`premium_unlock` false). premium_unlock is read live
 * for existing pets (refunds, Free / Paid display, default plans), so a second
 * free dog or no free dog would change payments of existing pets.
 * BreedConfig::saving / deleting refuse such a change (freeBreedViolation()),
 * the Filament form shows the same message. Raw SQL bypasses it — don't.
 */
class BreedCatalogService
{
    public const CACHE_KEY = 'breed-catalog:v1';

    public const CACHE_SECONDS = 300;

    /** @var array<string, array{breed: string, slug: string, species: string, premium: bool, label_key: string, search_keywords: list<string>, sort_order: int}>|null */
    private ?array $memo = null;

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $e) {
            Log::warning('BreedCatalogService: cache forget failed', ['error' => $e->getMessage()]);
        }
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

        try {
            $cached = Cache::get(self::CACHE_KEY);
        } catch (Throwable $e) {
            Log::warning('BreedCatalogService: cache read failed, reading breed_configs directly', ['error' => $e->getMessage()]);
            $cached = null;
        }
        if (is_array($cached) && $cached !== []) {
            return $this->memo = $cached;
        }

        $entries = $this->load();
        if ($entries !== []) {
            try {
                Cache::put(self::CACHE_KEY, $entries, self::CACHE_SECONDS);
            } catch (Throwable $e) {
                Log::warning('BreedCatalogService: cache write failed', ['error' => $e->getMessage()]);
            }
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
     * Why this change would break "exactly one free breed per species", or
     * null when it is fine (QA PR #91 M1). Reads the table directly (never the
     * cache). Only enum breeds count (the catalogue ignores other slugs); only
     * the species of the changed / deleted rows are checked; a species
     * without any enum row is fine (not seeded yet).
     *
     * @param  BreedConfig|null  $changed  a row about to be created / updated (its new attributes)
     * @param  list<int>  $deletedIds  rows about to be deleted
     */
    public function freeBreedViolation(?BreedConfig $changed = null, array $deletedIds = []): ?string
    {
        $rows = [];
        $touched = [];

        foreach (BreedConfig::query()->get(['id', 'breed_slug', 'premium_unlock']) as $row) {
            $breed = BreedType::fromSlug((string) $row->breed_slug);
            if (in_array($row->id, $deletedIds, true)) {
                if ($breed !== null) {
                    $touched[$breed->species()->value] = true;
                }

                continue;
            }
            if ($changed !== null && $changed->exists && $row->id === $changed->id) {
                // The old slug's species is affected too (slug edit).
                if ($breed !== null) {
                    $touched[$breed->species()->value] = true;
                }

                continue;
            }
            $rows[] = [$breed, (bool) $row->premium_unlock];
        }

        if ($changed !== null) {
            $breed = BreedType::fromSlug((string) $changed->breed_slug);
            $rows[] = [$breed, (bool) $changed->premium_unlock];
            if ($breed !== null) {
                $touched[$breed->species()->value] = true;
            }
        }

        foreach (array_keys($touched) as $species) {
            $enumRows = array_filter($rows, fn (array $r): bool => $r[0] !== null && $r[0]->species()->value === $species);
            if ($enumRows === []) {
                continue;
            }
            $free = count(array_filter($enumRows, fn (array $r): bool => ! $r[1]));
            if ($free !== 1) {
                return "Every species needs exactly one free breed (premium off): {$species} would have {$free}. "
                    .'Existing pets read it live (refunds, plan defaults, Free / Paid), so it cannot change while breeds are enum-based.';
            }
        }

        return null;
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
