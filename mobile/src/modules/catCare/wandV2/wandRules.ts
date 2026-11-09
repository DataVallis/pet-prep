/**
 * The cat wand hunt — the SIMPLE rule (rules 3, GAMES_SPEC §5.2–§5.4; David 2026-11-08 »naredi
 * bolj preprosto igro«, the rule designed by Claude), pure. No native import: `WandGameV2` (game
 * layer) plays it, Jest checks it with plain numbers.
 *
 * The child drags the feather; after the stalk the cat crouches with a 👀 cue. Letting go (the
 * finger lifted, or »Pusti!« in »Lažje kretnje«) ANY time while the cue shows = the cat pounces
 * and catches the feather (★). Too early or never = nothing (no ✗, no penalty): the cat loses
 * interest and crouches again. 3 catches = the hunt is over and counts.
 *
 * - **Every number comes from the session** (`first_cue_ms`, `cue_window_ms`, `after_catch_ms`,
 *   `after_miss_ms`, `max_cues`, `catches_needed`, `catch_clip_ms`, …) — nothing here is a copy of
 *   `backend/config/wand.php`. A start body without one of them is not a playable hunt
 *   (`readWandHuntSession` → null → »bad response«; never guessed).
 * - `wandTimeline` mirrors `WandPlayService::simpleTimeline` line by line (same cue order, same
 *   window bounds, same `done_ms`) — instant feedback only; the finish response is the truth.
 * - `wandHuntAt` = the timeline at a moment (which cue is showing, whether the cat is catching).
 * - `wandStageAt` = which clip role the stage shows at a moment (`wandDeck.ts`).
 */

import { readCatSessionBase, type CatSessionBase } from '@/modules/catCare/catCare';

/** The simple hunt's rules version (the server's `WandPlayService::RULES_SIMPLE`). */
export const WAND_RULES_SIMPLE = 3;

/** `session` of `wand/start {rules: 3}` (and of the child state's own running hunt). */
export interface WandHuntSession extends CatSessionBase {
  rules_version: 3;
  /** `wand_stalking` (stand → crouch) runs 0 … stalk_ms. */
  stalk_ms: number;
  /** The first 👀 cue. */
  first_cue_ms: number;
  /** How long a 👀 cue shows: a let-go in [cue, cue + window] is a catch. */
  cue_window_ms: number;
  /** A catch at r → the next cue at r + after_catch_ms. */
  after_catch_ms: number;
  /** A missed window → the next cue at its end + after_miss_ms. */
  after_miss_ms: number;
  max_cues: number;
  catches_needed: number;
  /** The hunt is over this long after the deciding let-go (the catch clip plays out). */
  catch_clip_ms: number;
}

export interface WandCueResult {
  cue_ms: number;
  caught: boolean;
  release_ms: number | null;
}

export interface WandTimeline {
  cues: WandCueResult[];
  catches: number;
  /** When the hunt is over (the finish is accepted from here on). */
  done_ms: number;
}

/** The finish `result` of a simple hunt (server), read from `unknown`. */
export interface WandHuntResult {
  session_id: string;
  rules_version: 3;
  success: boolean;
  reason: 'too_few_catches' | null;
  stars: number;
  min_stars: number;
  max_stars: number;
  cues: WandCueResult[];
  hint: 'let_go' | null;
}

/** Validation bound of one finish (`wand.simple.max_releases`, the request rule). */
export const WAND_MAX_RELEASES = 200;

// ── Readers ───────────────────────────────────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function nonNegInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.round(value) : null;
}

