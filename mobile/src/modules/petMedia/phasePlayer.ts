/**
 * School and game PHASE clips (M5-T09 / M5-T10, APP_REFINEMENT_SPEC §3 / §10, David
 * 2026-10-20 / 21) — pure: WHICH phase clip (or pose image) the stage shows at a moment
 * of the school / game schedule. `PetMediaView phase={…}` only plays what this returns.
 *
 * Rules:
 * - Every phase clip (3 s) plays ONCE and holds its last frame (`loop: false`); it never
 *   loops into another action. Each new phase instance (a new trial, a new pounce) gets
 *   its own key, so the same clip plays again from the start.
 * - **§0 rule 1:** for a pet whose set has the group (`media.phaseGroups`) a missing clip
 *   shows the phase's POSE image (its end frame: e.g. »sedi« → the sitting pose; the
 *   standing pose = the reference image) — never a state clip, never another phase.
 * - **Picture mode (M5-T11, David 2026-10-21):** pose images only, no clip is mounted
 *   (nothing is downloaded); the app's own layers (ball, feather, cue) stay on top.
 * - A pet without the group (older set, older server) → null: the caller keeps its
 *   earlier stage (state clips idle / playing).
 *
 * School (per trial of the server schedule, `TrainingSession`):
 *   lead-in / cue → `attention` (waits for the command) → `performing` (3 s, timed so it ENDS
 *   in the held pose at `obey_at_ms`, QA M1) → `success` (holds the position through the
 *   praise window) until the next trial's attention ·
 *   a cue the dog ignores → the standing pose image (the text says it sniffs around —
 *   no clip that could show attention instead).
 * Ball (dog): waiting → running (out of frame) → fetching (back) — the app throws and
 *   brings the ball over the clip in `BALL_CLIP_FETCH_MS`.
 * Wand (cat): stalking (into the crouch) → pouncing per pounce (lands back in the crouch;
 *   between pounces the crouch pose) → catching (the last stretch, holds the catch).
 */

import { mediaKey, type PetMediaInfo, type PhaseGroup } from '@/modules/petMedia/petMedia';
import type { MediaMode, PlayItem } from '@/modules/petMedia/statePlayer';
import type { TrainingCommand, TrainingSession } from '@/modules/training/training';

/**
 * Default length of a school / game phase clip (server `media.state_media.clip_seconds`);
 * the server sends each stored clip's real length (`media.phaseMs`) — David 2026-10-23:
 * school PERFORMING clips are 5 s (older stored ones stay 3 s until regenerated).
 */
export const PHASE_CLIP_MS = 3_000;

/** Length of a stored phase clip (ms): the server's `phase_seconds`, else 3 s. */
export function phaseClipMs(media: Pick<PetMediaInfo, 'phaseMs'> | null | undefined, clip: string): number {
  const ms = media?.phaseMs?.[clip];
  return typeof ms === 'number' && ms > 0 ? ms : PHASE_CLIP_MS;
}

/** The ball's flight out and back while the run + fetch clips play (2 × 3 s). */
export const BALL_CLIP_FETCH_MS = 2 * PHASE_CLIP_MS;

export type SchoolPhase = 'attention' | 'performing' | 'success';
export type BallPhase = 'waiting' | 'running' | 'fetching';
export type WandPhase = 'stalking' | 'pouncing' | 'catching';

/** What the stage shows: the item + the still under it (the phase's pose, shown while a clip loads). */
export interface PhasePlayback {
  current: PlayItem;
  still: string | null;
}

/** A moment of a schedule: the phase clip key, its end pose and a per-instance id. */
export interface PhaseMoment {
  /** `school_sit_performing`, `ball_running`, … — null = no clip (a still pose only). */
  clip: string | null;
  /** The pose shown as a still (end frame of the clip; null = the standing reference image). */
  pose: string | null;
  /** Changes for every new instance (a new trial / pounce / throw) so a clip replays. */
  instance: string;
  /**
   * David 2026-10-23: start the clip this many ms in (a 5 s performing clip whose trial left
   * less than 5 s before the obey moment — its END, the pose, must land on time). 0 / absent = from the start.
   */
  startAtMs?: number;
}

const COMMAND_POSE: Record<TrainingCommand, string> = { sit: 'sit', come: 'come', place: 'place', potty: 'door' };

