<?php

namespace App\Services\Media;

use App\Enums\BreedType;
use App\Enums\ChallengePaidSource;
use App\Enums\LifeStage;
use App\Enums\PetPhaseClip;
use App\Enums\PetPlan;
use App\Enums\PetPose;
use App\Enums\PetStateEnum;
use App\Enums\PetTransition;
use App\Enums\Species;
use App\Models\Pet;
use App\Models\PetMedia;
use App\Models\PetMediaDerivative;
use App\Services\AppSettingsService;
use Illuminate\Support\Collection;

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
 *
 * Frugal clips (David 2026-10-30, AI cost, superadmin switch `frugal_clips`, default OFF —
 * {@see frugalClipsEnabled()}): the SETS above never change ({@see phaseClipsFor()} and
 * {@see transitionsFor()} stay the one source of generation, readiness, preparation and the
 * payload's `phase_groups`); what changes is where an item's MEDIA come from. A frugal
 * TARGET (come / place / potty attention → {@see FRUGAL_PHASE_SOURCES}) without media of its
 * own is served from its source ({@see phaseSourceOf()}) and is not generated
 * ({@see phaseClipsToGenerateFor()}, {@see leftToSource()}). A target that has its own file
 * (or is being made) always keeps it — existing pets / looks are served exactly as before.
 * The low-motion loops ({@see shortLoop()}) are generated 3 s and served as their ffmpeg
 * ping-pong.
 *
 * The reversed transition is GONE (David 2026-10-30: »looks like rewinding a tape«):
 * `sleeping_to_idle` is generated by fal again, switch on or off. Only slots marked before
 * that ({@see LEGACY_REVERSED_TRANSITIONS}, `pet_media.frugal_source = idle_to_sleeping`) are
 * still served reversed ({@see transitionSourceOf()}) until their own clip is stored —
 * `media:frugal-unreverse` (FrugalUnreverseService) queues those clips.
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

    /** QA #451 m5: the fresh switch read, memoised for this scoped instance (one job / request). */
    private ?bool $frugalFresh = null;

    /**
     * Frugal clips (David 2026-10-30): the superadmin switch (/admin → Feature switches,
     * `app_settings.frugal_clips`, default OFF). On = NEW DOG media are made the frugal way
     * (class doc); off = exactly the pre-2026-10-30 generation. Read it `fresh` at every fal
     * entry point (like {@see stateMediaEnabled()}); the fresh read is memoised per job /
     * request (this service is bound `scoped`), so a run reads the row once.
     */
    public function frugalClipsEnabled(bool $fresh = false): bool
    {
        if ($fresh) {
            return $this->frugalFresh ??= $this->settings->frugalClips(fresh: true);
        }

        return $this->frugalFresh ?? $this->settings->frugalClips();
    }

    /** Forget the memoised fresh read (tests, the Feature switches save). */
    public function forgetFrugalSwitch(): void
    {
        $this->frugalFresh = null;
    }

    /** QA #451 major 1 (David: dogs only): the switch applies to dogs, never to cats. */
    public function frugalFor(Species $species, bool $fresh = false): bool
    {
        return $species === Species::Dog && $this->frugalClipsEnabled($fresh);
    }

    /**
     * Frugal clips A: these school attention clips are served from the sit attention clip
     * (PetPhaseClip::motion() gives all four the same prompt, the same start frame — the
     * reference image — and no end frame).
     *
     * @var array<string, string> target phase clip => source phase clip
     */
    public const FRUGAL_PHASE_SOURCES = [
        'school_come_attention' => 'school_sit_attention',
        'school_place_attention' => 'school_sit_attention',
        'school_potty_attention' => 'school_sit_attention',
    ];

    /**
     * LEGACY (frugal B, 2026-10-30 until it was dropped the same day — David: »looks like
     * rewinding a tape«): slots marked `frugal_source` with the source transition were served
     * as that transition reversed with ffmpeg. NOTHING new is reversed any more — this map only
     * keeps those marked slots served until their own fal clip is stored
     * (`media:frugal-unreverse`, FrugalUnreverseService), and lets the reversed derivative be
     * cleaned up afterwards. Never a generation rule.
     *
     * @var array<string, string> target transition => source transition
     */
    public const LEGACY_REVERSED_TRANSITIONS = [
        'sleeping_to_idle' => 'idle_to_sleeping',
    ];

    /** A frugal target (only the attention phase clips since the reversal was dropped). */
    public static function isFrugalTarget(string $kind, string $state): bool
    {
        return $kind === PetMedia::KIND_PHASE && isset(self::FRUGAL_PHASE_SOURCES[$state]);
    }

    /** The source state of a frugal target (null for every other clip). */
    public static function frugalSourceOf(string $kind, string $state): ?string
    {
        return $kind === PetMedia::KIND_PHASE ? (self::FRUGAL_PHASE_SOURCES[$state] ?? null) : null;
    }

    /**
     * QA #451 B1: the generation of the pet's CURRENT reference image (null = none stored) — a
     * clip counts as the pet's own only when it was made from it (a previous life stage's file
     * is stale, exactly like poseImage() / frameImage()).
     *
     * @param  Collection<int, PetMedia>  $slots
     */
    public static function imageGeneration(Collection $slots): ?int
    {
        $image = $slots->first(fn (PetMedia $m) => $m->isImage());

        return $image !== null && $image->isServable() ? (int) $image->generation : null;
    }

    /** A stored file made from the current reference image generation (`null` = no image known). */
    public static function isCurrentClip(?PetMedia $own, ?int $imageGeneration): bool
    {
        return $own !== null && $own->isServable()
            && ($imageGeneration === null || (int) $own->source_generation >= $imageGeneration);
    }

    /**
     * SERVING side (payload, readiness, preparation): is a frugal target of this pet shown from
     * its source? When it has no CURRENT clip of its own (QA #451 B1: a previous stage's file does
     * not count) and either the switch is on (dogs) or the slot is marked `pet_media.frugal_source`
     * — a pet made with the switch on keeps the source after a switch-off until its next
     * generation (David 2026-10-30). A pet never touched by the switch is judged as before.
     *
     * @param  Collection<int, PetMedia>  $slots  the pet's media rows (with its image row)
     */
    public function servesFromSource(Pet $pet, ?PetMedia $own, Collection $slots): bool
    {
        if (self::isCurrentClip($own, self::imageGeneration($slots))) {
            return false;
        }

        return $own?->frugal_source !== null || $this->frugalFor($pet->speciesValue());
    }

    /**
     * The phase clip whose media show $clip for a pet with these slots: $clip itself, or (a
     * frugal target served from its source) the sit attention clip.
     *
     * @param  Collection<int, PetMedia>  $slots  the pet's media rows (with its image row)
     */
    public function phaseSourceOf(Pet $pet, PetPhaseClip $clip, Collection $slots): PetPhaseClip
    {
        $source = self::FRUGAL_PHASE_SOURCES[$clip->value] ?? null;

        if ($source === null) {
            return $clip;
        }

        $own = $slots->first(fn (PetMedia $m) => $m->isPhase() && $m->state === $clip->value);

        return $this->servesFromSource($pet, $own, $slots) ? PetPhaseClip::from($source) : $clip;
    }

    /**
     * LEGACY reversal: the transition a slot marked before the reversal was dropped is still
     * served from (reversed), or null. Only the marker counts — never the switch: a new pet /
     * look gets its own fal clip (David 2026-10-30). Served reversed until its own CURRENT clip
     * is stored (markStored / linkToLook clear the marker), so a queued remake never leaves a gap.
     *
     * @param  Collection<int, PetMedia>  $slots
     */
    public function transitionSourceOf(Pet $pet, PetTransition $transition, Collection $slots): ?PetTransition
    {
        $source = self::LEGACY_REVERSED_TRANSITIONS[$transition->value] ?? null;

        if ($source === null) {
            return null;
        }

        $own = $slots->first(fn (PetMedia $m) => $m->isTransition() && $m->state === $transition->value);

        return $own?->frugal_source !== null && ! self::isCurrentClip($own, self::imageGeneration($slots))
            ? PetTransition::from($source) : null;
    }

    /**
     * {@see phaseClipsFor()} without the frugal targets left to their source — what is
     * GENERATED (queueStateVideos, planMissing / costs, the submit guards).
     * `$imageGeneration` overrides the current one (a planned stage remake: every stored clip
     * becomes stale — QA #451 B1).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     * @return list<PetPhaseClip>
     */
    public function phaseClipsToGenerateFor(Pet $pet, ?Collection $slots = null, bool $fresh = false, ?int $imageGeneration = null): array
    {
        $clips = $this->phaseClipsFor($pet);

        if ($clips === [] || ! $this->frugalFor($pet->speciesValue(), $fresh)) {
            return $clips;
        }

        $slots ??= $pet->media()->get();

        return array_values(array_filter($clips, fn (PetPhaseClip $c) => ! $this->leftToSource($pet, PetMedia::KIND_PHASE, $c->value, $slots, $fresh, $imageGeneration)));
    }

    /**
     * What is GENERATED of {@see transitionsFor()}: all of them (the frugal reversal was dropped
     * — David 2026-10-30; sleeping_to_idle is made by fal again). Kept as the one generation
     * entry point next to {@see phaseClipsToGenerateFor()}.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return list<PetTransition>
     */
    public function transitionsToGenerateFor(Pet $pet, ?Collection $slots = null, bool $fresh = false, ?int $imageGeneration = null): array
    {
        return $this->transitionsFor($pet);
    }

    /**
     * GENERATION side for one target of a pet: not generated now? The switch on (dogs), and:
     *  - a shared-look pet (QA #451 B1): the look has no such clip at the pet's CURRENT stage
     *    (a file or a request in progress — linking it costs 0 $); the pet's own (older) row
     *    does not matter;
     *  - a pet with its own media: no current clip and no paid request in progress.
     * Only the attention phase clips are frugal targets ({@see isFrugalTarget()}).
     *
     * @param  Collection<int, PetMedia>  $slots  the pet's media rows (with its image row)
     */
    public function leftToSource(Pet $pet, string $kind, string $state, Collection $slots, bool $fresh = false, ?int $imageGeneration = null): bool
    {
        if (! self::isFrugalTarget($kind, $state) || ! $this->frugalFor($pet->speciesValue(), $fresh)) {
            return false;
        }

        if ($pet->usesLookPool()) {
            return ! $this->lookHasIt($pet, $kind, $state);
        }

        $own = $slots->first(fn (PetMedia $m) => $m->kind === $kind && $m->state === $state);

        return ! self::isCurrentClip($own, $imageGeneration ?? self::imageGeneration($slots))
            && ! ($own?->status === PetMedia::STATUS_RUNNING && $own->request_id !== null);
    }

    /**
     * Is this slot (a pet's or a look's phase clip / transition) a frugal target that is not
     * generated now? The submit guards (PetMediaService::wantsPoseMedia / submitLookVideo), the
     * retries and the admin panel.
     */
    public function slotLeftToSource(PetMedia $slot, bool $fresh = false): bool
    {
        if (! self::isFrugalTarget((string) $slot->kind, (string) $slot->state)) {
            return false;
        }

        if ($slot->isLookMedia()) {
            $look = $slot->look;
            if ($look === null || ! $this->frugalFor($look->breed_type->species(), $fresh)) {
                return false;
            }

            return ! $slot->isServable() && $slot->status !== PetMedia::STATUS_RUNNING;
        }

        $pet = $slot->pet;

        return $pet !== null && $this->leftToSource($pet, (string) $slot->kind, (string) $slot->state, $pet->media()->get(), $fresh);
    }

    /** The owner row's derived clip `$key` of the source's current file failed MAX_ATTEMPTS times. */
    public static function derivativeFailedFor(PetMedia $source, string $key): bool
    {
        return PetMediaDerivative::query()
            ->where('pet_media_id', (int) ($source->look_media_id ?? $source->id))
            ->where('key', $key)
            ->where('status', PetMediaDerivative::STATUS_FAILED)
            ->where('source_path', (string) $source->storage_path)
            ->where('attempts', '>=', SchoolClipDerivativeService::MAX_ATTEMPTS)
            ->exists();
    }

    /**
     * A shared look (pool / breed + colour) that has the target at the pet's stage (a file or a
     * request in progress): linking it costs 0 $, so the pet keeps it like any look clip.
     */
    private function lookHasIt(Pet $pet, string $kind, string $state): bool
    {
        if ($pet->life_stage === null) {
            return false;
        }

        return PetMedia::query()->where('pet_look_id', $pet->pet_look_id)->where('kind', $kind)->where('state', $state)
            ->where('life_stage', $pet->life_stage->value)
            ->where(fn ($q) => $q->whereNotNull('storage_path')->orWhere('status', PetMedia::STATUS_RUNNING))
            ->exists();
    }

    /**
     * Frugal clips C: is a NEW loop of this state generated short (`clip_seconds.state_short`,
     * 3 s) and served as a ping-pong? The switch on, a dog, a low-motion state — and (QA #451 m2)
     * not for a row whose current short loop's ping-pong failed for good (then a normal 5 s loop
     * is made). `$row` = the row being submitted (a pet's own row or a look row).
     */
    public function shortLoop(Species $species, PetStateEnum $state, ?PetMedia $row = null, bool $fresh = false): bool
    {
        if (! in_array($state->value, self::shortLoopStates(), true) || ! $this->frugalFor($species, $fresh)) {
            return false;
        }

        return $row === null || ! $row->isServable() || ! self::derivativeFailedFor($row, $state->value.'_'.SchoolClipDerivativeService::SUFFIX_LOOP);
    }

    /** @return list<string> the low-motion states whose frugal loops are 3 s + ping-pong */
    public static function shortLoopStates(): array
    {
        return array_values(array_map('strval', (array) config('media.state_media.frugal.short_loop_states', [])));
    }

    /** @return list<string> the dog breed values (SQL filters of the frugal rules) */
    public static function dogBreedValues(): array
    {
        return array_values(array_map(fn (BreedType $b) => $b->value, array_filter(BreedType::cases(), fn (BreedType $b) => $b->species() === Species::Dog)));
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