/** A playable simple hunt, or null. Every number must be present — the app never fills in a rule of its own. */
export function readWandHuntSession(raw: unknown): WandHuntSession | null {
  if (!isObj(raw) || raw.rules_version !== WAND_RULES_SIMPLE) return null;
  const base = readCatSessionBase(raw);
  if (base === null) return null;
  const stalk = nonNegInt(raw.stalk_ms);
  const first = nonNegInt(raw.first_cue_ms);
  const win = nonNegInt(raw.cue_window_ms);
  const afterCatch = nonNegInt(raw.after_catch_ms);
  const afterMiss = nonNegInt(raw.after_miss_ms);
  const maxCues = nonNegInt(raw.max_cues);
  const needed = nonNegInt(raw.catches_needed);
  const clip = nonNegInt(raw.catch_clip_ms);
  if (stalk === null || first === null || win === null || afterCatch === null || afterMiss === null || maxCues === null || needed === null || clip === null) return null;
  if (win < 1 || maxCues < 1 || needed < 1 || first > base.duration_ms) return null;
  return {
    ...base,
    rules_version: WAND_RULES_SIMPLE,
    stalk_ms: stalk,
    first_cue_ms: first,
    cue_window_ms: win,
    after_catch_ms: afterCatch,
    after_miss_ms: afterMiss,
    max_cues: maxCues,
    catches_needed: needed,
    catch_clip_ms: clip,
  };
}

/** A simple-hunt finish `result`, or null (a v1 result / a broken body → »bad response«). */
export function readWandHuntResult(raw: unknown): WandHuntResult | null {
  if (!isObj(raw) || raw.rules_version !== WAND_RULES_SIMPLE || typeof raw.session_id !== 'string' || typeof raw.success !== 'boolean') return null;
  const stars = nonNegInt(raw.stars);
  const minStars = nonNegInt(raw.min_stars);
  const maxStars = nonNegInt(raw.max_stars);
  if (stars === null || minStars === null || maxStars === null || !Array.isArray(raw.cues)) return null;
  const cues: WandCueResult[] = [];
  for (const c of raw.cues) {
    if (!isObj(c) || typeof c.caught !== 'boolean') return null;
    const cue = nonNegInt(c.cue_ms);
    if (cue === null) return null;
    cues.push({ cue_ms: cue, caught: c.caught, release_ms: nonNegInt(c.release_ms) });
  }
  return {
    session_id: raw.session_id,
    rules_version: WAND_RULES_SIMPLE,
    success: raw.success,
    reason: raw.success ? null : 'too_few_catches',
    stars,
    min_stars: minStars,
    max_stars: maxStars,
    cues,
    hint: raw.hint === 'let_go' ? 'let_go' : null,
  };
}

// ── The timeline (mirror of WandPlayService::simpleTimeline) ──

type TimelineRules = Pick<WandHuntSession, 'first_cue_ms' | 'cue_window_ms' | 'after_catch_ms' | 'after_miss_ms' | 'max_cues' | 'catches_needed' | 'catch_clip_ms'>;

/**
 * The hunt as it played with these let-go moments. Cue 1 at `first_cue_ms`; a cue is caught by
 * the first release in [cue, cue + window] and the next cue comes `after_catch_ms` after it; a
 * cue without one passes and the next comes `after_miss_ms` after its window; stop at
 * `catches_needed` catches or `max_cues` cues. `done_ms` = the deciding release + `catch_clip_ms`,
 * else the end of the last window.
 */
export function wandTimeline(releases: readonly number[], s: TimelineRules): WandTimeline {
  const sorted = [...releases].sort((a, b) => a - b);
  const cues: WandCueResult[] = [];
  let catches = 0;
  let cue = s.first_cue_ms;
  let done = cue + s.cue_window_ms;
  for (let i = 0; i < s.max_cues && catches < s.catches_needed; i++) {
    const hit = sorted.find((t) => t >= cue && t <= cue + s.cue_window_ms) ?? null;
    cues.push({ cue_ms: cue, caught: hit !== null, release_ms: hit });
    if (hit !== null) {
      catches++;
      done = hit + s.catch_clip_ms;
      cue = hit + s.after_catch_ms;
    } else {
      done = cue + s.cue_window_ms;
      cue += s.cue_window_ms + s.after_miss_ms;
    }
  }
  return { cues, catches, done_ms: done };
}

