/**
 * AI pet media on the phone (M4-03 app side): which state video / image to show and
 * how to recognise "the same media" behind a re-signed URL.
 *
 * Source: the `media` object of `GET /api/child/pet`, PIN login / pairing, the parent
 * dashboard (`family.pets[]`) and `pet.updated` (backend `PetMediaPayload`):
 * `{status, reference_image_url, videos {state: url}, current_video_url, states,
 * expires_at}`. URLs are our own signed `GET /api/media/{id}?expires=…&v=…&signature=…`
 * links, bucketed by the server (the same URL for ~30 min, valid 60–90 min). `v` is the
 * first 10 hex chars of sha1(storage path) — the path carries the generation, so `v`
 * changes with every newly stored file and `path + v` identifies the media; `expires` / `signature`
 * only renew the capability. The player is keyed by that identity, never by the raw
 * URL, so a poll that re-signs the URL doesn't restart playback.
 *
 * Pure functions only — the components live in `components/PetMediaView.tsx`.
 */

import type { LockReason } from '@/modules/childPet/childPetView';
import { isBehaviourScene, type BehaviourScene } from '@/modules/behaviour/behaviour';
import { readBreed } from '@/modules/species/species';
import type { PetMedia, PetState, ShownBreed } from '@/types';

/**
 * A stored video: one of the six pet states, or a M5-R02 behaviour scene (`accident`,
 * `chewing` — premium only, played while `behaviour.scene` says so).
 */
export type VideoState = PetState | BehaviourScene;

export type PetMediaStatus = 'disabled' | 'pending' | 'failed' | 'partial' | 'ready';

