<?php

namespace App\Services;

use App\Events\PetUpdated;
use App\Models\Pet;
use Illuminate\Support\Facades\DB;
use Normalizer;
use RuntimeException;

/**
 * Optional pet name (M5-R08, David 2026-10-09). Set, changed or cleared only
 * by a parent of the pet's family (PATCH /api/parent/pets/{pet}/name —
 * UpdatePetNameRequest + PetPolicy::rename). The name is ONLY a label in the
 * apps: never put it into push texts, server-generated sentences or AI
 * prompts (Slovenian declension, minimum data about the child's world).
 *
 * Rules: trimmed, inner whitespace collapsed, typographic apostrophe ’ → ',
 * NFKC (compatibility forms — fullwidth, math-bold, modifier letters,
 * ligatures — are stored as plain letters, so they cannot dodge the filter);
 * 1–20 characters (code points after NFKC); letters (\p{L} + combining
 * marks), space, hyphen and apostrophe; at least one letter; no invisible
 * Hangul fillers, no enclosing marks, no run of 3+ combining marks (Zalgo);
 * not on the short EN / SL filter list in config/pet_names.php (with its
 * allowlist). Empty / null = no name. Requires ext-intl (Normalizer) — there
 * is deliberately no fallback without it.
 */
class PetNameService
{
    public const EVENT_TYPE = 'pet_renamed';

    /** Allowed characters (after normalize()). */
    public const PATTERN = "/^[\\p{L}\\p{M}' \\-]+$/u";

    /** Separators removed for the "spacing tricks" check (F-u-c-k, pi zda). */
    private const SEPARATORS = "/[ '\\-]+/u";

    /**
     * Letters that render as nothing (Hangul fillers U+115F, U+1160, U+3164,
     * U+FFA0), enclosing marks (U+20DD …) and runs of 3+ combining marks
     * (Zalgo) are refused although they are \p{L} / \p{M}.
     */
    private const INVISIBLE_OR_STACKED = '/[\x{115F}\x{1160}\x{3164}\x{FFA0}]|\p{Me}|\p{M}{3,}/u';

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

        $name = self::compatibilityNormalize(str_replace("\u{2019}", "'", $value), decompose: false);
        if ($name === false) {
            return $value; // not valid UTF-8 — left as is so validation rejects it
        }
        $name = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));

        return $name === '' ? null : $name;
    }

    /**
     * Valid characters and at least one letter (length is checked separately).
     */
    public static function hasValidCharacters(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1
            && preg_match('/\p{L}/u', $name) === 1
            && preg_match(self::INVISIBLE_OR_STACKED, $name) === 0;
    }

    /**
     * False when the name hits the filter list (config/pet_names.php):
     * case- and diacritic-insensitive, on whole words, on the name with
     * spaces / hyphens / apostrophes removed, and — for `fragments` — inside
     * that joined name once the `allowed` names (Shitzu …) are cut out of it.
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

        // Allowlisted names are removed before the fragment check (not
        // replaced by a separator, so "fu<allowed>ck" still reads "fuck").
        $allowed = array_values(array_filter(array_map(self::foldForFilter(...), (array) config('pet_names.allowed', [])), fn (string $a): bool => $a !== ''));
        if ($allowed !== []) {
            $joined = str_replace($allowed, '', $joined);
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
     * Lower case without diacritics: NFKD (compatibility forms → plain
     * letters), lower case, combining marks dropped, then the letters NFKD
     * does not decompose (đ, ł, ø, ß …) mapped by hand.
     */
    public static function foldForFilter(string $text): string
    {
        $text = self::compatibilityNormalize($text, decompose: true) ?: $text;
        $text = mb_strtolower($text, 'UTF-8');
        $text = (string) preg_replace('/\p{M}+/u', '', $text);

        return strtr($text, ['đ' => 'd', 'ł' => 'l', 'ø' => 'o', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ı' => 'i']);
    }

    /**
     * NFKC (or NFKD with $decompose); false for invalid UTF-8. Throws without
     * ext-intl: the compatibility / diacritic folding — and so the filter —
     * would otherwise weaken silently.
     */
    private static function compatibilityNormalize(string $text, bool $decompose): string|false
    {
        if (! class_exists(Normalizer::class)) {
            throw new RuntimeException('Pet names need the PHP intl extension (Normalizer).');
        }

        return Normalizer::normalize($text, $decompose ? Normalizer::FORM_KD : Normalizer::FORM_KC);
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
