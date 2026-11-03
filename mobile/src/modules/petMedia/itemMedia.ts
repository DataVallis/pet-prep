/**
 * Bought items in the pet's scenes (M6-W03, SHOP_SPEC §4 + S1 / S8) — pure: WHICH item media
 * replaces which scene. `PetMediaView` / the game decks only play what this returns.
 *
 * Source: `media.items` of every `PetMediaPayload` (child state, broadcasts, dashboard). A key is
 * present ONLY when the pet owns the item AND its content for the pet's current stage is ready;
 * a missing key = the normal scene. Looks only (S1) — nothing here touches a game rule.
 *
 * - `bed` (loop) → wherever the SLEEPING loop / key image shows (also the sleeping substitute of
 *   an older media set). Transitions into / out of a replaced state are skipped (crossfade).
 * - `toy_1` / `toy_2` (loop) → wherever the generic `playing` loop shows (home `playing`, the
 *   cuddle stage, the ball / wand decks WITHOUT their phase group, the v1 play stage). Both
 *   owned → one toy per play session, alternating (`pickToy` + `advanceToyTurn`). Phase-clip
 *   decks (ball / wand v2 with their group) are never touched.
 * - `bowls` / `treats` (once) → a one-shot moment on the stage after an accepted feed / water or
 *   a given treat (`itemMomentPlayback`), then the normal stage. Picture mode → the item image
 *   for `ITEM_PICTURE_MS`.
 * A missing / failing URL falls back to the item image, then to the normal scene — never blank.
 */

import { isBehaviourScene } from '@/modules/behaviour/behaviour';
import type { PetState } from '@/types';
import { mediaKey, type ItemMedia, type ItemMediaKey, type ItemMediaMap, type VideoState } from '@/modules/petMedia/petMedia';

export type { ItemMedia, ItemMediaKey, ItemMediaMap };
export { ITEM_MEDIA_KEYS, NO_ITEM_MEDIA, readItemMedia } from '@/modules/petMedia/petMedia';

export type ToyKey = 'toy_1' | 'toy_2';
/** Items that replace a state loop. */
export type SceneItemKey = 'bed' | ToyKey;
/** Items that play once as a moment. */
export type MomentItemKey = 'bowls' | 'treats';

// ── Toys ─────────────────────────────────────────────────────

let toyTurn = 0;

/** The current play-session turn (the home stage shows the toy of this turn). */
export function currentToyTurn(): number {
  return toyTurn;
}

/** A new play session opens: the next toy (alternates when both are owned). Returns the new turn. */
export function advanceToyTurn(): number {
  toyTurn += 1;
  return toyTurn;
}

/** Jest only. */
export function resetToyTurnForTests(): void {
  toyTurn = 0;
}

/** The toy shown in this session: the only one owned, or alternating by turn when both are. */
export function pickToy(items: ItemMediaMap, turn: number = toyTurn): ToyKey | null {
  const one = items.toy_1 !== undefined;
  const two = items.toy_2 !== undefined;
  if (one && two) return Math.abs(turn) % 2 === 0 ? 'toy_1' : 'toy_2';
  if (one) return 'toy_1';
  if (two) return 'toy_2';
  return null;
}

// ── Scenes (loops) ───────────────────────────────────────────

/** The item that replaces a state's loop (bed → sleeping, toy → playing), if owned + ready. */
export function sceneItemKey(items: ItemMediaMap, state: VideoState | null, toy: ToyKey | null): SceneItemKey | null {
  if (state === 'sleeping') return items.bed !== undefined ? 'bed' : null;
  if (state === 'playing') return toy !== null && items[toy] !== undefined ? toy : null;
  return null;
}

export interface SceneItemPick {
  key: SceneItemKey;
  /** The loop to play (null = show `image`). */
  video: string | null;
  image: string | null;
}

/**
 * The item media to show for a state, honouring the display mode and the player's failed URLs:
 * picture mode → its image; video → its loop when playable, else its image; nothing usable → null.
 */
export function sceneItemFor(
  items: ItemMediaMap,
  state: VideoState | null,
  toy: ToyKey | null,
  mode: 'video' | 'picture',
  canPlayVideo?: (url: string) => boolean,
): SceneItemPick | null {
  const key = sceneItemKey(items, state, toy);
  if (key === null) return null;
  const item = items[key] as ItemMedia;
  const playable = item.video !== null && mode === 'video' && (canPlayVideo?.(item.video) ?? true);
  if (playable) return { key, video: item.video, image: item.image };
  if (item.image !== null) return { key, video: null, image: item.image };
  return null;
}

