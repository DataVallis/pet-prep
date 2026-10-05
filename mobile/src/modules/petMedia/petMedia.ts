/**
 * AI pet media on the phone (M4-03 app side): which state video / image to show and
 * how to recognise "the same media" behind a re-signed URL.
 *
 * Source: the `media` object of `GET /api/child/pet`, PIN login / pairing, the parent
 * dashboard (`family.pets[]`) and `pet.updated` (backend `PetMediaPayload`):
 * `{status, reference_image_url, videos {state: url}, current_video_url, states,
 * expires_at}`. URLs are our own signed `GET /api/media/{id}?expires=…&v=…&signature=…`
 * links, bucketed by the server (the same URL for ~30 min, valid 60–90 min). `v` is a
 * hash of the stored file, so `path + v` identifies the media; `expires` / `signature`
 * only renew the capability. The player is keyed by that identity, never by the raw
 * URL, so a poll that re-signs the URL doesn't restart playback.
 *
 * Pure functions only — the components live in `components/PetMediaView.tsx`.
 */

import type { LockReason } from '@/modules/childPet/childPetView';
import type { BreedType, PetMedia, PetState } from '@/types';

export type PetMediaStatus = 'disabled' | 'pending' | 'failed' | 'partial' | 'ready';

/** Normalised `media` (always complete; unknown / missing parts → empty). */
export interface PetMediaInfo {
  status: PetMediaStatus;
  referenceImageUrl: string | null;
  /** Stored state videos only. */
  videos: Partial<Record<PetState, string>>;
  /** Server's pick for the current `pet_state` (fallback idle) — used when `videos` is empty (older payloads). */
  currentVideoUrl: string | null;
  /** Entitled video states (idle first). */
  states: PetState[];
  expiresAt: string | null;
}

export const PET_STATES: readonly PetState[] = ['idle', 'sleeping', 'low_energy', 'hungry', 'sick', 'playing'];
const STATUSES: readonly PetMediaStatus[] = ['disabled', 'pending', 'failed', 'partial', 'ready'];

export const EMPTY_PET_MEDIA: PetMediaInfo = {
  status: 'disabled',
  referenceImageUrl: null,
  videos: {},
  currentVideoUrl: null,
  states: [],
  expiresAt: null,
};

function isPetState(value: unknown): value is PetState {
  return typeof value === 'string' && (PET_STATES as readonly string[]).includes(value);
}

function urlOrNull(value: unknown): string | null {
  return typeof value === 'string' && value.length > 0 ? value : null;
}

/** Legacy top-level fields (pre-M4-05 payloads / broadcasts without `media`). */
export interface LegacyMediaFields {
  current_video_url?: string | null;
  reference_image_url?: string | null;
  media_status?: string | null;
}

/** Map the old `pets.media_status` (disabled | pending | ready | failed) to the new status. */
function legacyStatus(fields: LegacyMediaFields): PetMediaStatus {
  if (fields.reference_image_url) return 'partial';
  const s = fields.media_status;
  return s === 'pending' || s === 'failed' || s === 'disabled' ? s : 'disabled';
}

/**
 * `media` from any payload → `PetMediaInfo`. Never throws; a missing / malformed `media`
 * falls back to the legacy top-level URLs so older servers keep working.
 */
export function normalizePetMedia(raw: PetMedia | null | undefined | unknown, legacy: LegacyMediaFields = {}): PetMediaInfo {
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) {
    const currentVideoUrl = urlOrNull(legacy.current_video_url);
    const referenceImageUrl = urlOrNull(legacy.reference_image_url);
    if (currentVideoUrl === null && referenceImageUrl === null && !legacy.media_status) return EMPTY_PET_MEDIA;
    return { ...EMPTY_PET_MEDIA, status: legacyStatus(legacy), currentVideoUrl, referenceImageUrl };
  }
  const m = raw as Record<string, unknown>;
  const videos: Partial<Record<PetState, string>> = {};
  if (typeof m.videos === 'object' && m.videos !== null && !Array.isArray(m.videos)) {
    for (const [state, url] of Object.entries(m.videos as Record<string, unknown>)) {
      const u = urlOrNull(url);
      if (isPetState(state) && u !== null) videos[state] = u;
    }
  }
  const status =
    typeof m.status === 'string' && (STATUSES as readonly string[]).includes(m.status)
      ? (m.status as PetMediaStatus)
      : 'disabled';
  return {
    status,
    referenceImageUrl: urlOrNull(m.reference_image_url),
    videos,
    currentVideoUrl: urlOrNull(m.current_video_url),
    states: Array.isArray(m.states) ? m.states.filter(isPetState) : [],
    expiresAt: urlOrNull(m.expires_at),
  };
}

/**
 * Identity of a signed media URL: everything but the capability part. Keeps the path
 * and the `v` (stored-file hash) parameter, drops `expires`, `signature` and anything
 * else. Two URLs with the same key point at the same file.
 */
export function mediaKey(url: string): string {
  const hashAt = url.indexOf('#');
  const noHash = hashAt === -1 ? url : url.slice(0, hashAt);
  const q = noHash.indexOf('?');
  if (q === -1) return noHash;
  const base = noHash.slice(0, q);
  const version = noHash
    .slice(q + 1)
    .split('&')
    .map((pair) => pair.split('='))
    .find(([name]) => name === 'v');
  return version && version[1] ? `${base}?v=${version[1]}` : base;
}

