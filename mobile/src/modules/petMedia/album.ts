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
 */

import { mediaKey, PET_STATES, type PetMediaInfo, type VideoState } from '@/modules/petMedia/petMedia';
import type { PetState } from '@/types';

/** User-visible strings (i18n with M1-18). */
export const ALBUM_STRINGS = {
  title: 'Moj kuža',
  /** Parent's child detail (read-only viewer). */
  parentTitle: 'Posnetki kužka',
  videoHint: 'video',
  photoHint: 'fotografija',
  retry: 'Poskusi znova',
  open: 'Odpri album',
  close: 'Zapri album',
  back: 'Nazaj na album',
  photo: 'Fotografija',
  missing: 'Še ni posnetka',
  empty: 'Kuža se še pripravlja. Kmalu bodo tu njegove slike in posnetki.',
  unavailable: 'Posnetka trenutno ni mogoče predvajati.',
  previous: 'Prejšnji',
  next: 'Naslednji',
  mute: 'Izklopi zvok',
  unmute: 'Vklopi zvok',
  position: (index: number, total: number) => `${index} / ${total}`,
  states: {
    idle: 'Miruje',
    sleeping: 'Spi',
    low_energy: 'Utrujen',
    hungry: 'Lačen',
    sick: 'Bolan',
    playing: 'Se igra',
  } satisfies Record<PetState, string>,
} as const;

export type AlbumItem =
  | { kind: 'photo'; id: 'photo'; label: string; url: string; key: string }
  | { kind: 'video'; id: PetState; state: PetState; label: string; url: string; key: string }
  | { kind: 'missing'; id: PetState; state: PetState; label: string };

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

export function buildAlbumItems(media: PetMediaInfo): AlbumItem[] {
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
  for (const state of entitled) {
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

  const nothingComing = media.status === 'disabled' || media.status === 'failed';
  if (nothingComing && !items.some(isPlayable)) return [];
  return items;
}

/** The HUD shows the album button only when the album has something to show. */
export function hasAlbum(media: PetMediaInfo): boolean {
  return buildAlbumItems(media).length > 0;
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