/**
 * The states whose shown scene an item may replace: the wanted state; a behaviour scene without
 * its own media → the pet state behind it; an older set's sleeping SUBSTITUTE (sick / tired →
 * the sleeping loop) → sleeping.
 */
export function sceneStatesFor(wanted: VideoState, petState: PetState, shownState: VideoState | null, sceneShown: boolean): VideoState[] {
  if (isBehaviourScene(wanted)) {
    if (sceneShown) return [];
    return shownState === 'sleeping' && petState !== 'sleeping' ? [petState, 'sleeping'] : [petState];
  }
  return shownState === 'sleeping' && wanted !== 'sleeping' ? [wanted, 'sleeping'] : [wanted];
}

/** A bought toy has media (so a play stage has a picture even without a `playing` / idle clip). */
export function hasPlayingMedia(media: { items: ItemMediaMap }, toy: ToyKey | null = pickToy(media.items)): boolean {
  return sceneItemKey(media.items, 'playing', toy) !== null;
}

/** The deck stand-in for a `playing` loop: the toy's loop / image (null = no toy, keep the deck's own). */
export function toyLoop(items: ItemMediaMap, toy: ToyKey | null, mode: 'video' | 'picture'): { url: string | null; image: string | null } | null {
  const pick = sceneItemFor(items, 'playing', toy, mode);
  return pick === null ? null : { url: pick.video, image: pick.image };
}

// ── Moments (once) ───────────────────────────────────────────

/** A moment without a clip (picture mode / image only) stays this long. */
export const ITEM_PICTURE_MS = 2_500;
/** A clip of unknown length is given this long. */
export const ITEM_CLIP_DEFAULT_MS = 3_000;
/** Safety on top of the clip's length: a clip that never reports its end is given up then. */
export const ITEM_CLIP_GRACE_MS = 2_000;
/** Never longer than this (a stuck player never keeps the moment on screen). */
export const ITEM_MOMENT_MAX_MS = 10_000;

export interface ItemMoment {
  key: MomentItemKey;
  /** Changes for every moment (the same clip plays again from its start). */
  instance: number;
}

export interface ItemMomentPlayback {
  /** The item the stage shows (a `PetMediaView phase`-compatible item). */
  current:
    | { kind: 'clip'; url: string; key: string; state: null; loop: false; transition: null }
    | { kind: 'image'; url: string; key: string; state: null };
  /** The still under the clip (the item image, else the reference image). */
  still: string | null;
  /** When the host ends the moment at the latest (ms from its start). */
  maxMs: number;
}

/**
 * What a moment shows: the item clip once (video mode, playable) — `fallbackClip` (the treat
 * answer's clip of an older contract) when the item has none —, else the item image for
 * `ITEM_PICTURE_MS`. Nothing to show → null (the host keeps its plain feedback).
 */
export function itemMomentPlayback(
  items: ItemMediaMap,
  moment: ItemMoment,
  mode: 'video' | 'picture',
  referenceImage: string | null,
  options: { fallbackClip?: string | null; canPlayVideo?: (url: string) => boolean } = {},
): ItemMomentPlayback | null {
  const item = items[moment.key];
  const image = item?.image ?? null;
  const clip = item?.video ?? options.fallbackClip ?? null;
  if (mode === 'video' && clip !== null && (options.canPlayVideo?.(clip) ?? true)) {
    const length = item?.video === clip && item.videoMs !== null ? item.videoMs : ITEM_CLIP_DEFAULT_MS;
    return {
      current: { kind: 'clip', url: clip, key: `${mediaKey(clip)}#item-${moment.key}-${moment.instance}`, state: null, loop: false, transition: null },
      still: image ?? referenceImage,
      maxMs: Math.min(ITEM_MOMENT_MAX_MS, length + ITEM_CLIP_GRACE_MS),
    };
  }
  if (image !== null) {
    return { current: { kind: 'image', url: image, key: mediaKey(image), state: null }, still: image, maxMs: ITEM_PICTURE_MS };
  }
  return null;
}
