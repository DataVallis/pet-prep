<?php

namespace App\Services;

use App\Enums\BreedType;

/**
 * "Za koga je primerna" (M5-R10): the sourced suitability tags of a breed
 * from config/breed_suitability.php — `suits` (the breed fits this family /
 * home) and `consider` (what a family must be ready for). Tag keys only; the
 * apps translate them. A breed without sourced tags gets empty lists.
 *
 * Only keys of the fixed vocabulary of the matching kind are returned (a
 * config typo never reaches the apps; BreedSuitabilityTest fails on it).
 */
class BreedSuitability
{
    public const SUITS = 'suits';

    public const CONSIDER = 'consider';

    /**
     * @return array{suits: list<string>, consider: list<string>}
     */
    public function for(BreedType $breed): array
    {
        $vocabulary = self::vocabulary();
        $entry = (array) config("breed_suitability.breeds.{$breed->value}", []);
        $out = [self::SUITS => [], self::CONSIDER => []];

        foreach ([self::SUITS, self::CONSIDER] as $kind) {
            foreach ((array) ($entry[$kind] ?? []) as $item) {
                $tag = is_array($item) ? ($item['tag'] ?? null) : null;
                if (is_string($tag) && ($vocabulary[$tag] ?? null) === $kind && ! in_array($tag, $out[$kind], true)) {
                    $out[$kind][] = $tag;
                }
            }
        }

        return $out;
    }

    /**
     * Every tag key → its kind (`suits` | `consider`).
     *
     * @return array<string, string>
     */
    public static function vocabulary(): array
    {
        return array_filter(
            (array) config('breed_suitability.vocabulary', []),
            fn (mixed $kind): bool => $kind === self::SUITS || $kind === self::CONSIDER,
        );
    }
}
