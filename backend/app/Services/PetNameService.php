<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Models\Pet;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Optional pet name (M5-R08, David 2026-10-09). Set, changed or cleared only
 * by a parent of the pet's family (PATCH /api/parent/pets/{pet}/name —
 * UpdatePetNameRequest + PetPolicy::rename). The name is ONLY a label in the
 * apps: never put it into push texts, server-generated sentences or AI
 * prompts (Slovenian declension, minimum data about the child's world).
 *
 * Rules: trimmed, inner whitespace collapsed, typographic apostrophe ’ → ',
 * NFC; 1–20 characters (code points after NFC); letters (\p{L} + combining
 * marks), space, hyphen and apostrophe; at least one letter; not on the short
 * EN / SL filter list in config/pet_names.php. Empty / null = no name.
 */
class PetNameService
{
    public const EVENT_TYPE = 'pet_renamed';

    /** Allowed characters (after normalize()). */
    public const PATTERN = "/^[\\p{L}\\p{M}' \\-]+$/u";

    /** Separators removed for the "spacing tricks" check (F-u-c-k, pi zda). */
    private const SEPARATORS = "/[ '\\-]+/u";

    public static function maxLength(): int
    {
        return (int) config('pet_names.max_length', 20);
    }

    /**
     * Canonical form of user input; null for "no name" (null, empty or only
     * whitespace). Non-strings are returned unchanged so validation rejects
     * them.
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        $name = str_replace("\u{2019}", "'", $value);
        if (class_exists(Normalizer::class)) {
            $name = Normalizer::normalize($name, Normalizer::FORM_C) ?: $name;
        }
        $name = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    /**
     * Valid characters and at least one letter (length is checked separately).
     */
    public static function hasValidCharacters(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1 && preg_match('/\p{L}/u', $name) === 1;
    }

    /**
     * False when the name hits the filter list (config/pet_names.php):
     * case- and diacritic-insensitive, on whole words, on the name with
     * spaces / hyphens / apostrophes removed, and — for `fragments` — inside
     * that joined name.
     */
    public function isAllowed(string $name): bool
    {
        $folded = self::foldForFilter($name);
        $words = array_values(array_filter(preg_split(self::SEPARATORS, $folded) ?: [], fn (string $w): bool => $w !== ''));
        $joined = implode('', $words);

        $blockedWords = array_map(self::foldForFilter(...), (array) config('pet_names.words', []));
        foreach ([...$words, $joined] as $candidate) {
            if (in_array($candidate, $blockedWords, true)) {
                return false;
            }
        }

        foreach ((array) config('pet_names.fragments', []) as $fragment) {
            $fragment = self::foldForFilter((string) $fragment);
            if ($fragment !== '' && str_contains($joined, $fragment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower case without diacritics: NFD, combining marks dropped, then the
     * letters NFD does not decompose (đ, ł, ø, ß …) mapped by hand.
     */
    public static function foldForFilter(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
        }
        $text = (string) preg_replace('/\p{M}+/u', '', $text);

        return strtr($text, ['đ' => 'd', 'ł' => 'l', 'ø' => 'o', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ı' => 'i']);
    }

    /**
     * Set / change / clear the name of a pet (already authorized). Under the
     * pet row lock like every other pet writer (the `updating` hook may run
     * illness recovery / thaw on the fresh row); one PetUpdated
     * (`pet_renamed`) after commit when the name changed, none otherwise.
     *
     * @param  string|null  $name  normalized + validated (UpdatePetNameRequest)
     */
    public function rename(Pet $pet, ?string $name): Pet
    {
        return DB::transaction(function () use ($pet, $name): Pet {
            /** @var Pet $locked */
            $locked = Pet::whereKey($pet->id)->lockForUpdate()->firstOrFail();

            if ($locked->name === $name) {
                return $locked;
            }

            $locked->update(['name' => $name]);
            PetUpdated::afterCommit($locked, self::EVENT_TYPE);

            return $locked;
        });
    }
}