export function sameMedia(a: string | null, b: string | null): boolean {
  if (a === null || b === null) return a === b;
  return mediaKey(a) === mediaKey(b);
}

/**
 * Which state video the HUD should play (null = no video, show the image).
 *
 * `pet_state` already comes from the server's rules (`PetDecayService`): quiet hours →
 * `sleeping`, hygiene 0 → `sick`, hunger / thirst ≤ 30 → `hungry`, energy ≤ 30 →
 * `low_energy`, energy ≥ 80 and hunger ≥ 60 → `playing`, else `idle`. Locks override it:
 * - vet visit (`ill`) → `sick`
 * - parent's hard stop → `sleeping` (the game is paused, the dog rests)
 * - game over / inactive / unborn (contract) → no video (the lock screen covers the HUD;
 *   a happy loop behind "the dog was taken" would be wrong)
 */
export function videoStateFor(petState: PetState, lockReason: LockReason | null = null): PetState | null {
  switch (lockReason) {
    case 'game_over':
    case 'inactive':
    case 'contract_required':
      return null;
    case 'ill':
      return 'sick';
    case 'hard_stopped':
      return 'sleeping';
    default:
      return petState;
  }
}

export type MediaSource =
  | { kind: 'video'; state: PetState | null; url: string; key: string }
  | { kind: 'image'; url: string; key: string }
  | { kind: 'placeholder' };

export interface SelectOptions {
  /** Videos the player may load now (see `canPlayUrl`); default: all. Images are always usable. */
  canPlayVideo?: (url: string) => boolean;
}

/**
 * Fallback chain: video of the wanted state → idle video → the server's
 * `current_video_url` (payloads without `videos`) → reference image → placeholder.
 * `wanted = null` skips the videos (locked states).
 */
export function selectMediaSource(media: PetMediaInfo, wanted: PetState | null, options: SelectOptions = {}): MediaSource {
  const present = (url: string | null | undefined): url is string => typeof url === 'string' && url.length > 0;
  const playable = (url: string | null | undefined): url is string =>
    present(url) && (options.canPlayVideo?.(url) ?? true);

  if (wanted !== null) {
    const candidates: Array<[PetState | null, string | null | undefined]> = [
      [wanted, media.videos[wanted]],
      ['idle', media.videos.idle],
    ];
    if (Object.keys(media.videos).length === 0) candidates.push([null, media.currentVideoUrl]);
    for (const [state, url] of candidates) {
      if (playable(url)) return { kind: 'video', state, url, key: mediaKey(url) };
    }
  }
  if (present(media.referenceImageUrl)) {
    return { kind: 'image', url: media.referenceImageUrl, key: mediaKey(media.referenceImageUrl) };
  }
  return { kind: 'placeholder' };
}

/** Loose breed string (dashboard payloads) → `BreedType`; unknown → mutt. */
export function toBreedType(value: string | null | undefined): BreedType {
  return value === 'border_collie' ? 'border_collie' : 'mutt';
}

/** "The dog is still being prepared" — show the gentle pending hint. */
export function isMediaPending(media: PetMediaInfo): boolean {
  return media.status === 'pending';
}

/**
 * Expired-URL recovery per media key (pure reducer so it can be tested without a
 * player): first error → ask for a fresh state once (`refetch`) and wait for a new URL;
 * a new URL for the same key → retry it; a second error (or no way to refetch) → the
 * key is failed for good and the chain falls back to the image.
 */
export interface VideoErrorState {
  /** key → URL that failed once (waiting for a re-signed one). */
  retrying: Readonly<Record<string, string>>;
  /** keys that failed for good. */
  failed: ReadonlySet<string>;
}

export const NO_VIDEO_ERRORS: VideoErrorState = { retrying: {}, failed: new Set<string>() };

export function recordVideoError(
  state: VideoErrorState,
  url: string,
  canRefetch: boolean,
): { state: VideoErrorState; refetch: boolean } {
  const key = mediaKey(url);
  if (state.failed.has(key)) return { state, refetch: false };
  // Second error for this media (the re-signed URL failed too), or nothing to refetch.
  if (!canRefetch || key in state.retrying) {
    const retrying = { ...state.retrying };
    delete retrying[key];
    return { state: { retrying, failed: new Set([...state.failed, key]) }, refetch: false };
  }
  return { state: { ...state, retrying: { ...state.retrying, [key]: url } }, refetch: true };
}

/** The video showed a frame: its key may refetch again on a later error (next expiry). */
export function recordVideoReady(state: VideoErrorState, url: string): VideoErrorState {
  const key = mediaKey(url);
  if (!(key in state.retrying)) return state;
  const retrying = { ...state.retrying };
  delete retrying[key];
  return { ...state, retrying };
}

/**
 * Whether `url` may be loaded now: not failed, and — while its key waits for a fresh
 * URL — only once the URL actually changed (the re-signed one).
 */
export function canPlayUrl(state: VideoErrorState, url: string): boolean {
  const key = mediaKey(url);
  if (state.failed.has(key)) return false;
  const waitingOn = state.retrying[key];
  return waitingOn === undefined || waitingOn !== url;
}
