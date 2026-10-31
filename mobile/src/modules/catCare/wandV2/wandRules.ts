/**
 * Wand v2 — one ~25 s hunt scored in stars (GAMES_SPEC §5.2–§5.4, David 2026-10-30 P1 / P2;
 * strict calm rule David 2026-10-31), pure. No native import: `WandGameV2` (game layer) plays it,
 * Jest checks it with plain numbers.
 *
 * - **Every timing comes from the session** (`crouch_cues_ms`, `calm_window_ms`, `calm_max_moves`,
 *   `dodge_window_ms`, `catch_at_ms`, `catch_window_ms`, `max_speed_pt_s`, `min_dodge_pt`, …) —
 *   nothing here is a copy of `backend/config/wand.php`. A start body without one of them is not
 *   a playable v2 session (`readWandV2Session` → null → »bad response«; never guessed).
 * - `scoreWandV2` mirrors `WandPlayService::scoreV2` line by line (same merge, same reason order,
 *   same hint) for INSTANT feedback only; the finish response is the truth (result screen).
 * - `wandLive` = the mirror at a moment of the hunt: which pounce / the catch is decided already
 *   (a verdict can't change any more), which is still open — the stars and cues come from it.
 * - `wandStageAt` = which clip role the stage should show at a moment (`wandDeck.ts`).
 */

import { readCatSessionBase, type CatSessionBase } from '@/modules/catCare/catCare';

export const WAND_RULES_V2 = 2;

/** `session` of `wand/start {rules: 2}` (and of the child state's own running v2 session). */
export interface WandV2Session extends CatSessionBase {
  rules_version: 2;
  /** `wand_stalking` (stand → crouch) runs 0 … stalk_ms. */
  stalk_ms: number;
  /** Pounce instants (sorted). */
  pounces_ms: number[];
  /** 👀 cue per pounce (same order): the feather must come to rest. */
  crouch_cues_ms: number[];
  catch_at_ms: number;
  catch_window_ms: number;
  /** At most `calm_max_moves` counted moves in [p − calm_window_ms, p). */
  calm_window_ms: number;
  calm_max_moves: number;
  /** The first away move after p must lie in [p + from, p + to]. */
  dodge_window_ms: readonly [number, number];
  /** Calm count only: a move closer than this to the previous counted one counts once. */
  min_move_interval_ms: number;
  min_stars: number;
  max_stars: number;
  /** What the app checks for `speed_ok`. */
  max_speed_pt_s: number;
  min_dodge_pt: number;
}

/** One feather move as the v2 server scores it (ms since the local start). */
export interface WandV2Move {
  t: number;
  away: boolean;
  /** The stroke ended inside the play zone. */
  in_zone: boolean;
  /** Peak ≤ `max_speed_pt_s` and length ≥ `min_dodge_pt`. */
  speed_ok: boolean;
}

export type DodgeReason = 'moved_during_crouch' | 'too_early' | 'no_dodge' | 'out_of_zone' | 'bad_stroke';
export type CatchReason = 'pulled_away' | 'out_of_zone';
export type WandHint = 'calm' | 'dodge' | 'catch';

export interface WandDodge {
  pounce_ms: number;
  star: boolean;
  reason: DodgeReason | null;
  move_ms: number | null;
}

export interface WandV2Score {
  success: boolean;
  reason: 'too_few_stars' | null;
  stars: number;
  min_stars: number;
  max_stars: number;
  dodges: WandDodge[];
  catch: { star: boolean; reason: CatchReason | null };
  hint: WandHint | null;
}

/** The v2 finish `result` (server), read from `unknown`. */
export interface WandV2Result extends WandV2Score {
  session_id: string;
  rules_version: 2;
}

/** Validation bound of one finish (`wand.max_moves`, the request rule). */
export const WAND_V2_MAX_MOVES = 600;

// ── Readers ───────────────────────────────────────────────────

type Obj = Record<string, unknown>;

