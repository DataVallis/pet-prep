<?php

namespace App\Services\Media;

use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Models\Pet;
use App\Services\AppSettingsService;

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
 *
 * M4-10: the tier decides WHICH states a pet gets, never WHERE they come
 * from — a free pool pet's media are its shared look's (PetMediaService,
 * "Shared looks"), generated once per look and reused.
 *
 * M5-T08 (APP_REFINEMENT_SPEC §7, David 2026-10-20 / 21) — state media:
 * a pet that {@see usesStateMedia()} also gets a KEY IMAGE per entitled
 * state except idle ({@see keyframeStatesFor()}), its state videos are
 * loops from / to that key image, and it gets the TRANSITION clips of
 * config media.state_media.transitions whose two states it is entitled to
 * ({@see transitionsFor()}). Only the paid full set (the 49,99 € challenge
 * pays for it, §14); the free basic set (idle + sleeping) keeps its pre-M5-T08
 * media (confirmed by David 2026-10-21: no sleeping key image for free pets for now). Only pets with
 * `state_media_version = 2` — every pet that existed before M5-T08 keeps
 * its set (no automatic backfill, David 2026-10-21); a new purchase and the
 * admin "Upgrade to state media" move a pet to version 2 (PetMediaService).
 * M5-T08b: the whole feature is the superadmin switch {@see stateMediaEnabled()}.
 *
 * M5-T09 / M5-T10 (APP_REFINEMENT_SPEC §3 / §10, David 2026-10-21): a pet that
 * uses state media also gets the PHASE clips of the school (per command and
 * phase — only with training on) and of its game (dog: ball, cat: wand) —
 * {@see phaseClipsFor()} — and the POSE images those clips start / end in
 * ({@see posesFor()}). Same rule as the key images: the paid full set, version 3,
 * the state media switch on — plus their own superadmin switch »School & game
 * clips« ({@see phaseClipsEnabled()}, QA M2); existing pets get nothing automatically
 * (a v2 pet only on a purchase or the admin upgrade, David 2026-10-21).
 */
class MediaEntitlementService
{
    public function __construct(private readonly AppSettingsService $settings) {}

    /**
     * M5-T08b: the superadmin switch (/admin → Feature switches, `app_settings.state_media`,
     * default on). Off = no pet uses state media and nothing already queued is generated.
     */
    public function stateMediaEnabled(bool $fresh = false): bool
    {
        return $this->settings->stateMedia($fresh);
    }

    /**
     * M5-T09 / T10 (QA M2): the superadmin switch »School & game clips« (`app_settings.phase_clips`,
     * default on). Off = no pose image / phase clip is generated (nothing already queued either)
     * and readiness / the app payload use the M5-T08 rules.
     */
    public function phaseClipsEnabled(bool $fresh = false): bool
    {
        return $this->settings->phaseClips($fresh);
    }

    /**
     * M5-T08b (QA m3): does the pet HAVE the state media set (version 2, profiled, full
     * set) — independent of the switch and the key image profile. The app payload uses
     * this, so turning the switch off never changes how already stored media are shown.
     */
    public function hasStateMediaSet(Pet $pet): bool
    {
        return (int) ($pet->state_media_version ?? 1) >= self::STATE_MEDIA_VERSION
            && ! $pet->isLegacyProfile()
            && $this->tierFor($pet) === self::TIER_FULL;
    }

    /** M5-T08: key images, loops, transitions from this media version. */
    public const STATE_MEDIA_VERSION = 2;

    /**
     * M5-T09 / T10: + school and game phase clips from this media version. A pet on
     * version 2 (made between the M5-T08 and M5-T09 releases) keeps its set — no
     * automatic backfill; a purchase or the admin »Upgrade to state media« moves it to 3.
     */
    public const PHASE_CLIPS_VERSION = 3;

