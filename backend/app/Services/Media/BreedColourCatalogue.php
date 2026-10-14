<?php

namespace App\Services\Media;

use App\Enums\BreedType;

/**
 * The coat colours a parent may pick for a new pet of a paid breed (M5-R21,
 * David 2026-10-14) — config/breed_colours.php, which mirrors the breed's
 * `coat_color` options in config/breed_appearance.php (standard colours only).
 * Free breeds (mutt, domestic cat) have none: they keep the look pool (M4-10).
 */
class BreedColourCatalogue
{
    /**
     * @return list<array{key: string, value: string, en: string, sl: string}>
     */
    public function for(BreedType $breed): array
    {
        $list = config('breed_colours.'.$breed->value, []);

        return is_array($list) ? array_values($list) : [];
    }

    public function hasColours(BreedType $breed): bool
    {
        return $this->for($breed) !== [];
    }

    /**
     * @return array{key: string, value: string, en: string, sl: string}|null
     */
    public function find(BreedType $breed, ?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($this->for($breed) as $colour) {
            if ($colour['key'] === $key) {
                return $colour;
            }
        }

        return null;
    }

    /** The colour key of a `coat_color` trait value (null when the breed has no such colour). */
    public function keyForValue(BreedType $breed, ?string $value): ?string
    {
        foreach ($this->for($breed) as $colour) {
            if ($colour['value'] === $value) {
                return $colour['key'];
            }
        }

        return null;
    }

    /** 1-based position of the colour in the breed's list (the shared look's pool index). */
    public function position(BreedType $breed, string $key): ?int
    {
        foreach ($this->for($breed) as $i => $colour) {
            if ($colour['key'] === $key) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The colours shared looks are keyed by: the picker list, or — for a paid
     * breed whose breed_colours.php entry is missing (a new breed added without
     * that step) — every `coat_color` option of breed_appearance.php with a
     * stable derived key (`auto-` + hash of the value). Fail safe toward SHARED
     * media: a missing picker list must never silently give every new pet its
     * own (paid) generation. Such keys are not offered in the picker.
     *
     * @return list<array{key: string, value: string, en: string, sl: string}>
     */
    public function lookColours(BreedType $breed): array
    {
        $list = $this->for($breed);
        if ($list !== []) {
            return $list;
        }

        $options = config('breed_appearance.'.$breed->value.'.traits.coat_color', []);
        $colours = [];
        foreach (is_array($options) ? $options : [] as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : $option;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $colours[] = ['key' => self::derivedKey($value), 'value' => $value, 'en' => $value, 'sl' => $value];
        }

        return $colours;
    }

    /** True for a key {@see lookColours()} derived from breed_appearance (no picker entry). */
    public static function isDerivedKey(?string $key): bool
    {
        return $key !== null && str_starts_with($key, 'auto-');
    }

    private static function derivedKey(string $value): string
    {
        return 'auto-'.substr(hash('sha256', $value), 0, 16);
    }

    /**
     * The picker list for the app, labels in $locale (en | sl; anything else → en).
     *
     * @return list<array{key: string, label: string}>
     */
    public function pickerFor(BreedType $breed, string $locale): array
    {
        $lang = $locale === 'sl' ? 'sl' : 'en';

        return array_map(fn (array $c): array => ['key' => $c['key'], 'label' => $c[$lang]], $this->for($breed));
    }
}
