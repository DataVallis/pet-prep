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
 *   (MediaEntitlementService::phaseClipsFor — state media version 3, both
 *   switches on) needs the PHASE clips of its game (`media.game_phase_groups`:
 *   play → ball (dog), wand → wand). `play` keeps its list above as well (QA m1:
 *   the cuddles share the stage and play the `playing` loop) — a cat's play has
 *   no phase group, so it keeps the list above only. Every other pet (v1, v2,
 *   free set, a switch off) keeps the list above — a switch off never leaves a
 *   game disabled for clips that will not come.
 * - Home first, games and school locked (David 2026-10-28): the school (`school`
 *   = every school phase clip, §3) is a START condition like the games — training
 *   start refuses 422 `media_not_ready` until all its clips are shown. A phase clip
 *   of the activity that FAILED (or a pose it is made from, or a flagged clip) keeps
 *   the activity locked — it is never playable without its own animations (this
 *   replaces the QA m1 fallback to the list above). No dead end: failed pose / phase
 *   slots are retried automatically (ActivityClipRetryService, hourly, capped) and a
 *   slot that runs out of retries is reported to the admin (Discord + AI media
 *   panel, {@see failedClips()}); the admin's Regenerate / »Approve anyway« unlocks it.
 *
 * Pure read; no personal data.
 */
class GameMediaReadiness
{
    /** The play endpoint: the dog's ball game and cuddles (dog + cat) share one stage. */
    public const PLAY = 'play';

    /** The cat's feather wand. */
    public const WAND = 'wand';

    /** M5-T09: the school (since 2026-10-28 a start condition of training, like the games). */
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
        $required = $this->requiredClips($pet, $game, $slots);
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
            // Quality check (David 2026-10-23): a flagged clip is not shown, so it does not count.
            ->filter(fn (PetMedia $m) => ($m->isVideo() || $m->isPhase()) && $m->isShown())
            ->map(fn (PetMedia $m) => (string) $m->state)
            ->all();

        return array_values(array_diff($required, $stored));
    }

    /**
     * The clips $game shows for this pet: the configured list ∩ the pet's entitled states —
     * for a pet with the game's phase clips those phase clips (+ the list for `play`).
     *
     * @param  Collection<int, PetMedia>|null  $slots  the pet's media rows when already loaded
     * @return list<string>
     */
    public function requiredClips(Pet $pet, string $game, ?Collection $slots = null): array
    {
        $entitled = array_map(fn (PetStateEnum $s) => $s->value, $this->entitlements->videoStatesFor($pet));
        $classic = array_values(array_unique(array_intersect(array_map('strval', (array) config("media.game_clips.{$game}", [])), $entitled)));

        // M5-T10: the game's phase clips for a pet that gets them (v3, both switches on).
        $clips = $this->phaseClipsOf($pet, $game);

        if ($clips === []) {
            return $game === self::SCHOOL ? [] : $classic;
        }

        // David 2026-10-28: a failed phase clip / pose no longer falls back to the list above —
        // the activity stays locked (missing() reports the clip) until it is made.
        $phases = array_map(fn (PetPhaseClip $c) => $c->value, $clips);

        return match ($game) {
            self::SCHOOL, self::WAND => $phases,
            // QA m1: the cuddles share the play stage and play the `playing` loop.
            default => array_values(array_unique([...$phases, ...$classic])),
        };
    }

    /**
     * The activities a pet with phase clips has (school / play / wand), each with what
     * keeps it locked now — for the admin panel and the retry service. Empty for a pet
     * without phase clips.
     *
     * @param  Collection<int, PetMedia>|null  $slots
     * @return array<string, array{ready: bool, missing: list<string>, failed: list<string>}>
     */
    public function activities(Pet $pet, ?Collection $slots = null): array
    {
        $slots ??= $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();
        $out = [];
        // QA #434 M4a: without a stored reference image no pose / phase clip can be made.
        $image = $slots->first(fn (PetMedia $m) => $m->isImage());
        $imageFailed = $image !== null && $image->status === PetMedia::STATUS_FAILED && ! $image->isServable();

        foreach ([self::SCHOOL, self::PLAY, self::WAND] as $game) {
            $clips = $this->phaseClipsOf($pet, $game);
            if ($clips === []) {
                continue;
            }
            $missing = $this->missing($pet, $game, $slots);
            $failed = $this->failedClips($pet, $clips, $slots);
            $out[$game] = ['ready' => $missing === [], 'missing' => $missing, 'failed' => $missing !== [] && $imageFailed ? ['reference', ...$failed] : $failed];
        }

        return $out;
    }

    /**
     * The phase clips of $game the pet gets (empty without phase clips / for a group it
     * cannot use).
     *
     * @return list<PetPhaseClip>
     */
    public function phaseClipsOf(Pet $pet, string $game): array
    {
        $groups = array_map('strval', (array) config("media.game_phase_groups.{$game}", []));

        return array_values(array_filter(
            $this->entitlements->phaseClipsFor($pet),
            fn (PetPhaseClip $c) => in_array($c->group(), $groups, true),
        ));
    }

    /**
     * The phase clips / poses of these clips that FAILED without a shown older file, or
     * are flagged by the quality check (values: phase clip / pose names).
     *
     * @param  list<PetPhaseClip>  $clips
     * @param  Collection<int, PetMedia>|null  $slots
     * @return list<string>
     */
    public function failedClips(Pet $pet, array $clips, ?Collection $slots = null): array
    {
        $slots ??= $pet->relationLoaded('media') ? $pet->getRelation('media') : $pet->media()->get();
        $values = array_map(fn (PetPhaseClip $c) => $c->value, $clips);
        $poses = [];
        foreach ($clips as $clip) {
            foreach ([$clip->startPose(), $clip->endPose()] as $pose) {
                if ($pose !== null) {
                    $poses[] = $pose->value;
                }
            }
        }

        return $slots
            ->filter(fn (PetMedia $m) => (($m->status === PetMedia::STATUS_FAILED && ! $m->isServable()) || $m->quality === PetMedia::QUALITY_FLAGGED)
                && (($m->isPhase() && in_array($m->state, $values, true)) || ($m->isPose() && in_array($m->state, $poses, true))))
            ->map(fn (PetMedia $m) => (string) $m->state)
            ->unique()
            ->values()
            ->all();
    }
}
