/**
 * Pet media player failures → app error reports (scope `pet-media`, David 2026-11-24 »ja naredi«).
 *
 * Why: on a phone the pet sometimes shows its still image although the clip is `ready` on the
 * server — the player failed on the device (`PetMediaView` → `recordVideoError` → refetch →
 * cool-down → given up) and nobody learned why. One report per media label per attempt stage
 * per app session tells us.
 *
 * A report carries ONLY technical facts, encoded in the message (no new payload fields):
 *
 *   pet-media <label> src=<local|remote> stage=<refetch|cooldown|given-up> layer=<span> link=<±span|none> player=<text|->
 *
 *  - `label`: what was playing — `state:<state>`, `transition:<a_to_b>`, `phase:<clip>`,
 *    `item:<toy_1|bed|bowls|bowls-drink|treats>` or `clip:<loop|once>` (legacy / unknown);
 *  - `src`: the phone's cached file or the remote (signed) URL;
 *  - `stage`: what `recordVideoError` did with this error;
 *  - `layer`: how long the player layer existed before it failed (instant vs mid-playback);
 *  - `link`: time left on the URL's signature (`expires` query value vs the device clock;
 *    negative = expired — device clock skew applies);
 *  - `player`: expo-video's `StatusChangeEventPayload.error.message`, scrubbed + clipped.
 *
 * Never the URL, the signed query, a media / pet / family / child id or a name. Spans use at
 * most three digits (`s` / `m` / `h` / `d`) so the scrubber's 4+-digit rule never eats them.
 * The reporter adds the rest (release builds only, server switch `app_error_reports`, scrub).
 */

import { reportAppError } from '@/modules/errorReporting/reporter';
import { scrubText, stripPaths } from '@/modules/errorReporting/errorReport';
import type { PlayItem } from '@/modules/petMedia/statePlayer';
import type { PetMediaInfo, VideoErrorState } from '@/modules/petMedia/petMedia';

export type VideoErrorStage = 'refetch' | 'cooldown' | 'given-up';

export type ClipItem = Extract<PlayItem, { kind: 'clip' }>;

export interface PetMediaFailure {
  /** `describeClip(...)` of the layer's item. */
  label: string;
  local: boolean;
  stage: VideoErrorStage;
  /** Layer mount → error, ms. */
  layerAgeMs: number;
  /** Signature expiry − now, ms; null = the URL carries no `expires`. */
  linkLeftMs: number | null;
  /** expo-video's error message, if it gave one. */
  playerMessage: string | null;
}

export const PLAYER_MESSAGE_MAX = 240;
const LABEL_PART = /[^A-Za-z0-9_-]/g;

function part(text: string): string {
  const clean = text.replace(LABEL_PART, '').slice(0, 60);
  return clean.length > 0 ? clean : 'unknown';
}

/** What a clip layer plays, as a short label without any URL / id (see the header). */
export function describeClip(item: ClipItem, media: Pick<PetMediaInfo, 'phases'>): string {
  if (item.transition !== null) return `transition:${part(item.transition)}`;
  if (item.item !== undefined) return `item:${part(item.item)}`;
  const moment = /#item-(.+)-[^-]*$/.exec(item.key);
  if (moment !== null && moment[1] !== undefined) return `item:${part(moment[1])}`;
  const phase = Object.entries(media.phases).find(([, url]) => url === item.url);
  if (phase !== undefined) return `phase:${part(phase[0])}`;
  if (item.loop && item.state !== null) return `state:${part(item.state)}`;
  return `clip:${item.loop ? 'loop' : 'once'}`;
}

/**
 * What `recordVideoError` did with this error (compare the state before / after for `key`);
 * null = nothing changed (already failed for good / still cooling down) — not reported.
 */
export function videoErrorStage(before: VideoErrorState, after: VideoErrorState, key: string, refetch: boolean): VideoErrorStage | null {
  if (refetch) return 'refetch';
  if (after === before) return null;
  const failed = after.failed[key];
  if (failed === null) return 'given-up';
  if (failed !== undefined) return 'cooldown';
  return null;
}

/** Signature expiry of a signed URL (`expires` = unix seconds) minus now; null without one. */
export function linkLeftMs(url: string, nowMs: number): number | null {
  const match = /[?&]expires=(\d+)(?:[&#]|$)/.exec(url);
  if (match === null || match[1] === undefined) return null;
  const expires = Number(match[1]);
  return Number.isFinite(expires) ? expires * 1000 - nowMs : null;
}

/** `42s` / `12m` / `3h` / `5d`, at most three digits (`>999d` beyond). */
export function formatSpan(ms: number): string {
  const s = Math.floor(Math.abs(ms) / 1000);
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 48) return `${h}h`;
  const d = Math.floor(h / 24);
  return d > 999 ? '>999d' : `${d}d`;
}

function signedSpan(ms: number): string {
  return `${ms < 0 ? '-' : '+'}${formatSpan(ms)}`;
}

/** The report message in the fixed format of the header. */
export function petMediaErrorMessage(failure: PetMediaFailure): string {
  const raw = failure.playerMessage?.trim() ?? '';
  const scrubbed = raw.length > 0 ? scrubText(stripPaths(raw)).replace(/\s+/g, ' ').trim() : '';
  const player = scrubbed.length > 0 ? (scrubbed.length > PLAYER_MESSAGE_MAX ? `${scrubbed.slice(0, PLAYER_MESSAGE_MAX - 1)}…` : scrubbed) : '-';
  return [
    'pet-media',
    failure.label,
    `src=${failure.local ? 'local' : 'remote'}`,
    `stage=${failure.stage}`,
    `layer=${formatSpan(failure.layerAgeMs)}`,
    `link=${failure.linkLeftMs === null ? 'none' : signedSpan(failure.linkLeftMs)}`,
    `player=${player}`,
  ].join(' ');
}

/** The error the report is built from (name `PetMediaError`). */
export class PetMediaError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PetMediaError';
  }
}

/** label|stage pairs already reported in this app session. */
const reported = new Set<string>();

/**
 * Report one player failure — at most once per label + stage per app session (a broken clip
 * retried by every remount never floods). Never throws. Returns whether it was queued.
 */
export function reportPetMediaFailure(failure: PetMediaFailure): boolean {
  try {
    const once = `${failure.label}|${failure.stage}`;
    if (reported.has(once)) return false;
    const queued = reportAppError('pet-media', new PetMediaError(petMediaErrorMessage(failure)));
    // Not queued (reporting off, switch off, device dedupe) → may be tried again later.
    if (queued) reported.add(once);
    return queued;
  } catch {
    return false;
  }
}

export function resetPetMediaReportsForTests(): void {
  reported.clear();
}