    /** The version a new profiled pet / a purchase / the admin upgrade gives a pet now. */
    public const CURRENT_MEDIA_VERSION = self::PHASE_CLIPS_VERSION;

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
     * M5-T08: key images, loops and transitions for this pet — the full
     * (purchased) set, a profiled pet (stage images), media version 2, the
     * feature on and a key image profile available.
     */
    public function usesStateMedia(Pet $pet): bool
    {
        return $this->stateMediaEnabled()
            && $this->hasStateMediaSet($pet)
            && app(MediaProfiles::class)->keyframe() !== null;
    }

    /**
     * M5-T08: the states that get a key image — every entitled video state but
     * idle (the reference image IS the idle pose). [] without state media.
     *
     * @return list<PetStateEnum>
     */
    public function keyframeStatesFor(Pet $pet): array
    {
        if (! $this->usesStateMedia($pet)) {
            return [];
        }

        return array_values(array_filter(
            $this->videoStatesFor($pet),
            fn (PetStateEnum $state): bool => $state !== PetStateEnum::Idle,
        ));
    }

    /**
     * M5-T08: the transition clips of the pet — configured, both ends entitled. [] without state media.
     *
     * @return list<PetTransition>
     */
    public function transitionsFor(Pet $pet): array
    {
        if (! $this->usesStateMedia($pet)) {
            return [];
        }

        $states = $this->videoStatesFor($pet);
        $out = [];

        foreach ((array) config('media.state_media.transitions', []) as $value) {
            $transition = PetTransition::tryFrom((string) $value);

            if ($transition !== null && ! in_array($transition, $out, true)
                && in_array($transition->fromState(), $states, true) && in_array($transition->toState(), $states, true)) {
                $out[] = $transition;
            }
        }

        return $out;
    }

    /**
     * M5-T09 / T10: the phase clips of the pet in queue order (config
     * media.state_media.phase_clips: the games, then the school — QA m1). A group only where the
     * pet can use it: school = training on (dogs), ball = a dog, wand = a cat.
     * [] without state media or with the »School & game clips« switch off (QA M2).
     * The single source of the generation, preparation, game readiness and the app
     * payload's `phase_groups` (QA m2), so server and app always agree.
     *
     * @return list<PetPhaseClip>
     */
    public function phaseClipsFor(Pet $pet): array
    {
        // usesStateMedia first: a pet without state media never reads the phase switch (no query).
        if (! $this->usesStateMedia($pet) || ! $this->phaseClipsEnabled()) {
            return [];
        }

        return $this->phaseClipsOfSet($pet);
    }

    /**
     * The phase clips the pet's state media set has (version 3, profiled, full set, its
     * species / training), independent of the switches — the admin panel's view.
     *
     * @return list<PetPhaseClip>
     */
    public function phaseClipsOfSet(Pet $pet): array
    {
        if (! $this->hasStateMediaSet($pet) || (int) $pet->state_media_version < self::PHASE_CLIPS_VERSION) {
            return [];
        }

        $species = $pet->speciesValue();
        $out = [];

        foreach ((array) config('media.state_media.phase_clips', []) as $group) {
            $usable = match ((string) $group) {
                PetPhaseClip::GROUP_SCHOOL => $pet->trainingEnabled(),
                PetPhaseClip::GROUP_BALL => $pet->isDog(),
                PetPhaseClip::GROUP_WAND => $pet->isCat(),
                default => false,
            };

            foreach ($usable ? PetPhaseClip::ofGroup((string) $group) : [] as $clip) {
                if ($clip->species() === $species && ! in_array($clip, $out, true)) {
                    $out[] = $clip;
                }
            }
        }

        return $out;
    }

    /**
     * M5-T09 / T10: the pose images the pet's phase clips start / end in, in the
     * order the clips need them. [] without state media.
     *
     * @return list<PetPose>
     */
    public function posesFor(Pet $pet): array
    {
        $out = [];

        foreach ($this->phaseClipsFor($pet) as $clip) {
            foreach ([$clip->startPose(), $clip->endPose()] as $pose) {
                if ($pose !== null && ! in_array($pose, $out, true)) {
                    $out[] = $pose;
                }
            }
        }

        return $out;
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
