<?php

namespace App\Services\Media;

use App\Enums\PetPhaseClip;
use App\Enums\PetStateEnum;
use App\Models\Pet;
use App\Models\PetMedia;
use Illuminate\Support\Collection;

/**
 * Is a mini-game's media ready for this pet? (M5-T04, David 2026-10-20,
 * APP_REFINEMENT_SPEC §10 short term + §13): if ANY clip a game needs is
 * missing, the game is disabled (»Igra se še pripravlja«) — never half
 * working. The server is the truth: PlayPayload / WandPayload report it and
 * the start endpoints refuse with 422 `media_not_ready`.
 *
 * - The clips of a game: `config('media.game_clips.<game>')` ∩ the states the
 *   pet is entitled to (MediaEntitlementService — a basic-tier pet has no
 *   `playing` clip, so its game needs only `idle`; a full-tier pet needs both).
 * - A clip counts when the pet's own slot of that state has a stored file
 *   (`isServable`) — the same set `PetMediaService::mediaFor` serves. A shared
 *   look's file (M4-10 pool, M5-R21 breed + colour) is linked into the pet's
 *   slot, so the pet's EFFECTIVE media set is what is checked. An older
 *   generation's file still being served while a new one runs (life-stage
 *   change, regenerate) counts — the game keeps working.
 * - A pet that gets no AI media at all (no reference image and
 *   `media_status = disabled`: AI media off, a breed without appearance data)
 *   never will: its games use the drawn stage and are always ready.
 *
 * - M5-T10 (APP_REFINEMENT_SPEC §10 long term): a pet that gets phase clips
 *   (MediaEntitlementService::phaseClipsFor — state media version 2, the switch
 *   on) needs the PHASE clips of its game instead (`media.game_phase_groups`:
 *   play → ball (dog; a cat's play is cuddles only → the old list), wand → wand).
 *   Every other pet (v1, free set, switch off) keeps the list above — the
 *   switch off never leaves a game disabled for clips that will not come.
 * - M5-T09: `school` = every school phase clip of the pet (§3: school readiness
 *   = all its clips). Not a start condition — practice works without clips; the
 *   app shows the pose and »Šola se še pripravlja« while preparation runs.
 *
 * Pure read; no personal data.
 */
class GameMediaReadiness
{
    /** The play endpoint: the dog's ball game and cuddles (dog + cat) share one stage. */
    public const PLAY = 'play';

    /** The cat's feather wand. */
    public const WAND = 'wand';

    /** M5-T09: the school (informational — never blocks a session). */
    public const SCHOOL = 'school';

    public function __construct(
        private readonly MediaEntitlementService $entitlements,
    ) {}

    /**
     * Every clip of $game the pet is entitled to is stored (or the pet gets no AI media).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     */
    public function ready(Pet $pet, string $game, ?Collection $slots = null): bool
    {
        return $this->missing($pet, $game, $slots) === [];
    }

    /**
     * The clips of $game the pet is entitled to but does not have yet
     * (empty = ready). Used by the admin / logs and the tests.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return list<string>
     */
    public function missing(Pet $pet, string $game, ?Collection $slots = null): array
    {
        $required = $this->requiredClips($pet, $game);
        if ($required === []) {
            return [];
        }

        $slots ??= $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();

        $image = $slots->first(fn (PetMedia $m) => $m->isImage());
        // M5-T06: not "no image row" — the birth (queueStateVideos) creates a pending
        // image slot even for a pet that never gets media; `media_status` stays `disabled`.
        if ($pet->media_status === 'disabled' && ! ($image?->isServable() ?? false)) {
            return [];
        }

        $stored = $slots
            ->filter(fn (PetMedia $m) => ($m->isVideo() || $m->isPhase()) && $m->isServable())
            ->map(fn (PetMedia $m) => (string) $m->state)
            ->all();

        return array_values(array_diff($required, $stored));
    }

    /**
     * The clips $game shows for this pet: the configured list ∩ the pet's entitled states.
     *
     * @return list<string>
     */
    public function requiredClips(Pet $pet, string $game): array
    {
        // M5-T10: the game's phase clips for a pet that gets them (v2, switch on).
        $groups = array_map('strval', (array) config("media.game_phase_groups.{$game}", []));
        $phases = array_values(array_map(fn (PetPhaseClip $c) => $c->value, array_filter(
            $this->entitlements->phaseClipsFor($pet),
            fn (PetPhaseClip $c) => in_array($c->group(), $groups, true),
        )));

        if ($phases !== [] || $game === self::SCHOOL) {
            return $phases;
        }

        $entitled = array_map(fn (PetStateEnum $s) => $s->value, $this->entitlements->videoStatesFor($pet));
        $clips = array_map('strval', (array) config("media.game_clips.{$game}", []));

        return array_values(array_unique(array_intersect($clips, $entitled)));
    }
}
