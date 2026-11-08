/**
 * The cat wand hunt — where the feather is drawn (GAMES_SPEC §5.2), pure (Jest-tested with plain
 * numbers). Since the simple rule (David 2026-11-08) the feather's moves are not scored at all:
 * the feather only follows the finger (lifted above the thumb) so the cat has something to stalk;
 * what counts is the moment the child lets go (`wandRules.ts`).
 */

import type { Point } from '@/modules/catCare/catGames';

export interface ZoneRect {
  x: number;
  y: number;
  width: number;
  height: number;
}

/**
 * The area where the feather rests by default and floats in »Lažje kretnje«: the upper part of
 * the screen where the cat looks (under the top bar, above the hand's third — GAMES_SPEC §3.1).
 */
export function wandZone(size: { width: number; height: number }): ZoneRect {
  'worklet';
  return { x: size.width * 0.07, y: size.height * 0.15, width: size.width * 0.86, height: size.height * 0.45 };
}

/** The feather floats this far above the finger, so the thumb never hides it. */
export const FEATHER_LIFT_PT = 56;

/** Where the feather is for a finger at `finger` (pt on the field). */
export function featherForFinger(finger: Point): Point {
  'worklet';
  return { x: finger.x, y: finger.y - FEATHER_LIFT_PT };
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
