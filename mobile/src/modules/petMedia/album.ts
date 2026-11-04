/**
 * "Moj kuža" album (2026-10-05) — pure model, tested in `__tests__/album.test.ts`.
 *
 * The child sees ALL of the dog's media, not only the one state video the HUD loops:
 * the reference photo first, then one tile per **entitled** video state
 * (`media.states`, e.g. a free mutt: idle + sleeping, a premium breed: all six).
 * - entitled + stored → playable tile;
 * - entitled, not stored yet (still generating / failed) → greyed "Še ni posnetka";
 * - not entitled → hidden (no teasing of paid content in the child app).
 * Older payloads without `states` fall back to the stored `videos` (or the server's
 * single `current_video_url`, shown as "Miruje").
 * Nothing stored at all and generation `disabled` / `failed` → an empty album (and no
 * HUD button): greyed tiles would promise videos that are not coming. While `pending` /
 * `partial` the greyed tiles stay ("Še ni posnetka" — they are on their way).
 *
 * Bought items (M6-W03, David 2026-11-04 — `media.items`, only while owned + ready):
 * - the »Spi« tile shows the bed (`sceneItemFor(items, 'sleeping', …)`, the same pick as the
 *   live sleeping scene — they never disagree): its clip (poster = the bed image), in picture
 *   mode / without a clip its image; also when the sleeping clip itself isn't stored yet;
 * - after the state tiles one tile per owned item (bed only when no »Spi« tile carries it,
 *   bowls, toy_1, toy_2, treats) through `itemPick` — clip, else / in picture mode the image;
 *   an item with nothing usable gets no tile. A failing URL behaves like every album URL
 *   (placeholder poster, »Poskusi znova« + refetch in the viewer).
 */

import { itemPick, sceneItemFor, type ItemPick } from '@/modules/petMedia/itemMedia';
import { mediaKey, PET_STATES, type ItemMediaKey, type PetMediaInfo, type VideoState } from '@/modules/petMedia/petMedia';
import type { PetState } from '@/types';
import { t } from '@/i18n';
import { strings } from '@/i18n/strings';

/** User-visible strings (`pet:album`, M1-18). Items built from them carry the language of their build. */
export const ALBUM_STRINGS = strings('pet', 'album', {
  position: (index: number, total: number) => t('pet:album.position', { index, total }),
});

/** Tile id: the reference photo, a state, or a bought item (M6-W03). */
export type AlbumItemId = 'photo' | PetState | ItemMediaKey;

export type AlbumItem =
  | { kind: 'photo'; id: AlbumItemId; label: string; url: string; key: string }
  | {
      kind: 'video';
      id: AlbumItemId;
      /** The state of a state tile; null for an item tile. */
      state: PetState | null;
      label: string;
      url: string;
      key: string;
      /** The clip's own first frame (an item's image); undefined = the reference photo. */
      poster?: string;
    }
  | { kind: 'missing'; id: PetState; state: PetState; label: string };

/** Album order of the item tiles. */
export const ALBUM_ITEM_ORDER: readonly ItemMediaKey[] = ['bed', 'bowls', 'toy_1', 'toy_2', 'treats'];

function itemLabel(key: ItemMediaKey, bothToys: boolean): string {
  const s = ALBUM_STRINGS.items;
  switch (key) {
    case 'bed':
      return s.bed;
    case 'bowls':
      return s.bowls;
    case 'treats':
      return s.treats;
    case 'toy_1':
      return bothToys ? s.toy_1 : s.toy;
    case 'toy_2':
      return bothToys ? s.toy_2 : s.toy;
  }
}

/** A playable tile from an item pick (clip → video tile with the item image as poster). */
function pickTile(id: AlbumItemId, state: PetState | null, label: string, pick: ItemPick): AlbumItem | null {
  if (pick.video !== null) {
    return { kind: 'video', id, state, label, url: pick.video, key: mediaKey(pick.video), ...(pick.image !== null ? { poster: pick.image } : {}) };
  }
  if (pick.image !== null) return { kind: 'photo', id, label, url: pick.image, key: mediaKey(pick.image) };
  return null;
}

/** Items that can be opened in the viewer (photo + stored videos). */
export type PlayableAlbumItem = Exclude<AlbumItem, { kind: 'missing' }>;

export function isPlayable(item: AlbumItem): item is PlayableAlbumItem {
  return item.kind !== 'missing';
}

