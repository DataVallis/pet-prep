<?php

namespace App\Enums;

/**
 * Review state of a breed fact (DID_YOU_KNOW_SPEC §5): written (draft) →
 * checked against its quote by an independent agent (verified) → approved by
 * David in the admin. Only `approved` reaches the apps. Mirrored by
 * `breed_facts_status_check`.
 */
enum BreedFactStatus: string
{
    case Draft = 'draft';
    case Verified = 'verified';
    case Approved = 'approved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
