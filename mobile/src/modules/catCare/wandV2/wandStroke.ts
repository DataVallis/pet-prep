/**
 * Wand v2 — finger → feather moves (GAMES_SPEC §5.3), pure (Jest-tested with plain numbers).
 *
 * The same stroke segmentation as v1 (`catGames.ts#moveWandStroke`: ONE move per stroke of the
 * feather — a stretch that goes consistently away from / towards the cat, ending at a lift or a
 * turn back of `WAND_REVERSAL_PT`; its time = the moment it reached its furthest point) — except
 * that a long sweep is never split (v1 split it every `WAND_SEGMENT_MAX_PATH`), plus what v2
 * reports per move:
 * - `in_zone` — the stroke ENDED (its furthest point) inside the play zone (`wandZone`): a swipe
 *   off the screen never counts;
 * - `speed_ok` — its peak speed (the gesture's velocity, pt/s) ≤ the session's `max_speed_pt_s`
 *   and its path ≥ the session's `min_dodge_pt` (both from the session, never constants here).
 */

import {
  WAND_RADIAL_MIN_PT,
  WAND_REVERSAL_PT,
  WAND_SEGMENT_MAX_PATH,
  WAND_STROKE_MIN_PATH,
  type Point,
} from '@/modules/catCare/catGames';
import type { WandV2Move, WandV2Session } from '@/modules/catCare/wandV2/wandRules';

export interface ZoneRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

/**
 * The play zone: the upper part of the screen where the cat looks (under the top bar, above
 * the hand's third — GAMES_SPEC §3.1 / §5.2), drawn as a light ring.
 */
export function wandZone(size: { width: number; height: number }): ZoneRect {
  'worklet';
  return { x: size.width * 0.07, y: size.height * 0.15, width: size.width * 0.86, height: size.height * 0.45 };
}

/** The cat's anchor on the full-screen video (the reference for »away«). */
export function wandCatAt(size: { width: number; height: number }): Point {
  'worklet';
  return { x: size.width / 2, y: size.height * 0.8 };
}

export function inZone(p: Point, zone: ZoneRect): boolean {
  'worklet';
  return p.x >= zone.x && p.x <= zone.x + zone.width && p.y >= zone.y && p.y <= zone.y + zone.height;
}

/** The feather floats this far above the finger, so the thumb never hides it. */
export const FEATHER_LIFT_PT = 56;

/** Where the feather is for a finger at `finger` (pt on the field). */
export function featherForFinger(finger: Point): Point {
  'worklet';
  return { x: finger.x, y: finger.y - FEATHER_LIFT_PT };
}

interface Sample extends Point {
  t: number;
  /** Distance to the cat. */
  r: number;
}

export interface StrokeTracker {
  start: Sample;
  extreme: Sample;
  last: Sample;
  dir: 1 | -1 | 0;
  path: number;
  pathAtExtreme: number;
  /** Peak speed since the stroke began / up to its extreme (pt/s). */
  peak: number;
  peakAtExtreme: number;
}

/** A finished stroke: the move's time and direction plus what v2 checks. */
export interface WandStroke {
  t: number;
  away: boolean;
  end: Point;
  lengthPt: number;
  peakPtS: number;
}

