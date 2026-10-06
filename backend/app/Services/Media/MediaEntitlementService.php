<?php

namespace App\Services\Media;

use App\Enums\LifeStage;
use App\Enums\PetStateEnum;
use App\Models\Pet;

/**
 * Which AI state videos a pet gets at birth (M4-03).
 *
 * Today (Claude's proposal 2026-10-05, waiting for David): a breed with
 * `breed_configs.premium_unlock` (Border Collie — the paid challenge) gets the
 * `full` set; every other pet (the free mutt) the `basic` set (idle +
 * sleeping). Sets live in config('media.video_states').
 *
 * Behaviour videos (M5-R02, David 2026-10-06): the `full` set also has
 * `accident` and `chewing`. They are generated like every state video —
 * once per life stage from the stage's reference image, regenerated at a
 * stage transition — but only where the event can happen (Claude's cost
 * rule, waiting for David): `accident` for a pet with behaviour events
 * (`Pet::behaviourEventsEnabled`, PR #42) in the puppy stage, `chewing` for
 * any pet with behaviour events. Other pets never have these events, so
 * they never get these videos. The free set is unchanged. A stored
 * behaviour video the pet is no longer entitled to (accident after
 * puppy → young) is not served (PetMediaService::mediaFor).
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
        return array_values(array_filter(
            self::statesOfTier($this->tierFor($pet)),
            fn (PetStateEnum $state): bool => $this->behaviourApplies($pet, $state),
        ));
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

    /**
     * A behaviour video only where its event can happen (M5-R02); every
     * other state always applies.
     */
    private function behaviourApplies(Pet $pet, PetStateEnum $state): bool
    {
        return match ($state) {
            PetStateEnum::Accident => $pet->behaviourEventsEnabled() && $pet->life_stage === LifeStage::Puppy,
            PetStateEnum::Chewing => $pet->behaviourEventsEnabled(),
            default => true,
        };
    }
}
