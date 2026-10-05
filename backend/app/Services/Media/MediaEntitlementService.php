<?php

namespace App\Services\Media;

use App\Enums\PetStateEnum;
use App\Models\Pet;

/**
 * Which AI state videos a pet gets at birth (M4-03).
 *
 * Today (Claude's proposal 2026-10-05, waiting for David): a breed with
 * `breed_configs.premium_unlock` (Border Collie — the paid challenge) gets the
 * `full` set (all six states); every other pet (the free mutt) the `basic`
 * set (idle + sleeping). Sets live in config('media.video_states').
 *
 * This is the single place the decision is made, so payments (M3 —
 * RevenueCat entitlement) and AI media tokens (M4-09) can plug in here
 * without touching the pipeline. The reference image is always included.
 */
class MediaEntitlementService
{
    public const TIER_BASIC = 'basic';

    public const TIER_FULL = 'full';

    public function tierFor(Pet $pet): string
    {
        return $pet->breedConfig()?->premium_unlock ? self::TIER_FULL : self::TIER_BASIC;
    }

    /**
     * Ordered state list for the pet; `idle` always first (the fallback video).
     *
     * @return list<PetStateEnum>
     */
    public function videoStatesFor(Pet $pet): array
    {
        return self::statesOfTier($this->tierFor($pet));
    }

    /**
     * @return list<PetStateEnum>
     */
    public static function statesOfTier(string $tier): array
    {
        $states = [PetStateEnum::Idle];

        foreach ((array) config("media.video_states.{$tier}", []) as $value) {
            $state = PetStateEnum::tryFrom((string) $value);

            if ($state !== null && ! in_array($state, $states, true)) {
                $states[] = $state;
            }
        }

        return $states;
    }
}
