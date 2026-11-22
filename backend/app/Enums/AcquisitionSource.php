<?php

namespace App\Enums;

/**
 * »Kje ste izvedeli za PetPrep?« (VALIDATION_PLAN §4.1, David 2026-11-21 D2) — the parent's answer
 * at sign-up (`POST /api/register`, `POST /api/auth/social` when it creates the account) or once
 * later (`PATCH /api/parent/settings`). Stored in `users.acquisition_source`, mirrored by the
 * users_acquisition_source_check constraint. Parents only; null = not answered (old apps, skipped,
 * or an unknown value — an invalid answer never blocks a sign-up).
 */
enum AcquisitionSource: string
{
    case FriendFamily = 'friend_family';
    case Instagram = 'instagram';
    case Tiktok = 'tiktok';
    case Facebook = 'facebook';
    case Youtube = 'youtube';
    case SchoolKindergarten = 'school_kindergarten';
    case Search = 'search';
    case Website = 'website';
    case Ad = 'ad';
    case Other = 'other';

    /** `acquisition_detail` is kept only for these (free text / campaign code); dropped otherwise. */
    public function keepsDetail(): bool
    {
        return $this === self::Other || $this === self::Ad;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