/** The ★ the server will give (instant feedback; the finish response replaces it). */
export function scoreWandHunt(releases: readonly number[], s: WandHuntSession): Omit<WandHuntResult, 'session_id'> {
  const tl = wandTimeline(releases, s);
  const success = tl.catches >= s.catches_needed;
  return {
    rules_version: WAND_RULES_SIMPLE,
    success,
    reason: success ? null : 'too_few_catches',
    stars: tl.catches,
    min_stars: s.catches_needed,
    max_stars: s.catches_needed,
    cues: tl.cues,
    hint: success ? null : 'let_go',
  };
}

/** Add a let-go (bounds 0…duration, ≤ `WAND_MAX_RELEASES`); the same array when dropped. */
export function addWandRelease(releases: readonly number[], t: number, durationMs: number): readonly number[] {
  const at = Math.round(t);
  if (!Number.isFinite(at) || at < 0 || at > durationMs || releases.length >= WAND_MAX_RELEASES) return releases;
  return [...releases, at];
}

// ── The hunt at a moment ──────────────────────────────────────

export type WandMoment =
  /** Before the first cue (the stalk), or between cues (the cat watches the feather). */
  | { kind: 'waiting' }
  /** A 👀 cue is showing: let go now. */
  | { kind: 'cue'; index: number; cueMs: number; endsMs: number }
  /** The cat pounced at `releaseMs` and catches the feather. */
  | { kind: 'catching'; index: number; releaseMs: number }
  /** The hunt is decided (3 catches, or every cue passed). */
  | { kind: 'over' };

export interface WandHuntState {
  /** The cues decided so far (caught, or their window passed). */
  decided: WandCueResult[];
  catches: number;
  moment: WandMoment;
}

/**
 * The hunt at `nowMs` from the let-go moments seen so far (all ≤ now). A cue is decided once it
 * is caught or its window is over; equal to `wandTimeline` of the same releases for every decided
 * cue (tested).
 */
export function wandHuntAt(s: WandHuntSession, releases: readonly number[], nowMs: number): WandHuntState {
  const tl = wandTimeline(releases.filter((t) => t <= nowMs), s);
  const decided: WandCueResult[] = [];
  let catches = 0;
  for (let i = 0; i < tl.cues.length; i++) {
    const c = tl.cues[i];
    if (c.caught && c.release_ms !== null) {
      decided.push(c);
      catches++;
      if (nowMs < c.release_ms + s.catch_clip_ms) return { decided, catches, moment: { kind: 'catching', index: i, releaseMs: c.release_ms } };
      continue;
    }
    if (nowMs < c.cue_ms) return { decided, catches, moment: { kind: 'waiting' } };
    if (nowMs <= c.cue_ms + s.cue_window_ms) return { decided, catches, moment: { kind: 'cue', index: i, cueMs: c.cue_ms, endsMs: c.cue_ms + s.cue_window_ms } };
    decided.push(c);
  }
  return { decided, catches, moment: { kind: 'over' } };
}

/** Catches whose clip has played out (the ★ is shown) — the top bar's star count. */
export function wandStars(state: WandHuntState): number {
  return state.catches - (state.moment.kind === 'catching' ? 1 : 0);
}

/** Progress dots: one per needed catch, true once caught. */
export function wandDots(state: WandHuntState, s: WandHuntSession): boolean[] {
  return Array.from({ length: s.catches_needed }, (_, i) => i < state.catches);
}

export type WandCue =
  /** A 👀 cue starts. */
  | { kind: 'crouch'; index: number }
  /** The child let go during it: the cat pounces. */
  | { kind: 'pounce'; index: number }
  /** The catch clip played: ★ »Ujela ga je!«. */
  | { kind: 'caught'; index: number }
  /** The window passed without a let-go: the cat loses interest (no ✗). */
  | { kind: 'lostInterest'; index: number };