/** The six pet states in album order; behaviour scenes (M5-R02) are not album tiles. */
function ordered(states: readonly VideoState[]): PetState[] {
  const set = new Set<VideoState>(states);
  return PET_STATES.filter((s) => set.has(s));
}

/** `mode` = the child's »Prikaz« (picture → item tiles show their image); the parent passes nothing. */
export function buildAlbumItems(media: PetMediaInfo, mode: 'video' | 'picture' = 'video'): AlbumItem[] {
  const items: AlbumItem[] = [];
  if (media.referenceImageUrl) {
    items.push({
      kind: 'photo',
      id: 'photo',
      label: ALBUM_STRINGS.photo,
      url: media.referenceImageUrl,
      key: mediaKey(media.referenceImageUrl),
    });
  }

  const stored = Object.keys(media.videos) as VideoState[];
  const entitled = media.states.length > 0 ? ordered(media.states) : ordered(stored);
  let bedShown = false;
  for (const state of entitled) {
    const label = ALBUM_STRINGS.states[state];
    if (state === 'sleeping') {
      const bed = sceneItemFor(media.items, 'sleeping', null, mode);
      const tile = bed === null ? null : pickTile(state, state, label, bed);
      if (tile !== null) {
        items.push(tile);
        bedShown = true;
        continue;
      }
    }
    const url = media.videos[state];
    items.push(
      url
        ? { kind: 'video', id: state, state, label: ALBUM_STRINGS.states[state], url, key: mediaKey(url) }
        : { kind: 'missing', id: state, state, label: ALBUM_STRINGS.states[state] },
    );
  }

  // Legacy payload: no `states`, no `videos`, only the server's current pick.
  if (entitled.length === 0 && media.currentVideoUrl) {
    items.push({
      kind: 'video',
      id: 'idle',
      state: 'idle',
      label: ALBUM_STRINGS.states.idle,
      url: media.currentVideoUrl,
      key: mediaKey(media.currentVideoUrl),
    });
  }

  const bothToys = media.items.toy_1 !== undefined && media.items.toy_2 !== undefined;
  for (const key of ALBUM_ITEM_ORDER) {
    if (key === 'bed' && bedShown) continue;
    const pick = itemPick(media.items[key], mode);
    const tile = pick === null ? null : pickTile(key, null, itemLabel(key, bothToys), pick);
    if (tile !== null) items.push(tile);
  }

  const nothingComing = media.status === 'disabled' || media.status === 'failed';
  if (nothingComing && !items.some(isPlayable)) return [];
  return items;
}

/** The HUD shows the album button only when the album has something to show. */
export function hasAlbum(media: PetMediaInfo, mode: 'video' | 'picture' = 'video'): boolean {
  return buildAlbumItems(media, mode).length > 0;
}

/**
 * Album refresh policy (PR #31 review): one refetch per media key, again after
 * {@link ALBUM_REFRESH_COOLDOWN_MS} (a re-signed URL that failed may have been a
 * network hiccup); and a proactive refetch {@link ALBUM_REFRESH_LEAD_MS} before the
 * signed URLs expire (`media.expiresAt`) while the album is open.
 */
export const ALBUM_REFRESH_COOLDOWN_MS = 5 * 60_000;
export const ALBUM_REFRESH_LEAD_MS = 60_000;

/** ms until the proactive refetch (0 = now), or null without a known expiry. */
export function refreshDelay(expiresAt: string | null, nowMs: number): number | null {
  if (expiresAt === null) return null;
  const at = Date.parse(expiresAt);
  if (Number.isNaN(at)) return null;
  return Math.max(0, at - ALBUM_REFRESH_LEAD_MS - nowMs);
}

/** Whether a failed media may trigger a refetch now (never before, or after the cooldown). */
export function mayRefresh(lastMs: number | undefined, nowMs: number): boolean {
  return lastMs === undefined || nowMs - lastMs >= ALBUM_REFRESH_COOLDOWN_MS;
}

/** Index in the playable list after a swipe / arrow, wrapping around; null when empty. */
export function stepIndex(index: number, delta: -1 | 1, length: number): number | null {
  if (length === 0) return null;
  return (((index + delta) % length) + length) % length;
}

/** Horizontal swipe → direction (null = not a swipe). Left swipe = next item. */
export const SWIPE_MIN_DX = 50;
export function swipeDirection(dx: number, dy: number): -1 | 1 | null {
  if (Math.abs(dx) < SWIPE_MIN_DX || Math.abs(dx) < Math.abs(dy) * 1.5) return null;
  return dx < 0 ? 1 : -1;
}