/** The pet's set has phase clips of this group (else: the caller's older stage). */
export function usesPhaseClips(media: PetMediaInfo, group: PhaseGroup): boolean {
  return media.phaseGroups.includes(group);
}

function present(url: string | null | undefined): url is string {
  return typeof url === 'string' && url.length > 0;
}

/** The pose image (null pose = the reference image). */
export function phasePoseUrl(media: PetMediaInfo, pose: string | null): string | null {
  if (pose !== null) {
    const url = media.poses[pose];
    if (present(url)) return url;
  }
  return present(media.referenceImageUrl) ? media.referenceImageUrl : null;
}

/**
 * The item for a moment: its clip (played once, keyed per instance) — else / in picture
 * mode its pose image. Never a state clip.
 */
export function phasePlayback(media: PetMediaInfo, moment: PhaseMoment, mode: MediaMode = 'video', canPlayVideo?: (url: string) => boolean): PhasePlayback {
  const still = phasePoseUrl(media, moment.pose);
  const url = moment.clip !== null ? media.phases[moment.clip] : undefined;
  if (mode === 'video' && present(url) && (canPlayVideo?.(url) ?? true)) {
    const startAt = moment.startAtMs !== undefined && moment.startAtMs > 0 ? moment.startAtMs : undefined;
    return {
      current: { kind: 'clip', url, key: `${mediaKey(url)}#${moment.instance}`, state: null, loop: false, transition: null, ...(startAt !== undefined ? { startAtMs: startAt } : {}) },
      still,
    };
  }
  return { current: still === null ? { kind: 'placeholder' } : { kind: 'image', url: still, key: mediaKey(still), state: null }, still };
}

// ── School ───────────────────────────────────────────────────

/**
 * QA M1: the `performing` clip ends this long BEFORE `obey_at_ms`, so the held end pose
 * (sitting, at the door, …) is on screen when the text says the dog obeys and during the whole
 * praise window (server: obey_at + 150 … window_end_ms); the margin covers the clip's start-up.
 */
export const PERFORMING_END_MARGIN_MS = 100;

/** Server praise window when a trial carries no `window_end_ms` (TrainingService::PRAISE_WINDOW_MS). */
const PRAISE_WINDOW_FALLBACK_MS = 1_500;

type SchoolTrial = TrainingSession['trials'][number];

function obeyAt(t: SchoolTrial | undefined): number | null {
  return t !== undefined && t.obeys && t.obey_at_ms !== null ? t.obey_at_ms : null;
}

/** Until when the previous trial keeps the stage: its praise window (obeys) or its cue (ignores). */
function holdEnd(prev: SchoolTrial | undefined): number {
  if (prev === undefined) return 0;
  const at = obeyAt(prev);
  return at === null ? prev.cue_at_ms : (prev.window_end_ms ?? at + PRAISE_WINDOW_FALLBACK_MS);
}

/**
 * QA M1: when trial `i`'s `performing` clip starts — so that it ENDS at `obey_at − margin`
 * (the dog obeys 0,8–2,5 s after the cue, so the motion begins around the cue), never before
 * the previous trial's praise window is over. null = the dog ignores this cue.
 * `clipMs` = the stored performing clip's length (3 s; 5 s since David 2026-10-23).
 */
export function performingStartMs(trials: readonly SchoolTrial[], i: number, clipMs: number = PHASE_CLIP_MS): number | null {
  const at = obeyAt(trials[i]);
  if (at === null) return null;
  return Math.max(holdEnd(trials[i - 1]), at - clipMs - PERFORMING_END_MARGIN_MS);
}

/** When trial `i` takes the stage (its `attention`): after the previous trial's hold, latest at its cue / performing. */
function trialStartMs(trials: readonly SchoolTrial[], i: number, clipMs: number): number {
  if (i === 0) return 0; // the lead-in belongs to trial 0
  const own = Math.min(trials[i].cue_at_ms, performingStartMs(trials, i, clipMs) ?? trials[i].cue_at_ms);
  return obeyAt(trials[i - 1]) !== null ? Math.min(holdEnd(trials[i - 1]), own) : own;
}

/**
 * The school phase at `elapsedMs` of a session (local game clock). Per trial: `attention`
 * (waits for the command) → `performing` (ends at the obey moment, QA M1) → `success` (holds
 * the position through the praise window, until the next trial's attention). A cue the dog
 * ignores → the standing pose from the cue on.
 */