function dist(a: Point, b: Point): number {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

function sample(p: Point, t: number, cat: Point): Sample {
  return { x: p.x, y: p.y, t, r: dist(p, cat) };
}

function speedOf(v: number): number {
  return Number.isFinite(v) && v > 0 ? v : 0;
}

export function beginStroke(p: Point, t: number, cat: Point, speed = 0): StrokeTracker {
  const s = sample(p, t, cat);
  const v = speedOf(speed);
  return { start: s, extreme: s, last: s, dir: 0, path: 0, pathAtExtreme: 0, peak: v, peakAtExtreme: v };
}

function close(tr: StrokeTracker): WandStroke | null {
  if (tr.dir === 0 || tr.pathAtExtreme < WAND_STROKE_MIN_PATH) return null;
  if (Math.abs(tr.extreme.r - tr.start.r) < WAND_RADIAL_MIN_PT) return null;
  return { t: tr.extreme.t, away: tr.dir > 0, end: { x: tr.extreme.x, y: tr.extreme.y }, lengthPt: tr.pathAtExtreme, peakPtS: tr.peakAtExtreme };
}

/** One drag sample (`speed` = the gesture's velocity there): the tracker and a finished stroke, if any. */
export function moveStroke(tr: StrokeTracker, p: Point, t: number, cat: Point, speed: number): { tracker: StrokeTracker; stroke: WandStroke | null } {
  if (!Number.isFinite(p.x) || !Number.isFinite(p.y) || !Number.isFinite(t)) return { tracker: tr, stroke: null };
  const s = sample(p, t, cat);
  const v = speedOf(speed);
  const path = tr.path + dist(tr.last, s);
  let next: StrokeTracker = { ...tr, last: s, path, peak: Math.max(tr.peak, v) };

  if (next.dir === 0) {
    const delta = s.r - next.start.r;
    if (Math.abs(delta) >= WAND_RADIAL_MIN_PT) next = { ...next, dir: delta > 0 ? 1 : -1, extreme: s, pathAtExtreme: path, peakAtExtreme: next.peak };
    else if (path >= WAND_SEGMENT_MAX_PATH) next = beginStroke(s, t, cat, v); // circling: no move
    return { tracker: next, stroke: null };
  }

  if ((s.r - next.extreme.r) * next.dir > 0) {
    // Unlike v1, a long sweep is NOT split every `WAND_SEGMENT_MAX_PATH`: the stroke runs to its
    // lift / turn, so a swipe off the screen ends off the screen (its first chunk never counts).
    next = { ...next, extreme: s, pathAtExtreme: path, peakAtExtreme: next.peak };
    return { tracker: next, stroke: null };
  }

  if ((next.extreme.r - s.r) * next.dir >= WAND_REVERSAL_PT) {
    const stroke = close(next);
    const restart: StrokeTracker = {
      start: next.extreme,
      extreme: s,
      last: s,
      dir: next.dir > 0 ? -1 : 1,
      path: path - next.pathAtExtreme,
      pathAtExtreme: path - next.pathAtExtreme,
      peak: v,
      peakAtExtreme: v,
    };
    return { tracker: restart, stroke };
  }
  return { tracker: next, stroke: null };
}

/** The finger lifted: the open stroke, if it was one. */
export function endStroke(tr: StrokeTracker, p: Point | null, t: number, cat: Point, speed: number): WandStroke | null {
  const last = p !== null ? moveStroke(tr, p, t, cat, speed) : { tracker: tr, stroke: null };
  return last.stroke ?? close(last.tracker);
}

/** A stroke → the move the server scores (zone and speed / length checked against the SESSION). */
export function strokeToMove(stroke: WandStroke, zone: ZoneRect, s: Pick<WandV2Session, 'max_speed_pt_s' | 'min_dodge_pt'>): WandV2Move {
  return {
    t: Math.round(stroke.t),
    away: stroke.away,
    in_zone: inZone(stroke.end, zone),
    speed_ok: stroke.peakPtS <= s.max_speed_pt_s && stroke.lengthPt >= s.min_dodge_pt,
  };
}

/**
 * »Lažje kretnje« / screen reader (§5.4 Dostopnost): the feather floats by itself and reports
 * NOTHING (no move = calm); »Umakni!« is one clean away move inside the zone — the same
 * `{t, away, in_zone, speed_ok}` a good swipe produces, at the child's own moment.
 */
export function dodgeButtonMove(t: number): WandV2Move {
  return { t: Math.round(t), away: true, in_zone: true, speed_ok: true };
}

/** Fixed float path of the feather in hold mode (fractions of the zone; no randomness). */
export const FLOAT_POINTS: readonly Point[] = [
  { x: 0.5, y: 0.55 },
  { x: 0.3, y: 0.35 },
  { x: 0.68, y: 0.3 },
  { x: 0.78, y: 0.6 },
  { x: 0.4, y: 0.7 },
  { x: 0.22, y: 0.5 },
];
export const FLOAT_STEP_MS = 1_100;

export function floatPoint(zone: ZoneRect, i: number): Point {
  const f = FLOAT_POINTS[((i % FLOAT_POINTS.length) + FLOAT_POINTS.length) % FLOAT_POINTS.length];
  return { x: zone.x + f.x * zone.width, y: zone.y + f.y * zone.height };
}
