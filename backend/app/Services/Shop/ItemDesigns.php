<?php

namespace App\Services\Shop;

use App\Enums\Species;
use App\Models\ShopItem;
use Illuminate\Support\Facades\App;

/**
 * The designs of the »Želje« items (M6-W03, David 2026-11-03) — `config/shop_designs.php`.
 *
 * Every durable item has designs per species (the child picks one, the parent
 * buys exactly that one); treats have none (one pack — the content uses the
 * species' treat description under the internal key `dog_treats` / `cat_treats`).
 * The first design of an item is the default (items owned before designs
 * existed, the welcome bundle when the parent picks nothing).
 *
 * The only reader of the config — never `config('shop_designs…')` elsewhere.
 */
final class ItemDesigns
{
    /**
     * The designs of an item for a species, catalogue order (empty for treats / the bundle / unknown keys).
     *
     * @return list<array{key: string, name: array{en: string, sl: string}, description: string}>
     */
    public static function for(string $itemKey, Species $species): array
    {
        $list = config("shop_designs.{$species->value}.{$itemKey}", []);

        return is_array($list) ? array_values($list) : [];
    }

    /** Does the item come in designs (durable items: yes; treats / bundle: no)? */
    public static function hasDesigns(string $itemKey, Species $species): bool
    {
        return self::for($itemKey, $species) !== [];
    }

    /** The default design of an item (its first one), or null for an item without designs. */
    public static function defaultFor(string $itemKey, Species $species): ?string
    {
        return self::for($itemKey, $species)[0]['key'] ?? null;
    }

    /** Is $design one of the item's designs for this species? */
    public static function isValid(string $itemKey, Species $species, ?string $design): bool
    {
        if ($design === null || $design === '') {
            return false;
        }

        foreach (self::for($itemKey, $species) as $d) {
            if ($d['key'] === $design) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every design key with its species and item (all species), in config order.
     *
     * @return array<string, array{species: Species, item_key: string, key: string, name: array{en: string, sl: string}, description: string}>
     */
    public static function all(): array
    {
        $all = [];
        foreach (Species::cases() as $species) {
            foreach ((array) config("shop_designs.{$species->value}", []) as $itemKey => $designs) {
                foreach ((array) $designs as $d) {
                    $all[$d['key']] = ['species' => $species, 'item_key' => (string) $itemKey] + $d;
                }
            }
        }

        return $all;
    }

    /**
     * One design by key (any species), or null.
     *
     * @return array{species: Species, item_key: string, key: string, name: array{en: string, sl: string}, description: string}|null
     */
    public static function find(string $design): ?array
    {
        return self::all()[$design] ?? null;
    }

    /** The design name in the request language (Accept-Language → App locale), English otherwise. */
    public static function name(string $design, ?string $locale = null): string
    {
        $d = self::find($design);
        $locale ??= App::getLocale();

        return (string) ($d['name'][$locale] ?? $d['name']['en'] ?? $design);
    }

    /**
     * The key the CONTENT of an owned item is generated under: its design, or for treats
     * the species' internal treat key (`dog_treats` / `cat_treats`). Null when unknown.
     */
    public static function contentKey(string $itemKey, Species $species, ?string $design): ?string
    {
        if ($itemKey === ShopItem::TREATS) {
            return self::treatKey($species);
        }

        return self::isValid($itemKey, $species, $design) ? $design : self::defaultFor($itemKey, $species);
    }

    public static function treatKey(Species $species): string
    {
        return (string) config("shop_designs.treats.{$species->value}.key", $species->value.'_treats');
    }

    /**
     * The object description used in every prompt of a content key (a design or a treat key).
     */
    public static function description(string $contentKey): ?string
    {
        if (($d = self::find($contentKey)) !== null) {
            return $d['description'];
        }

        foreach (Species::cases() as $species) {
            if (self::treatKey($species) === $contentKey) {
                return (string) config("shop_designs.treats.{$species->value}.description");
            }
        }

        return null;
    }
}
