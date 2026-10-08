<?php

namespace App\Enums;

/**
 * What the child did with the dog (M5-R05, David 2026-10-07): a short ball
 * game or a cuddle. Mirrored by the pet_play_events_kind_check constraint.
 */
enum PlayKind: string
{
    /** Ball game: three throws, the dog fetches. */
    case Play = 'play';

    /** Cuddles: stroking the dog with a finger. */
    case Cuddle = 'cuddle';

    /** The parent timeline row this kind writes. */
    public function activityType(): ActivityType
    {
        return match ($this) {
            self::Play => ActivityType::PlayedWithPet,
            self::Cuddle => ActivityType::CuddledPet,
        };
    }
}
