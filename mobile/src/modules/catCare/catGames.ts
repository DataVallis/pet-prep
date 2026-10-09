/**
 * Cat mini-games (M5-R06-08a) — pure logic, unit-tested with plain numbers / fake timers.
 *
 * The server owns every schedule and every verdict (`WandPlayService::score`,
 * `CatChoreService::score`, `ScratchingService::score`). The app plays the schedule against
 * its own clock (`SessionClock` from the training game, started when the start response
 * arrived) and reports what the child did as whole ms since that local start. The scorers
 * below MIRROR the server's (same thresholds, same order of reasons) so the child gets
 * immediate, kind feedback; the server's result replaces it on finish.
 *
 * Thresholds that the session payload carries (min moves / strokes, segments, intervals,
 * pounce window, praise window, reaction floor) are read from the session; the rest are
 * mirrored constants of `backend/config/wand.php` and `config/cat_care.php` (marked below).
 */

// ── Shared ────────────────────────────────────────────────────

export interface Point {
  x: number;
  y: number;
}

function dist(a: Point, b: Point): number {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

function finite(p: Point): boolean {
  return Number.isFinite(p.x) && Number.isFinite(p.y);
}

/** Whole seconds left (rounded up) and 0–1 progress of a session at `elapsedMs`. */
export function sessionTime(durationMs: number, elapsedMs: number): { secondsLeft: number; progress: number; over: boolean } {
  const e = Math.max(0, elapsedMs);
  return {
    secondsLeft: Math.max(0, Math.ceil((durationMs - e) / 1000)),
    progress: durationMs > 0 ? Math.min(1, e / durationMs) : 1,
    over: e >= durationMs,
  };
}

/** Index of the equal part of the session that holds `ms` (server: `intdiv(t * segments, duration)`, capped). */
export function segmentOf(ms: number, durationMs: number, segments: number): number {
  const n = Math.max(1, segments);
  return Math.min(n - 1, Math.floor((Math.max(0, ms) * n) / Math.max(1, durationMs)));
}

/**
 * Times the server counts: sorted, a time closer than `intervalMs` to the previous COUNTED
 * one counts once. Returns the counted times (same rule as both server scorers).
 */
export function countedTimes(times: readonly number[], intervalMs: number): number[] {
  const out: number[] = [];
  let last: number | null = null;
  for (const t of [...times].sort((a, b) => a - b)) {
    if (last !== null && intervalMs > t - last) continue;
    out.push(t);
    last = t;
  }
  return out;
}

/** ≥ `minGaps` gaps between counted times, all within `maxSpreadMs` of each other → scripted (server "too uniform"). */
export function isTooUniform(counted: readonly number[], minGaps: number, maxSpreadMs: number): boolean {
  const gaps: number[] = [];
  for (let i = 1; i < counted.length; i++) gaps.push(counted[i] - counted[i - 1]);
  return gaps.length >= Math.max(2, minGaps) && Math.max(...gaps) - Math.min(...gaps) <= maxSpreadMs;
}

// ── Wand play ("Palica s peresom") ────────────────────────────

/** One feather move as the server scores it: ms since start, moved AWAY from the cat. */
export interface WandMove {
  t: number;
  away: boolean;
}

/** Mirrors of `backend/config/wand.php` (not in the session payload). */
/** `wand.session_seconds` (David 2026-10-08: ~60 s) — only for the intro text before a session exists. */
export const WAND_SESSION_SECONDS = 60;
export const WAND_MIN_AWAY_SHARE = 0.5;
export const WAND_UNIFORM_MIN_GAPS = 6;
export const WAND_UNIFORM_MAX_SPREAD_MS = 60;
/** Validation bound of one finish (`wand.max_moves`). */
export const WAND_MAX_MOVES = 600;

export interface WandRules {
  duration_ms: number;
  pounces_ms: readonly number[];
  min_away_moves: number;
  segments: number;
  min_move_interval_ms: number;
  pounce_window_ms: number;
}

export interface WandScore {
  success: boolean;
  reason: 'too_few_moves' | 'not_spread' | 'wrong_technique' | 'missed_pounces' | 'too_uniform' | null;
  away_moves: number;
  toward_moves: number;
  segments_hit: number;
  pounces_hit: number;
}

/** Mirror of `WandPlayService::score` (same counting, same order of reasons). */
export function scoreWand(moves: readonly WandMove[], rules: WandRules): WandScore {
  const sorted = [...moves].sort((a, b) => a.t - b.t);
  let away = 0;
  let toward = 0;
  const hit = new Set<number>();
  const awayTimes: number[] = [];
  const counted: number[] = [];
  let last: number | null = null;
  for (const move of sorted) {
    if (last !== null && rules.min_move_interval_ms > move.t - last) continue;
    last = move.t;
    counted.push(move.t);
    if (move.away) {
      away++;
      awayTimes.push(move.t);
      hit.add(segmentOf(move.t, rules.duration_ms, rules.segments));
    } else {
      toward++;
    }
  }
  const pouncesHit = rules.pounces_ms.filter((p) => awayTimes.some((t) => t >= p && t <= p + rules.pounce_window_ms)).length;
  let reason: WandScore['reason'] = null;
  if (away < Math.max(1, rules.min_away_moves)) reason = 'too_few_moves';
  else if (hit.size < Math.max(1, rules.segments)) reason = 'not_spread';
  else if (away < WAND_MIN_AWAY_SHARE * (away + toward)) reason = 'wrong_technique';
  else if (pouncesHit < rules.pounces_ms.length) reason = 'missed_pounces';
  else if (isTooUniform(counted, WAND_UNIFORM_MIN_GAPS, WAND_UNIFORM_MAX_SPREAD_MS)) reason = 'too_uniform';
  return { success: reason === null, reason, away_moves: away, toward_moves: toward, segments_hit: hit.size, pounces_hit: pouncesHit };
}

/** Add a move (bounds 0…duration, ≤ `WAND_MAX_MOVES`); returns the same array when it is dropped. */
export function addWandMove(moves: readonly WandMove[], move: WandMove, durationMs: number): readonly WandMove[] {
  const t = Math.round(move.t);
  if (!Number.isFinite(t) || t < 0 || t > durationMs || moves.length >= WAND_MAX_MOVES) return moves;
  return [...moves, { t, away: move.away }];
}

/**
 * Finger → moves. The server wants ONE move per stroke of the feather (not a sampled
 * stream — evenly sampled points would look "machine-regular"). A stroke is a stretch of
 * the drag that goes consistently away from or towards the cat; it ends when the finger
 * lifts, when the drag turns back by `WAND_REVERSAL_PT`, or after `WAND_SEGMENT_MAX_PATH`
 * of path. Its time is the moment it reached its furthest point (human speed decides the
 * gaps). A stroke that hardly changes the distance to the cat (circling) is no move.
 */
export const WAND_RADIAL_MIN_PT = 12;
export const WAND_REVERSAL_PT = 16;
export const WAND_STROKE_MIN_PATH = 24;
export const WAND_SEGMENT_MAX_PATH = 180;

interface StrokePoint extends Point {
  t: number;
  /** Distance to the cat. */
  r: number;
}

export interface WandTracker {
  start: StrokePoint;
  /** Furthest point in the stroke's direction so far. */
  extreme: StrokePoint;
  last: StrokePoint;
  /** +1 away, −1 towards, 0 not decided yet. */
  dir: 1 | -1 | 0;
  /** Path since the start / up to the extreme. */
  path: number;
  pathAtExtreme: number;
}

function strokePoint(p: Point, t: number, cat: Point): StrokePoint {
  return { x: p.x, y: p.y, t, r: dist(p, cat) };
}

export function beginWandStroke(p: Point, t: number, cat: Point): WandTracker {
  const sp = strokePoint(p, t, cat);
  return { start: sp, extreme: sp, last: sp, dir: 0, path: 0, pathAtExtreme: 0 };
}

function closeAt(tracker: WandTracker): WandMove | null {
  if (tracker.dir === 0 || tracker.pathAtExtreme < WAND_STROKE_MIN_PATH) return null;
  if (Math.abs(tracker.extreme.r - tracker.start.r) < WAND_RADIAL_MIN_PT) return null;
  return { t: tracker.extreme.t, away: tracker.dir > 0 };
}

/** One drag sample: the updated tracker and the move a finished stroke produced (or null). */
export function moveWandStroke(tracker: WandTracker, p: Point, t: number, cat: Point): { tracker: WandTracker; move: WandMove | null } {
  if (!finite(p) || !Number.isFinite(t)) return { tracker, move: null };
  const sp = strokePoint(p, t, cat);
  const path = tracker.path + dist(tracker.last, sp);
  let next: WandTracker = { ...tracker, last: sp, path };

  if (next.dir === 0) {
    const delta = sp.r - next.start.r;
    if (Math.abs(delta) >= WAND_RADIAL_MIN_PT) next = { ...next, dir: delta > 0 ? 1 : -1, extreme: sp, pathAtExtreme: path };
    else if (path >= WAND_SEGMENT_MAX_PATH) next = beginWandStroke(sp, t, cat); // circling: no move
    return { tracker: next, move: null };
  }

  if ((sp.r - next.extreme.r) * next.dir > 0) {
    next = { ...next, extreme: sp, pathAtExtreme: path };
    if (path >= WAND_SEGMENT_MAX_PATH) {
      // A long sweep in one direction: it counts, and a new stroke begins here.
      return { tracker: beginWandStroke(sp, t, cat), move: closeAt(next) };
    }
    return { tracker: next, move: null };
  }

  if ((next.extreme.r - sp.r) * next.dir >= WAND_REVERSAL_PT) {
    // Turned back: the stroke ended at its extreme; the new one runs the other way.
    const move = closeAt(next);
    const restart: WandTracker = {
      start: next.extreme,
      extreme: sp,
      last: sp,
      dir: next.dir > 0 ? -1 : 1,
      path: path - next.pathAtExtreme,
      pathAtExtreme: path - next.pathAtExtreme,
    };
    return { tracker: restart, move };
  }
  return { tracker: next, move: null };
}

/** The finger lifted: the open stroke's move (or null). */
export function endWandStroke(tracker: WandTracker, p: Point | null, t: number, cat: Point): WandMove | null {
  const last = p !== null ? moveWandStroke(tracker, p, t, cat) : { tracker, move: null };
  return last.move ?? closeAt(last.tracker);
}

/** What the cat does on screen at `elapsedMs`. */
export type CatAction = 'stalking' | 'crouching' | 'pouncing' | 'catching' | 'caught';

/** The cat crouches this long before a pounce and is "in the air" this long after it. */
export const CROUCH_MS = 700;
export const POUNCE_MS = 800;
/** The last stretch of the game: she is about to catch the feather. */
export const CATCH_LEAD_MS = 2_000;

export interface WandFrame {
  cat: CatAction;
  /** Index of the pounce whose answer window is open now (an "away" move answers it), else null. */
  pounceOpen: number | null;
  /** Quarter (segment) of the game now. */
  segment: number;
  secondsLeft: number;
  progress: number;
  over: boolean;
}

export function wandFrameAt(session: WandRules & { catch_at_ms?: number }, elapsedMs: number): WandFrame {
  const e = Math.max(0, elapsedMs);
  const time = sessionTime(session.duration_ms, e);
  const catchAt = session.catch_at_ms ?? session.duration_ms;
  let cat: CatAction = 'stalking';
  let pounceOpen: number | null = null;
  session.pounces_ms.forEach((p, i) => {
    if (e >= p && e <= p + session.pounce_window_ms) pounceOpen = i;
    if (e >= p - CROUCH_MS && e < p) cat = 'crouching';
    if (e >= p && e < p + POUNCE_MS) cat = 'pouncing';
  });
  if (e >= catchAt - CATCH_LEAD_MS) cat = 'catching';
  if (time.over || e >= catchAt) cat = 'caught';
  return { cat, pounceOpen, segment: segmentOf(Math.min(e, session.duration_ms - 1), session.duration_ms, session.segments), ...time };
}

export type PounceStatus = 'upcoming' | 'open' | 'answered' | 'missed';

/** Per pounce of the schedule: answered by an "away" move in its window, still open, missed, or not yet. */
export function pounceStatuses(session: WandRules, moves: readonly WandMove[], elapsedMs: number): PounceStatus[] {
  const counted = countedWandMoves(moves, session.min_move_interval_ms);
  return session.pounces_ms.map((p) => {
    if (counted.some((m) => m.away && m.t >= p && m.t <= p + session.pounce_window_ms)) return 'answered';
    if (elapsedMs < p) return 'upcoming';
    return elapsedMs <= p + session.pounce_window_ms ? 'open' : 'missed';
  });
}

/** The moves the server counts (≥ min interval apart). */
export function countedWandMoves(moves: readonly WandMove[], intervalMs: number): WandMove[] {
  const out: WandMove[] = [];
  let last: number | null = null;
  for (const m of [...moves].sort((a, b) => a.t - b.t)) {
    if (last !== null && intervalMs > m.t - last) continue;
    out.push(m);
    last = m.t;
  }
  return out;
}

/** Segments (quarters) that already have an "away" move — the progress dots. */
export function wandSegmentsHit(session: WandRules, moves: readonly WandMove[]): Set<number> {
  const hit = new Set<number>();
  for (const m of countedWandMoves(moves, session.min_move_interval_ms)) {
    if (m.away) hit.add(segmentOf(m.t, session.duration_ms, session.segments));
  }
  return hit;
}

/** How long the verdict of the last move stays up. */
export const WAND_FEEDBACK_MS = 1_500;

export type WandFeedback = 'away' | 'toward' | 'pounce';

/** The kind line for the newest move (≤ `WAND_FEEDBACK_MS` ago): escaped, dodged a pounce, or "pull it away". */
export function wandFeedbackAt(session: WandRules, moves: readonly WandMove[], elapsedMs: number): WandFeedback | null {
  const last = moves.length > 0 ? moves[moves.length - 1] : null;
  if (last === null || elapsedMs - last.t > WAND_FEEDBACK_MS || elapsedMs < last.t) return null;
  if (!last.away) return 'toward';
  const dodged = session.pounces_ms.some((p) => last.t >= p && last.t <= p + session.pounce_window_ms);
  return dodged ? 'pounce' : 'away';
}

// ── Stroke games (grooming, weekly litter change) ─────────────

/** Mirrors of `backend/config/cat_care.php` (not in the session payload). */
export const CHORE_UNIFORM_MIN_GAPS = 8;
export const CHORE_UNIFORM_MAX_SPREAD_MS = 40;
export const CHORE_MAX_STROKES = 600;

export interface ChoreRules {
  duration_ms: number;
  min_strokes: number;
  segments: number;
  min_stroke_interval_ms: number;
}

export interface ChoreScore {
  success: boolean;
  reason: 'too_few_strokes' | 'not_spread' | 'too_uniform' | null;
  strokes: number;
  segments_hit: number;
}

/** Mirror of `CatChoreService::score`. */
export function scoreStrokes(times: readonly number[], rules: ChoreRules): ChoreScore {
  const counted = countedTimes(times, rules.min_stroke_interval_ms);
  const hit = new Set(counted.map((t) => segmentOf(t, rules.duration_ms, rules.segments)));
  let reason: ChoreScore['reason'] = null;
  if (counted.length < Math.max(1, rules.min_strokes)) reason = 'too_few_strokes';
  else if (hit.size < Math.max(1, rules.segments)) reason = 'not_spread';
  else if (isTooUniform(counted, CHORE_UNIFORM_MIN_GAPS, CHORE_UNIFORM_MAX_SPREAD_MS)) reason = 'too_uniform';
  return { success: reason === null, reason, strokes: counted.length, segments_hit: hit.size };
}

/** Add a stroke time (bounds 0…duration, ≤ `CHORE_MAX_STROKES`); same array when dropped. */
export function addStroke(strokes: readonly number[], ms: number, durationMs: number): readonly number[] {
  const t = Math.round(ms);
  if (!Number.isFinite(t) || t < 0 || t > durationMs || strokes.length >= CHORE_MAX_STROKES) return strokes;
  return [...strokes, t];
}

/** Live progress: strokes the server will count and which parts (thirds) have one. */
export function choreProgress(rules: ChoreRules, strokes: readonly number[]): { strokes: number; segmentsHit: Set<number> } {
  const counted = countedTimes(strokes, rules.min_stroke_interval_ms);
  return { strokes: counted.length, segmentsHit: new Set(counted.map((t) => segmentOf(t, rules.duration_ms, rules.segments))) };
}

/**
 * Finger → strokes for brushing / scrubbing. A stroke is one lift of the finger after
 * ≥ `CHORE_STROKE_MIN_PT` of travel, or — while rubbing back and forth without lifting —
 * every turn after ≥ `CHORE_STROKE_MIN_PT` out and `CHORE_TURN_PT` back. Its time is the
 * turning point / the lift (the child's own rhythm decides the gaps).
 */
export const CHORE_STROKE_MIN_PT = 40;
export const CHORE_TURN_PT = 20;

export interface RubTracker {
  start: Point & { t: number };
  /** Point furthest from `start` so far. */
  far: Point & { t: number; d: number };
  path: number;
  last: Point;
}

export function beginRub(p: Point, t: number): RubTracker {
  return { start: { ...p, t }, far: { ...p, t, d: 0 }, path: 0, last: p };
}

/** One rub sample: updated tracker and the stroke time a turn produced (or null). */
export function moveRub(tracker: RubTracker, p: Point, t: number): { tracker: RubTracker; stroke: number | null } {
  if (!finite(p) || !Number.isFinite(t)) return { tracker, stroke: null };
  const path = tracker.path + dist(tracker.last, p);
  const d = dist(tracker.start, p);
  let next: RubTracker = { ...tracker, path, last: p };
  if (d > next.far.d) next = { ...next, far: { ...p, t, d } };
  if (next.far.d >= CHORE_STROKE_MIN_PT && dist(next.far, p) >= CHORE_TURN_PT && d < next.far.d - CHORE_TURN_PT / 2) {
    const stroke = next.far.t;
    const restartFrom = next.far;
    const restart: RubTracker = { start: { x: restartFrom.x, y: restartFrom.y, t: restartFrom.t }, far: { ...p, t, d: dist(restartFrom, p) }, path: dist(restartFrom, p), last: p };
    return { tracker: restart, stroke };
  }
  return { tracker: next, stroke: null };
}

/** The finger lifted: a stroke at `t` when the open rub went far enough. */
export function endRub(tracker: RubTracker, t: number): number | null {
  return tracker.far.d >= CHORE_STROKE_MIN_PT || tracker.path >= CHORE_STROKE_MIN_PT ? t : null;
}

/** Step 1…3 of the stroke game at `elapsedMs` (the server's thirds). */
export function choreStepAt(rules: ChoreRules, elapsedMs: number): 1 | 2 | 3 {
  const s = segmentOf(Math.min(elapsedMs, rules.duration_ms - 1), rules.duration_ms, 3);
  return s === 0 ? 1 : s === 1 ? 2 : 3;
}

// ── Scratching ("Na praskalnik" + praise in 3 s) ──────────────

export interface ScratchingRules {
  land_at_ms: number;
  praise_window_ms: number;
  min_reaction_ms: number;
}

export interface ScratchingScore {
  success: boolean;
  reason: 'no_praise' | 'too_early' | 'too_late' | null;
  delay_ms: number | null;
}

/** Mirror of `ScratchingService::score`: in time = [landing + reaction floor, landing + window]. */
export function scoreScratching(praiseMs: number | null, rules: ScratchingRules): ScratchingScore {
  const delay = praiseMs === null ? null : praiseMs - rules.land_at_ms;
  let reason: ScratchingScore['reason'] = null;
  if (delay === null) reason = 'no_praise';
  else if (delay < rules.min_reaction_ms) reason = 'too_early';
  else if (delay > rules.praise_window_ms) reason = 'too_late';
  return { success: reason === null, reason, delay_ms: delay };
}

export type ScratchingPhase = 'carrying' | 'landed' | 'over';

export function scratchingFrameAt(rules: ScratchingRules, elapsedMs: number): { phase: ScratchingPhase; carry: number } {
  const e = Math.max(0, elapsedMs);
  const carry = rules.land_at_ms > 0 ? Math.min(1, e / rules.land_at_ms) : 1;
  if (e < rules.land_at_ms) return { phase: 'carrying', carry };
  return { phase: e <= rules.land_at_ms + rules.praise_window_ms ? 'landed' : 'over', carry: 1 };
}

/**
 * When the app sends the finish (ms on its clock): right after the first praise — but never
 * before the landing (the server refuses a finish before it, `care_session_not_over`) —
 * or, without a praise, when the window has closed (the server then says "no praise").
 * The FIRST praise decides, like a training cue: tapping early and again later is "too early".
 */
export function scratchingFinishAt(rules: ScratchingRules, praiseMs: number | null): number {
  return praiseMs === null ? rules.land_at_ms + rules.praise_window_ms : Math.max(praiseMs, rules.land_at_ms);
}
