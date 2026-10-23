/**
 * The state media player's decisions (M5-T08, APP_REFINEMENT_SPEC §0 + §7) — pure, no
 * player. `PetMediaView` asks this module WHAT to show; it only mounts / fades layers.
 *
 * Rules:
 * - **Stanje → medij (§0 rule 1):** the shown media always belongs to the wanted state.
 *   With state media (`media.state_media`, paid pets from M5-T08) a missing clip shows
 *   the state's KEY IMAGE (idle: the reference image) — never a clip of another state.
 *   Pets with the older set keep the substitute chain (sick → sleeping → idle) of
 *   `VIDEO_FALLBACKS`, which was David's call for that set (2026-10-06).
 * - **Loop:** a state video in `media.loops` starts on its key image (since 2026-10-23 the
 *   server sends Kling the start frame only — the same start + end frame froze the clip —
 *   so the seam is close, not pixel-identical); the native player loops it (`loop = true`). An older clip
 *   (not in `loops`) is looped the same way (it was made "loopable" by prompt).
 * - **Transition once:** on a state change A → B with a stored `A_to_B` clip, that clip
 *   plays ONCE (no loop), then B's loop. The B loop is mounted (preloaded, hidden) while
 *   the transition plays, so the switch at its end is instant. A transition only plays
 *   when the change happened while the pet was shown (not on the first render).
 * - **Picture mode (M5-T11 plugs in here):** `mode: 'picture'` → the key image of the
 *   wanted state, no clip at all.
 *
 * Extension points: M5-T09 school / M5-T10 game phase clips are more `PlayItem`s of
 * kind `clip` with `loop: false` (play once, hold the last frame) — add their keys to
 * `media` and pick them in the overlay; this module's `nextPlayback` already handles
 * "play once, then continue with X".
 */

import {
  mediaKey,
  selectMediaSource,
  type PetMediaInfo,
  type VideoState,
} from '@/modules/petMedia/petMedia';
import type { PetState } from '@/types';

/** What the player shows now. */
export type PlayItem =
  /** A clip: `loop` true = the state loop; false = play once (transition), then `then`. */
  | {
      kind: 'clip';
      url: string;
      key: string;
      state: VideoState | null;
      loop: boolean;
      transition: string | null;
      /** David 2026-10-23: start this many ms into the clip (a school performing clip with a short window). */
      startAtMs?: number;
    }
  | { kind: 'image'; url: string; key: string; state: VideoState | null }
  | { kind: 'placeholder' };

export type MediaMode = 'video' | 'picture';

export interface PlaybackInput {
  media: PetMediaInfo;
  /** The state to show (after locks, scene and mood — `videoStateFor`); null = locked, no clip. */
  wanted: VideoState | null;
  /** The pet state the server reports (fallback of a behaviour scene). */
  petState: PetState;
  mode?: MediaMode;
  canPlayVideo?: (url: string) => boolean;
}

export interface Playback {
  /** What is on screen. */
  current: PlayItem;
  /** Mounted hidden so it starts instantly (the loop after a transition). */
  preload: PlayItem | null;
}

/** The transition key of a state change (`idle_to_sleeping`). */
export function transitionKey(from: VideoState, to: VideoState): string {
  return `${from}_to_${to}`;
}

function present(url: string | null | undefined): url is string {
  return typeof url === 'string' && url.length > 0;
}

/** The still picture of a state's pose: its key image, idle = the reference image. */
export function poseImageUrl(media: PetMediaInfo, state: VideoState | null): string | null {
  if (state !== null && state !== 'idle') {
    const key = media.keyframes[state];
    if (present(key)) return key;
  }
  return present(media.referenceImageUrl) ? media.referenceImageUrl : null;
}

function imageItem(url: string | null, state: VideoState | null): PlayItem {
  return url === null ? { kind: 'placeholder' } : { kind: 'image', url, key: mediaKey(url), state };
}

/**
 * The steady item for a wanted state (no transition): its loop, else (state media) its key
 * image, else (older set) the substitute chain → reference image → placeholder.
 */
