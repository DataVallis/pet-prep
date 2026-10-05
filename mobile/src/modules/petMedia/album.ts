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
 */

import { mediaKey, PET_STATES, type PetMediaInfo } from '@/modules/petMedia/petMedia';
import type { PetState } from '@/types';

/** User-visible strings (i18n with M1-18). */
export const ALBUM_STRINGS = {
  title: 'Moj kuža',
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

function ordered(states: readonly PetState[]): PetState[] {
  const set = new Set(states);
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

  const stored = Object.keys(media.videos) as PetState[];
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
  return items;
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