/** What the child sees / hears / feels between two evaluations (a miss shows nothing but the cat). */
export function wandCues(prev: WandHuntState | null, next: WandHuntState): WandCue[] {
  const cues: WandCue[] = [];
  const pm = prev?.moment ?? null;
  const nm = next.moment;
  if (nm.kind === 'cue' && !(pm?.kind === 'cue' && pm.index === nm.index)) cues.push({ kind: 'crouch', index: nm.index });
  const before = prev?.decided.length ?? 0;
  next.decided.slice(before).forEach((c, k) => cues.push({ kind: c.caught ? 'pounce' : 'lostInterest', index: before + k }));
  // The ★ comes once the catch clip has played (the cat »made the kill«).
  const caughtIdx = next.decided.flatMap((c, i) => (c.caught ? [i] : []));
  for (let n = prev === null ? 0 : wandStars(prev); n < wandStars(next); n++) cues.push({ kind: 'caught', index: caughtIdx[n] });
  return cues;
}

// ── Stage and time ────────────────────────────────────────────

/** The clip roles of the hunt (`wandDeck.ts`). */
export type WandRole = 'stalking' | 'crouch' | 'catching';

/**
 * Which role the stage should show at `t` (the queue plays it as soon as the chain rules allow):
 * the stalk, the crouch loop while the cat watches / crouches, the catch from each let-go that
 * caught for `catch_clip_ms` (the ★ mask then cuts back to the crouch loop).
 */
export function wandStageAt(s: WandHuntSession, releases: readonly number[], t: number): WandRole {
  const state = wandHuntAt(s, releases, t);
  // The last catch holds its catch pose to the end (the result card comes over it).
  if (state.moment.kind === 'catching' || (state.moment.kind === 'over' && state.catches >= s.catches_needed)) return 'catching';
  if (t < s.stalk_ms) return 'stalking';
  return 'crouch';
}

/** The paw swipes over the feather this long after the let-go. */
export const WAND_PAW_AFTER_MS = 600;

/**
 * When the game should re-evaluate next after `t` (the next boundary of the timeline), so a cue
 * is never late by more than one frame of the 100 ms game tick — or null after the end.
 */
export function wandNextBoundary(s: WandHuntSession, releases: readonly number[], t: number): number | null {
  const tl = wandTimeline(releases.filter((r) => r <= t), s);
  const points: number[] = [s.stalk_ms, tl.done_ms];
  for (const c of tl.cues) {
    points.push(c.cue_ms);
    if (c.release_ms !== null) points.push(c.release_ms + WAND_PAW_AFTER_MS, c.release_ms + s.catch_clip_ms);
    else points.push(c.cue_ms + s.cue_window_ms + 1);
  }
  // The cue after the last decided one (not in the timeline until its window opens).
  const last = tl.cues[tl.cues.length - 1];
  if (last !== undefined && tl.cues.length < s.max_cues && tl.catches < s.catches_needed) {
    points.push(last.caught && last.release_ms !== null ? last.release_ms + s.after_catch_ms : last.cue_ms + s.cue_window_ms + s.after_miss_ms);
  }
  let best: number | null = null;
  for (const at of points) if (at > t && (best === null || at < best)) best = at;
  return best;
}

/**
 * Can a hunt resumed at `elapsedMs` still count? Never — the deck (every clip preloaded) must be
 * ready before the hunt clock starts, which a resume can't wait for; the intro stays and a new
 * start replaces the session on the server (no penalty).
 */
export function canWandV2StillCount(): boolean {
  return false;
}

/** The finish is sent once the hunt is over: the third catch played out, or the last window passed. */
export function wandHuntFinishAt(s: WandHuntSession, releases: readonly number[]): number {
  return Math.min(s.duration_ms, wandTimeline(releases, s).done_ms);
}
