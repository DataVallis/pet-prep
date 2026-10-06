<?php

namespace App\Enums;

/**
 * Features the parent's app build can show for a NEW pet, sent as
 * `features` with POST /api/parent/generate-pin (M5-R02, PR #42 review B1,
 * orchestrator decision 2026-10-06). Game rules that need UI an older app
 * build does not have are switched on per pet only when the creating app
 * declared support — so an old build never gets a dog whose events it
 * cannot resolve.
 */
enum ClientFeature: string
{
    /**
     * Puppy accidents + "Pelji ven", chewing + "Pospravi in daj igračo"
     * → `pets.behaviour_events_enabled`.
     */
    case BehaviourEvents = 'behaviour_events';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $f) => $f->value, self::cases());
    }
}
