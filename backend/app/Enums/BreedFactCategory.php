<?php

namespace App\Enums;

/**
 * Category of a breed fact (M5-R09, DID_YOU_KNOW_SPEC §5). Mirrored by
 * `breed_facts_category_check`. Family and health facts are for parents only
 * (`breed_facts_parent_only_check`, David 2026-10-09 §0.4).
 */
enum BreedFactCategory: string
{
    case Origin = 'origin';
    case Body = 'body';
    case Temperament = 'temperament';
    case Exercise = 'exercise';
    case Diet = 'diet';
    case Grooming = 'grooming';
    case Training = 'training';
    case Family = 'family';
    case Health = 'health';

    /** Shown to parents only (never in the child's app). */
    public function isParentOnly(): bool
    {
        return $this === self::Family || $this === self::Health;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