export function schoolMomentAt(
  session: Pick<TrainingSession, 'command' | 'trials' | 'duration_ms'>,
  elapsedMs: number,
  media?: Pick<PetMediaInfo, 'phaseMs'> | null,
): PhaseMoment & { phase: SchoolPhase | null } {
  const e = Math.max(0, Math.min(elapsedMs, session.duration_ms));
  const pose = COMMAND_POSE[session.command];
  const clip = (phase: SchoolPhase) => `school_${session.command}_${phase}`;
  // David 2026-10-23: the stored performing clip's own length (5 s new, 3 s older).
  const clipMs = phaseClipMs(media, clip('performing'));
  const trials = session.trials;
  if (trials.length === 0) return { phase: 'attention', clip: clip('attention'), pose: null, instance: 'lead-in' };
  // The current trial = the last one whose stage time has begun.
  let index = 0;
  trials.forEach((_, i) => {
    if (trialStartMs(trials, i, clipMs) <= e) index = i;
  });
  const trial = trials[index];
  const start = performingStartMs(trials, index, clipMs);
  if (start !== null && e >= start) {
    // The clip must END at obey − margin; when the previous praise window left less time than
    // the clip lasts, it starts that much later INTO the clip (the pose still lands on time).
    const end = (obeyAt(trial) as number) - PERFORMING_END_MARGIN_MS;
    const skip = Math.max(0, clipMs - (end - start));
    const phase: SchoolPhase = e < Math.max(end, start + 1) ? 'performing' : 'success';
    return { phase, clip: clip(phase), pose, instance: `${index}:${phase}`, ...(phase === 'performing' && skip > 0 ? { startAtMs: skip } : {}) };
  }
  if (start === null && e >= trial.cue_at_ms) return { phase: null, clip: null, pose: null, instance: `${index}:ignores` };
  return { phase: 'attention', clip: clip('attention'), pose: null, instance: `${index}:attention` };
}

// ── Ball (dog) ───────────────────────────────────────────────

/** The ball game phase: `sinceThrowMs` = ms since the current throw (null = no ball in the air). */
export function ballMomentAt(throws: number, sinceThrowMs: number | null): PhaseMoment & { phase: BallPhase } {
  if (sinceThrowMs === null) return { phase: 'waiting', clip: 'ball_waiting', pose: null, instance: `wait:${throws}` };
  const phase: BallPhase = sinceThrowMs < PHASE_CLIP_MS ? 'running' : 'fetching';
  return { phase, clip: `ball_${phase}`, pose: phase === 'running' ? 'empty' : null, instance: `${throws}:${phase}` };
}

// ── Wand (cat) ───────────────────────────────────────────────

export interface WandScheduleLike {
  pounces_ms: readonly number[];
  duration_ms: number;
  catch_at_ms?: number;
}

/** How long before the catch the cat goes for it (matches `catGames.CATCH_LEAD_MS`). */
export const WAND_CATCH_LEAD_MS = 2_000;

/** The wand game phase at `elapsedMs`. */
export function wandMomentAt(session: WandScheduleLike, elapsedMs: number): PhaseMoment & { phase: WandPhase } {
  const e = Math.max(0, elapsedMs);
  const catchAt = session.catch_at_ms ?? session.duration_ms;
  if (e >= catchAt - WAND_CATCH_LEAD_MS) return { phase: 'catching', clip: 'wand_catching', pose: 'catch', instance: 'catch' };
  let pounce = -1;
  session.pounces_ms.forEach((p, i) => {
    if (p <= e) pounce = i;
  });
  if (pounce < 0) return { phase: 'stalking', clip: 'wand_stalking', pose: 'crouch', instance: 'stalk' };
  const since = e - session.pounces_ms[pounce];
  const next = session.pounces_ms[pounce + 1];
  if (since < PHASE_CLIP_MS && (next === undefined || e < next)) {
    return { phase: 'pouncing', clip: 'wand_pouncing', pose: 'crouch', instance: `pounce:${pounce}` };
  }
  // Between pounces she waits in the crouch the pounce landed in (a still — no new clip).
  return { phase: 'stalking', clip: null, pose: 'crouch', instance: `crouch:${pounce}` };
}