function isObj(value: unknown): value is Obj {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function int(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? Math.round(value) : null;
}

function nonNegInt(value: unknown): number | null {
  const n = int(value);
  return n !== null && n >= 0 ? n : null;
}

function intList(value: unknown): number[] | null {
  if (!Array.isArray(value)) return null;
  const out: number[] = [];
  for (const item of value) {
    const n = nonNegInt(item);
    if (n === null) return null;
    out.push(n);
  }
  return out;
}

/**
 * A playable v2 hunt, or null. Every timing must be present and consistent — the app never
 * fills in a rule of its own (David 2026-10-31: the calm rule is server config).
 */
export function readWandV2Session(raw: unknown): WandV2Session | null {
  if (!isObj(raw) || raw.rules_version !== WAND_RULES_V2) return null;
  const base = readCatSessionBase(raw);
  if (base === null) return null;
  const pounces = intList(raw.pounces_ms);
  const cues = intList(raw.crouch_cues_ms);
  const dodge = intList(raw.dodge_window_ms);
  const numbers = {
    stalk: nonNegInt(raw.stalk_ms),
    catchAt: nonNegInt(raw.catch_at_ms),
    catchWindow: nonNegInt(raw.catch_window_ms),
    calmWindow: nonNegInt(raw.calm_window_ms),
    calmMax: nonNegInt(raw.calm_max_moves),
    interval: nonNegInt(raw.min_move_interval_ms),
    minStars: nonNegInt(raw.min_stars),
    maxStars: nonNegInt(raw.max_stars),
    maxSpeed: nonNegInt(raw.max_speed_pt_s),
    minDodge: nonNegInt(raw.min_dodge_pt),
  };
  if (pounces === null || cues === null || dodge === null || dodge.length !== 2 || pounces.length === 0 || cues.length !== pounces.length) return null;
  const n = numbers;
  if (
    n.stalk === null ||
    n.catchAt === null ||
    n.catchWindow === null ||
    n.calmWindow === null ||
    n.calmMax === null ||
    n.interval === null ||
    n.minStars === null ||
    n.maxStars === null ||
    n.maxSpeed === null ||
    n.minDodge === null
  ) {
    return null;
  }
  const order = pounces.map((p, i) => ({ p, cue: cues[i] })).sort((a, b) => a.p - b.p);
  if (order.some(({ p, cue }) => cue > p || p > base.duration_ms) || n.catchAt > base.duration_ms || dodge[0] > dodge[1] || n.minStars < 1 || n.maxSpeed < 1) {
    return null;
  }
  return {
    ...base,
    rules_version: WAND_RULES_V2,
    stalk_ms: n.stalk,
    pounces_ms: order.map((o) => o.p),
    crouch_cues_ms: order.map((o) => o.cue),
    catch_at_ms: n.catchAt,
    catch_window_ms: n.catchWindow,
    calm_window_ms: n.calmWindow,
    calm_max_moves: n.calmMax,
    dodge_window_ms: [dodge[0], dodge[1]],
    min_move_interval_ms: n.interval,
    min_stars: n.minStars,
    max_stars: n.maxStars,
    max_speed_pt_s: n.maxSpeed,
    min_dodge_pt: n.minDodge,
  };
}

const DODGE_REASONS: readonly DodgeReason[] = ['moved_during_crouch', 'too_early', 'no_dodge', 'out_of_zone', 'bad_stroke'];
const CATCH_REASONS: readonly CatchReason[] = ['pulled_away', 'out_of_zone'];
const HINTS: readonly WandHint[] = ['calm', 'dodge', 'catch'];

function oneOf<T extends string>(value: unknown, list: readonly T[]): T | null {
  return typeof value === 'string' && (list as readonly string[]).includes(value) ? (value as T) : null;
}

/** A v2 finish `result`, or null (a v1 result / a broken body → »bad response«). */
export function readWandV2Result(raw: unknown): WandV2Result | null {
  if (!isObj(raw) || raw.rules_version !== WAND_RULES_V2 || typeof raw.session_id !== 'string' || typeof raw.success !== 'boolean') return null;
  const stars = nonNegInt(raw.stars);
  const minStars = nonNegInt(raw.min_stars);
  const maxStars = nonNegInt(raw.max_stars);
  if (stars === null || minStars === null || maxStars === null || !Array.isArray(raw.dodges) || !isObj(raw.catch)) return null;
  const dodges: WandDodge[] = [];
  for (const d of raw.dodges) {
    if (!isObj(d) || typeof d.star !== 'boolean') return null;
    const pounce = nonNegInt(d.pounce_ms);
    if (pounce === null) return null;
    dodges.push({ pounce_ms: pounce, star: d.star, reason: d.star ? null : oneOf(d.reason, DODGE_REASONS), move_ms: nonNegInt(d.move_ms) });
  }
  const catchStar = raw.catch.star === true;
  return {
    session_id: raw.session_id,
    rules_version: WAND_RULES_V2,
    success: raw.success,
    reason: raw.success ? null : 'too_few_stars',
    stars,
    min_stars: minStars,
    max_stars: maxStars,
    dodges,
    catch: { star: catchStar, reason: catchStar ? null : oneOf(raw.catch.reason, CATCH_REASONS) },
    hint: oneOf(raw.hint, HINTS),
  };
}

// ── Scorer mirror (WandPlayService::scoreV2) ──────────────────

/** Sorted by time (stable, like PHP 8's usort). */
function sortedMoves(moves: readonly WandV2Move[]): WandV2Move[] {
  return [...moves].sort((a, b) => a.t - b.t);
}

/** Moves the CALM rule counts: one closer than `interval` to the previous counted one counts once. */
export function countedV2Moves(sorted: readonly WandV2Move[], intervalMs: number): WandV2Move[] {
  const out: WandV2Move[] = [];
  let last: number | null = null;
  for (const m of sorted) {
    if (last !== null && intervalMs > m.t - last) continue;
    last = m.t;
    out.push(m);
  }
  return out;
}

function dodgeOf(p: number, raw: readonly WandV2Move[], counted: readonly WandV2Move[], s: WandV2Session): WandDodge {
  const calmMoves = counted.filter((m) => m.t >= p - s.calm_window_ms && m.t < p).length;
  const first = raw.find((m) => m.away && m.t >= p) ?? null;
  const [from, to] = s.dodge_window_ms;
  const inTime = first !== null && first.t >= p + from && first.t <= p + to;
  let reason: DodgeReason | null = null;
  if (calmMoves > s.calm_max_moves) reason = 'moved_during_crouch';
  else if (first !== null && first.t < p + from) reason = 'too_early';
  else if (!inTime || first === null) reason = 'no_dodge';
  else if (!first.in_zone) reason = 'out_of_zone';
  else if (!first.speed_ok) reason = 'bad_stroke';
  return { pounce_ms: p, star: reason === null, reason, move_ms: inTime && first !== null ? first.t : null };
}

function catchOf(raw: readonly WandV2Move[], s: WandV2Session): { star: boolean; reason: CatchReason | null } {
  const end = s.catch_at_ms + s.catch_window_ms;
  let pulled = false;
  let lastBefore: WandV2Move | null = null;
  for (const m of raw) {
    if (m.t > end) break;
    lastBefore = m;
    if (m.away && m.t >= s.catch_at_ms) pulled = true;
  }
  const reason: CatchReason | null = pulled ? 'pulled_away' : lastBefore !== null && !lastBefore.in_zone ? 'out_of_zone' : null;
  return { star: reason === null, reason };
}

/** Mirror of `WandPlayService::scoreV2` (same merge, same reason order, same hint). */
export function scoreWandV2(moves: readonly WandV2Move[], s: WandV2Session): WandV2Score {
  const raw = sortedMoves(moves);
  const counted = countedV2Moves(raw, s.min_move_interval_ms);
  const failures: { at: number; hint: WandHint }[] = [];
  let stars = 0;
  const dodges = s.pounces_ms.map((p) => {
    const d = dodgeOf(p, raw, counted, s);
    if (d.star) stars++;
    else failures.push({ at: p, hint: d.reason === 'moved_during_crouch' ? 'calm' : 'dodge' });
    return d;
  });
  const caught = catchOf(raw, s);
  if (caught.star) stars++;
  else failures.push({ at: s.catch_at_ms, hint: 'catch' });
  failures.sort((a, b) => a.at - b.at);
  const success = stars >= s.min_stars;
  return {
    success,
    reason: success ? null : 'too_few_stars',
    stars,
    min_stars: s.min_stars,
    max_stars: s.pounces_ms.length + 1,
    dodges,
    catch: caught,
    hint: failures[0]?.hint ?? null,
  };
}

/** Add a move (bounds 0…duration, ≤ `WAND_V2_MAX_MOVES`); the same array when dropped. */
export function addWandV2Move(moves: readonly WandV2Move[], move: WandV2Move, durationMs: number): readonly WandV2Move[] {
  const t = Math.round(move.t);
  if (!Number.isFinite(t) || t < 0 || t > durationMs || moves.length >= WAND_V2_MAX_MOVES) return moves;
  return [...moves, { t, away: move.away, in_zone: move.in_zone, speed_ok: move.speed_ok }];
}

// ── The hunt at a moment (instant feedback) ───────────────────

export type PounceLive =
  | { state: 'upcoming' }
  /** 👀 cue … pounce: `calmBroken` = the feather already moved inside the calm window. */
  | { state: 'cue'; calmBroken: boolean; calmFrom: number }
  /** Pounced, no verdict yet (waiting for the dodge). */
  | { state: 'open' }
  | { state: 'done'; star: boolean; reason: DodgeReason | null };

export type CatchLive = { state: 'upcoming' } | { state: 'open' } | { state: 'done'; star: boolean; reason: CatchReason | null };

export interface WandLive {
  pounces: PounceLive[];
  catch: CatchLive;
  /** Stars decided so far (display only — the server's result replaces them). */
  stars: number;
}

/**
 * The mirror at `nowMs`, given the moves seen so far (all ≤ now). A verdict is `done` only when
 * no later move can change it: the counted set is prefix-determined, the dodge is the FIRST away
 * move after p, the catch ends with its window. The `done` verdicts equal `scoreWandV2` of the
 * same moves (tested).
 */
export function wandLive(s: WandV2Session, moves: readonly WandV2Move[], nowMs: number): WandLive {
  const raw = sortedMoves(moves.filter((m) => m.t <= nowMs));
  const counted = countedV2Moves(raw, s.min_move_interval_ms);
  const [, to] = s.dodge_window_ms;
  let stars = 0;
  const pounces = s.pounces_ms.map((p, i): PounceLive => {
    const cue = s.crouch_cues_ms[i];
    const calmFrom = p - s.calm_window_ms;
    const calmMoves = counted.filter((m) => m.t >= calmFrom && m.t < Math.min(p, nowMs + 1)).length;
    if (nowMs < cue) return { state: 'upcoming' };
    if (nowMs < p) return { state: 'cue', calmBroken: calmMoves > s.calm_max_moves, calmFrom };
    const first = raw.find((m) => m.away && m.t >= p);
    const decided = calmMoves > s.calm_max_moves || first !== undefined || nowMs > p + to;
    if (!decided) return { state: 'open' };
    const d = dodgeOf(p, raw, counted, s);
    if (d.star) stars++;
    return { state: 'done', star: d.star, reason: d.reason };
  });
  let caught: CatchLive;
  if (nowMs < s.catch_at_ms) caught = { state: 'upcoming' };
  else {
    const pulled = raw.some((m) => m.away && m.t >= s.catch_at_ms);
    if (pulled) caught = { state: 'done', star: false, reason: 'pulled_away' };
    else if (nowMs <= s.catch_at_ms + s.catch_window_ms) caught = { state: 'open' };
    else caught = { state: 'done', ...catchOf(raw, s) };
  }
  if (caught.state === 'done' && caught.star) stars++;
  return { pounces, catch: caught, stars };
}

/** Progress dots: one per pounce + the catch, true once decided. */
export function wandDots(live: WandLive): boolean[] {
  return [...live.pounces.map((p) => p.state === 'done'), live.catch.state === 'done'];
}

export type WandCue =
  | { kind: 'crouch'; pounce: number }
  | { kind: 'pounce'; pounce: number }
  | { kind: 'dodged'; pounce: number }
  | { kind: 'grabbed'; pounce: number }
  | { kind: 'calmBroken'; pounce: number }
  | { kind: 'catchCue' }
  | { kind: 'caught' };

/** What the child sees / hears / feels between two evaluations (never anything for a miss but the picture). */
export function wandCues(prev: WandLive | null, next: WandLive): WandCue[] {
  const cues: WandCue[] = [];
  next.pounces.forEach((p, i) => {
    const before = prev?.pounces[i] ?? { state: 'upcoming' as const };
    if (before.state === 'upcoming' && p.state !== 'upcoming') cues.push({ kind: 'crouch', pounce: i });
    if (p.state === 'cue' && p.calmBroken && !(before.state === 'cue' && before.calmBroken)) cues.push({ kind: 'calmBroken', pounce: i });
    if ((before.state === 'upcoming' || before.state === 'cue') && (p.state === 'open' || p.state === 'done')) cues.push({ kind: 'pounce', pounce: i });
    if (before.state !== 'done' && p.state === 'done') cues.push({ kind: p.star ? 'dodged' : 'grabbed', pounce: i });
  });
  const c = next.catch;
  const cb = prev?.catch ?? { state: 'upcoming' as const };
  if (cb.state === 'upcoming' && c.state !== 'upcoming') cues.push({ kind: 'catchCue' });
  if (cb.state !== 'done' && c.state === 'done' && c.star) cues.push({ kind: 'caught' });
  return cues;
}

// ── Stage and time ────────────────────────────────────────────

/** The clip roles of the hunt (`wandDeck.ts`). */
export type WandRole = 'stalking' | 'crouch' | 'pounce' | 'catching';

/**
 * Which role the stage should show at `t` (the queue plays it as soon as the chain rules allow):
 * the stalk, the crouch loop between pounces, the pounce clip from each pounce for its stored
 * length (`pounceClipMs`), the catch from `catch_at_ms` to the end.
 */
export function wandStageAt(s: WandV2Session, t: number, pounceClipMs: number): WandRole {
  if (t >= s.catch_at_ms) return 'catching';
  if (t < s.stalk_ms) return 'stalking';
  if (s.pounces_ms.some((p) => t >= p && t < p + pounceClipMs)) return 'pounce';
  return 'crouch';
}

/** The paw swipes over the feather this long after the pounce (GAMES_SPEC §5.2: p + 600). */
export const WAND_PAW_AFTER_MS = 600;
/** The grabbed feather shakes in the paw this long (no ✗, no text — §5.2). */
export const WAND_GRAB_SHAKE_MS = 800;

/**
 * When the game should re-evaluate next after `t` (the next boundary of the schedule), so a cue
 * is never late by more than one frame of the 100 ms game tick — or null after the end.
 */
export function wandNextBoundary(s: WandV2Session, t: number, pounceClipMs: number): number | null {
  const [from, to] = s.dodge_window_ms;
  const points = [
    s.stalk_ms,
    ...s.crouch_cues_ms,
    ...s.pounces_ms.flatMap((p) => [p, p + from, p + WAND_PAW_AFTER_MS, p + to + 1, p + pounceClipMs]),
    s.catch_at_ms,
    s.catch_at_ms + s.catch_window_ms + 1,
    s.duration_ms,
  ];
  let best: number | null = null;
  for (const at of points) if (at > t && (best === null || at < best)) best = at;
  return best;
}

/**
 * QA M1 analogue: can a v2 hunt resumed at `elapsedMs` still count? Never — the deck (every
 * clip preloaded) must be ready before the hunt clock starts, which a resume can't wait for;
 * the intro stays and a new start replaces the session on the server (no penalty). Its time
 * already over → `useCatSessionGame` still closes it quietly (frees the cat for siblings).
 */
export function canWandV2StillCount(): boolean {
  return false;
}

/** The finish is sent once the catch clip has played out (the server accepts it from catch + window). */
export function wandV2FinishAt(s: WandV2Session): number {
  return Math.max(s.duration_ms, s.catch_at_ms + s.catch_window_ms);
}