/** Normalised `media` (always complete; unknown / missing parts → empty). */
export interface PetMediaInfo {
  status: PetMediaStatus;
  referenceImageUrl: string | null;
  /** Stored state videos only (incl. behaviour scenes). */
  videos: Partial<Record<VideoState, string>>;
  /** Server's pick for the current `pet_state` (fallback idle) — used when `videos` is empty (older payloads). */
  currentVideoUrl: string | null;
  /** Entitled video states (idle first; behaviour scenes after the six). */
  states: VideoState[];
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

export function isVideoState(value: unknown): value is VideoState {
  return isPetState(value) || isBehaviourScene(value);
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
  const videos: Partial<Record<VideoState, string>> = {};
  if (typeof m.videos === 'object' && m.videos !== null && !Array.isArray(m.videos)) {
    for (const [state, url] of Object.entries(m.videos as Record<string, unknown>)) {
      const u = urlOrNull(url);
      if (isVideoState(state) && u !== null) videos[state] = u;
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
    states: Array.isArray(m.states) ? m.states.filter(isVideoState) : [],
    expiresAt: urlOrNull(m.expires_at),
  };
}

/**
 * Identity of a signed media URL: everything but the capability part. Keeps the path
 * and the `v` parameter (sha1 of the storage path — new with every stored file), drops `expires`, `signature` and anything
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
 * - vet visit (`ill`) → `sick` (`selectMediaSource` substitutes `sleeping` when the tier has no sick video)
 * - parent's hard stop → `sleeping` (the game is paused, the dog rests)
 * - game over / inactive / unborn (contract) → no video (the lock screen covers the HUD;
 *   a happy loop behind "the dog was taken" would be wrong)
 * - M5-R02: an open accident / chewing (`behaviour.scene`) without a lock → that scene;
 *   `selectMediaSource` falls back to the `pet_state` chain when it isn't stored (free tier).
 * - M5-R05: the happy mood (`play.mood.scene`, already filtered by `moodSceneAt`) sits
 *   between the behaviour scene and the pet state: `playing`, whose chain ends in `idle`.
 */
export function videoStateFor(
  petState: PetState,
  lockReason: LockReason | null = null,
  scene: BehaviourScene | null = null,
  mood: 'playing' | null = null,
): VideoState | null {
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
      return scene ?? mood ?? petState;
  }
}

export type MediaSource =
  | { kind: 'video'; state: VideoState | null; url: string; key: string }
  | { kind: 'image'; url: string; key: string }
  | { kind: 'placeholder' };

export interface SelectOptions {
  /** Videos the player may load now (see `canPlayUrl`); default: all. Images are always usable. */
  canPlayVideo?: (url: string) => boolean;
  /**
   * Behaviour scene wanted: the pet state whose chain follows when the scene video is
   * missing (default `sick` — a dirty dog is `sick` on the server).
   */
  sceneFallback?: PetState;
}

/**
 * Substitute videos per wanted state, best first (2026-10-06, awaiting David's OK). A free
 * mutt is only entitled to `idle` + `sleeping` (backend `config/media.php` basic set), so a
 * sick dog at the vet must not fall back to the happy idle loop — a resting dog reads as
 * unwell, a cheerful one doesn't. Same for a tired dog. Hungry / playing have no calmer
 * substitute → idle. `videoChain` always ends with idle.
 */
export const VIDEO_FALLBACKS: Readonly<Record<PetState, readonly PetState[]>> = {
  idle: [],
  sleeping: ['idle'],
  low_energy: ['sleeping', 'idle'],
  hungry: ['idle'],
  sick: ['sleeping', 'idle'],
  playing: ['idle'],
};

/**
 * Video states to try for `wanted`, best first. A behaviour scene (M5-R02) is followed by
 * the chain of `sceneFallback` (the pet state the server reports).
 */
export function videoChain(wanted: VideoState, sceneFallback: PetState = 'sick'): VideoState[] {
  if (isBehaviourScene(wanted)) return [wanted, ...videoChain(sceneFallback)];
  const chain: PetState[] = [wanted, ...VIDEO_FALLBACKS[wanted]];
  return chain.includes('idle') ? chain : [...chain, 'idle'];
}

/**
 * Fallback chain: video of the wanted state → its substitutes (`VIDEO_FALLBACKS`, e.g.
 * sick → sleeping → idle) → the server's `current_video_url` (payloads without `videos`)
 * → reference image → placeholder. `wanted = null` skips the videos (locked states).
 */
export function selectMediaSource(media: PetMediaInfo, wanted: VideoState | null, options: SelectOptions = {}): MediaSource {
  const present = (url: string | null | undefined): url is string => typeof url === 'string' && url.length > 0;
  const playable = (url: string | null | undefined): url is string =>
    present(url) && (options.canPlayVideo?.(url) ?? true);

  if (wanted !== null) {
    const candidates: Array<[VideoState | null, string | null | undefined]> = videoChain(wanted, options.sceneFallback).map(
      (state): [VideoState, string | undefined] => [state, media.videos[state]],
    );
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

/**
 * Loose breed string (dashboard payloads) → typed. M5-R06-02: a breed this build doesn't
 * know stays `unknown` (generic placeholder, neutral label) — never the mutt.
 */
export function toBreedType(value: string | null | undefined): ShownBreed {
  return readBreed(value);
}

/** "The dog is still being prepared" — show the gentle pending hint. */
export function isMediaPending(media: PetMediaInfo): boolean {
  return media.status === 'pending';
}

/**
 * Video error recovery per media key (pure reducer so it can be tested without a
 * player):
 * 1. first error → ask for a fresh state once (`refetch`) and wait for a new URL
 *    (an expired signature is the usual cause); if the same URL is still all we have
 *    after `FAILED_RETRY_MS`, it is tried once more;
 * 2. a new URL for the same key → retry it;
 * 3. a second error (or no way to refetch) → the key is failed for
 *    `FAILED_RETRY_MS` (a decoder / network hiccup may pass) and the chain falls back;
 * 4. after that one more try with the current URL; failing again → failed for good
 *    (until the file changes — a new `v` is a new key).
 * A frame from the video clears the key's history (the next expiry starts at 1).
 */
export const FAILED_RETRY_MS = 60_000;

export interface VideoErrorState {
  /** key → URL that failed once and when (waiting for a re-signed one). */
  retrying: Readonly<Record<string, { url: string; since: number }>>;
  /** key → device ms until which it is failed; null = for good. */
  failed: Readonly<Record<string, number | null>>;
}

export const NO_VIDEO_ERRORS: VideoErrorState = { retrying: {}, failed: {} };

function without<T>(record: Readonly<Record<string, T>>, key: string): Record<string, T> {
  const next = { ...record };
  delete next[key];
  return next;
}

export function recordVideoError(
  state: VideoErrorState,
  url: string,
  canRefetch: boolean,
  nowMs: number,
): { state: VideoErrorState; refetch: boolean } {
  const key = mediaKey(url);
  const failedUntil = state.failed[key];
  if (failedUntil === null) return { state, refetch: false };
  if (failedUntil !== undefined) {
    if (nowMs < failedUntil) return { state, refetch: false };
    // The extra try after the cool-down failed too.
    return { state: { retrying: without(state.retrying, key), failed: { ...state.failed, [key]: null } }, refetch: false };
  }
  // Second error for this media (the retried URL failed too), or nothing to refetch.
  if (!canRefetch || key in state.retrying) {
    return {
      state: { retrying: without(state.retrying, key), failed: { ...state.failed, [key]: nowMs + FAILED_RETRY_MS } },
      refetch: false,
    };
  }
  return { state: { ...state, retrying: { ...state.retrying, [key]: { url, since: nowMs } } }, refetch: true };
}

/** The video showed a frame: forget its errors (a later expiry may refetch again). */
export function recordVideoReady(state: VideoErrorState, url: string): VideoErrorState {
  const key = mediaKey(url);
  if (!(key in state.retrying) && !(key in state.failed)) return state;
  return { retrying: without(state.retrying, key), failed: without(state.failed, key) };
}

/**
 * Whether `url` may be loaded now: not failed (or its cool-down is over), and — while
 * its key waits for a fresh URL — only a changed URL, or the same one after the wait.
 */
export function canPlayUrl(state: VideoErrorState, url: string, nowMs: number): boolean {
  const key = mediaKey(url);
  const failedUntil = state.failed[key];
  if (failedUntil === null) return false;
  if (failedUntil !== undefined) return nowMs >= failedUntil;
  const waiting = state.retrying[key];
  return waiting === undefined || waiting.url !== url || nowMs - waiting.since >= FAILED_RETRY_MS;
}

/** Next moment a cool-down / wait ends (device ms), or null — the view re-evaluates then. */
export function nextFailedExpiry(state: VideoErrorState, nowMs: number): number | null {
  const ends = [
    ...Object.values(state.failed).filter((t): t is number => t !== null),
    ...Object.values(state.retrying).map((w) => w.since + FAILED_RETRY_MS),
  ].filter((t) => t > nowMs);
  return ends.length > 0 ? Math.min(...ends) : null;
}

/** The key waits for a re-signed URL (its frozen last frame may stay on screen). */
export function isWaitingForUrl(state: VideoErrorState, key: string): boolean {
  return key in state.retrying;
}
