<?php

namespace App\Services\Media;

use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetPlan;
use App\Enums\PetStateEnum;
use App\Models\Pet;

/**
 * Which AI state videos a pet gets at birth (M4-03).
 *
 * A `challenge` pet gets the `full` set, a `free` pet the `basic` set
 * (idle + sleeping) — see M3-11 below. Sets live in
 * config('media.video_states').
 *
 * Behaviour videos (M5-R02, David 2026-10-06): the `full` set also has
 * `accident` and `chewing`. They are generated like every state video —
 * once per life stage from the stage's reference image, regenerated at a
 * stage transition — but only where the event can happen (Claude's cost
 * rule, waiting for David): `accident` for a pet with behaviour events
 * (`Pet::behaviourEventsEnabled`, PR #42) in the puppy stage, `chewing` for
 * any pet with behaviour events. Other pets never have these events, so
 * they never get these videos. Cats (M5-R06-07, CAT_SPEC §8): the same
 * six classic states with cat prompts, and `scratching` instead of
 * `accident` / `chewing` in the full set (any profiled cat — every cat
 * can scratch after a missed play). The free set is unchanged. A stored
 * behaviour video the pet is no longer entitled to (accident after
 * puppy → young) is not served (PetMediaService::mediaFor).
 *
 * This is the single place the decision is made, so payments (M3 —
 * RevenueCat entitlement) and AI media tokens (M4-09) can plug in here
 * without touching the pipeline. The reference image is always included.
 *
 * M3-11 P6 (David 2026-10-07, AI cost): the `full` set only for a pet
 * whose challenge was PAID BY A PURCHASE (`challenge_paid_source =
 * purchase`). Trial, payment_required, free-plan and grandfathered pets get
 * the `basic` set. Media a pet already has stays served (mediaFor serves
 * every stored classic state; behaviour videos while their event can
 * happen); this rule never deletes or regenerates anything by itself. A
 * purchase queues the missing full-set videos (ChallengeService::markPaid).
 * More media for other pets: AI-media tokens (M4-09, planned — also for the
 * free mutt).
 */
class MediaEntitlementService
{
    public const TIER_BASIC = 'basic';

    public const TIER_FULL = 'full';

    public function tierFor(Pet $pet): string
    {
        return $pet->plan === PetPlan::Challenge && $pet->challenge_paid_source === ChallengePaidSource::Purchase
            ? self::TIER_FULL
            : self::TIER_BASIC;
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
     * A behaviour video only where its event can happen (M5-R02, cats
     * M5-R06-07); every other state always applies.
     */
    public function behaviourApplies(Pet $pet, PetStateEnum $state): bool
    {
        // M5-R06-07: behaviour videos are per species — a cat never gets
        // `accident` / `chewing`, a dog never gets `scratching`.
        if (! $state->appliesTo($pet->speciesValue())) {
            return false;
        }

        return match ($state) {
            PetStateEnum::Accident => $pet->behaviourEventsEnabled() && $pet->life_stage === LifeStage::Puppy,
            PetStateEnum::Chewing => $pet->behaviourEventsEnabled(),
            // The cat's scratching (M5-R06-05) needs no behaviour opt-in: every
            // profiled cat can scratch after a missed play (ScratchingService::appliesOn).
            PetStateEnum::Scratching => ! $pet->isLegacyProfile(),
            default => true,
        };
    }
}