export function steadyItem(input: PlaybackInput): PlayItem {
  const { media, wanted, petState, mode = 'video', canPlayVideo } = input;
  const playable = (url: string | undefined): url is string => present(url) && (canPlayVideo?.(url) ?? true);

  if (mode === 'picture') return imageItem(poseImageUrl(media, wanted), wanted);

  if (media.stateMedia) {
    if (wanted === null) return imageItem(poseImageUrl(media, null), null);
    // A behaviour scene without its own clip shows the pet state (the server's truth) —
    // still that state's own media, never a substitute state.
    for (const state of [wanted, ...(wanted === petState ? [] : [petState])] as VideoState[]) {
      const url = media.videos[state];
      if (playable(url)) {
        return { kind: 'clip', url, key: mediaKey(url), state, loop: true, transition: null };
      }
      const pose = state === 'idle' ? null : media.keyframes[state];
      if (present(pose)) return imageItem(pose, state);
    }
    return imageItem(poseImageUrl(media, wanted), wanted);
  }

  const source = selectMediaSource(media, wanted, { canPlayVideo, sceneFallback: petState });
  if (source.kind === 'video') return { kind: 'clip', url: source.url, key: source.key, state: source.state, loop: true, transition: null };
  if (source.kind === 'image') return { kind: 'image', url: source.url, key: source.key, state: null };
  return { kind: 'placeholder' };
}

/**
 * What to show after a change of the wanted state.
 *
 * `previous` = the state shown before (null on the first render — no transition then);
 * `transitionDone` = the transition for this change already finished (`playToEnd`).
 */
export function nextPlayback(input: PlaybackInput, previous: VideoState | null, transitionDone = false): Playback {
  const steady = steadyItem(input);
  const { media, wanted, mode = 'video', canPlayVideo } = input;

  if (mode === 'picture' || transitionDone || previous === null || wanted === null || previous === wanted) {
    return { current: steady, preload: null };
  }

  const key = transitionKey(previous, wanted);
  const url = media.transitions[key];
  // Only into the target's own loop (a transition that ends in a still / substitute would jump).
  if (!present(url) || !(canPlayVideo?.(url) ?? true) || steady.kind !== 'clip' || steady.state !== wanted) {
    return { current: steady, preload: null };
  }

  return {
    current: { kind: 'clip', url, key: mediaKey(url), state: wanted, loop: false, transition: key },
    preload: steady,
  };
}

/**
 * Transition bookkeeping across renders (pure reducer for the view):
 * - `shown`: the state on screen once the current change settled;
 * - `pending`: a transition that is playing (`from → to`), cleared by `playToEnd` / error.
 */
export interface TransitionState {
  shown: VideoState | null;
  pending: { from: VideoState; to: VideoState } | null;
}

export const NO_TRANSITION: TransitionState = { shown: null, pending: null };

/** A transition that never reports its end (no frame, lost event) is given up after this. */
export const TRANSITION_MAX_MS = 8_000;

/** The wanted state changed (or stayed): start / keep / drop a transition. */
export function onWantedChange(state: TransitionState, wanted: VideoState | null, hasTransition: (from: VideoState, to: VideoState) => boolean): TransitionState {
  // Unchanged → the same object (the view derives this while rendering).
  if (wanted === null) return state.shown === null && state.pending === null ? state : NO_TRANSITION;
  if (state.pending !== null) {
    if (state.pending.to === wanted) return state;
    // Changed again mid-transition: settle on the new state without another transition.
    return { shown: wanted, pending: null };
  }
  if (state.shown === wanted) return state;
  if (state.shown === null) return { shown: wanted, pending: null };
  return hasTransition(state.shown, wanted) ? { shown: state.shown, pending: { from: state.shown, to: wanted } } : { shown: wanted, pending: null };
}

/**
 * M5-T08b (QA minor 5): would `nextPlayback` really play the `from → wanted` transition now —
 * a stored, playable clip AND the target's own loop to continue with? Only then may the reducer
 * mark a transition pending (else a clip arriving minutes later would still play it).
 */
export function canStartTransition(input: PlaybackInput, from: VideoState): boolean {
  const { current } = nextPlayback(input, from);
  return current.kind === 'clip' && current.transition !== null;
}

/**
 * M5-T08b: a pending transition that the player did not choose (its clip failed, the target loop
 * went missing) is settled on the target straight away — it never plays later.
 */
export function settleTransition(state: TransitionState, playback: Playback): TransitionState {
  if (state.pending === null) return state;
  const { current } = playback;
  return current.kind === 'clip' && current.transition !== null ? state : { shown: state.pending.to, pending: null };
}

/** The transition clip played to its end (or failed): continue with the target loop. */
export function onTransitionEnd(state: TransitionState): TransitionState {
  return state.pending === null ? state : { shown: state.pending.to, pending: null };
}
