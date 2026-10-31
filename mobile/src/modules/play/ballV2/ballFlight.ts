/**
 * Ball v2 — the drawn ball in 2.5D (GAMES_SPEC §4.3), pure math. Every function is a worklet:
 * `PlayField` evaluates them on the UI thread (reanimated `useDerivedValue`, 60 fps); Jest
 * calls them as plain functions.
 *
 * Space: `nx` = 0…1 across the screen, `z` = depth (0 = the hand / camera plane, bigger = into
 * the room), `h` = height above the floor (1 unit = `HEIGHT_UNIT_PT` at z = 0). Perspective:
 * size and height shrink with `1 / (1 + PERSPECTIVE · z)`, the floor point moves from the hand
 * line up to the horizon.
 *
 * Throw (§4.3, T … T+1.1 s): an arc into the room (higher / further with power), one bounce
 * (~550 ms), then it rolls to the side the dog runs to (`exit_side`) and leaves the frame —
 * `center` (or unknown) = straight into the distance, smaller and fading.
 * Return: the ball drops in front of the dog and rolls to the camera in `ROLL_MS`, ending in
 * the hand (the rest position) — a caught or missed ball is ready for the next throw.
 */

import type { ExitSide } from '@/modules/petMedia/petMedia';

export interface FieldSize {
  width: number;
  height: number;
}

export interface BallFrame {
  /** Centre (pt). */
  x: number;
  y: number;
  /** Radius (pt). */
  r: number;
  /** Floor shadow (ellipse centre / radii, opacity 0…1). */
  shadowX: number;
  shadowY: number;
  shadowRx: number;
  shadowRy: number;
  shadowOpacity: number;
  /** Ball opacity 0…1. */
  opacity: number;
}

export const BALL_RADIUS_PT = 34;
/** Where the hand holds the ball (fraction of the height) — the bottom third is the hand's (§3.1). */
export const HAND_LINE = 0.8;
/** Where the floor meets the far wall (fraction of the height). */
export const HORIZON_LINE = 0.46;
export const PERSPECTIVE = 1.6;
export const HEIGHT_UNIT_PT = 170;
/** The throw on screen: arc + bounce + roll out of frame (§4.3: out of frame at ~1.1 s). */
export const FLIGHT_MS = 1_100;
/** The touch target of the rolling ball (§4.3: 96 pt). */
export const CATCH_TARGET_PT = 96;

/** −1 left, +1 right, 0 into the distance. */
export function sideSign(side: ExitSide | null): number {
  'worklet';
  return side === 'left' ? -1 : side === 'right' ? 1 : 0;
}

function lerp(a: number, b: number, t: number): number {
  'worklet';
  return a + (b - a) * t;
}

function clamp01(t: number): number {
  'worklet';
  return t < 0 ? 0 : t > 1 ? 1 : t;
}

function easeOut(t: number): number {
  'worklet';
  const u = clamp01(t);
  return 1 - (1 - u) * (1 - u);
}

/** A point in the room → a frame on screen. */
export function projectBall(size: FieldSize, nx: number, z: number, h: number, opacity: number): BallFrame {
  'worklet';
  const persp = 1 / (1 + PERSPECTIVE * Math.max(0, z));
  const floorY = (HORIZON_LINE + (HAND_LINE - HORIZON_LINE) * persp) * size.height;
  const r = BALL_RADIUS_PT * persp;
  const x = nx * size.width;
  const lift = Math.max(0, h) * HEIGHT_UNIT_PT * persp;
  return {
    x,
    // The ball rests ON the floor point; height lifts it above its shadow.
    y: floorY - r - lift,
    r,
    shadowX: x,
    shadowY: floorY - r * 0.1,
    shadowRx: r * 1.05,
    shadowRy: r * 0.32,
    shadowOpacity: opacity * 0.35 * (1 - Math.min(1, Math.max(0, h) / 1.4)),
    opacity,
  };
}

/** The ball in the hand, dragged by the finger (`dx`, `dy` in pt). */
export function ballAtRest(size: FieldSize, dx = 0, dy = 0): BallFrame {
  'worklet';
  const frame = projectBall(size, 0.5, 0, 0, 1);
  return { ...frame, x: frame.x + dx, y: frame.y + dy, shadowX: frame.shadowX + dx * 0.6 };
}

/**
 * The thrown ball at `t` (0…1 of `FLIGHT_MS`). `power` 0…1 = arc height / distance (picture
 * only); `fromDx` / `fromDy` = where the finger let go, blended away in the first quarter.
 */
export function ballInFlight(size: FieldSize, t: number, power: number, side: ExitSide | null, fromDx = 0, fromDy = 0): BallFrame {
  'worklet';
  const p = clamp01(power);
  const s = sideSign(side);
  const zLand = 1.0 + 0.8 * p;
  const peak = 0.9 + 0.9 * p;
  const xLand = 0.5 + s * 0.16;
  const xExit = 0.5 + s * 0.78;
  const tt = clamp01(t);
  let nx: number;
  let z: number;
  let h: number;
  let opacity = 1;
  if (tt < 0.5) {
    const u = tt / 0.5;
    nx = lerp(0.5, xLand, u);
    z = zLand * u;
    h = peak * 4 * u * (1 - u);
  } else if (tt < 0.72) {
    const u = (tt - 0.5) / 0.22;
    nx = lerp(xLand, xExit, 0.2 * u);
    z = zLand + 0.3 * u;
    h = 0.22 * peak * 4 * u * (1 - u);
  } else {
    const u = easeOut((tt - 0.72) / 0.28);
    nx = lerp(lerp(xLand, xExit, 0.2), xExit, u);
    z = zLand + 0.3 + (s === 0 ? 2.4 : 0.4) * u;
    h = 0;
    // Into the distance: smaller and fading; to a side: it simply leaves the frame.
    opacity = s === 0 ? 1 - u : 1;
  }
  const frame = projectBall(size, nx, z, h, opacity);
  const blend = 1 - clamp01(tt / 0.25);
  return { ...frame, x: frame.x + fromDx * blend, y: frame.y + fromDy * blend };
}

/** The returned ball at `t` (0…1 of `ROLL_MS`): drops in front of the dog, rolls to the hand. */
export function ballReturning(size: FieldSize, t: number): BallFrame {
  'worklet';
  const tt = clamp01(t);
  let h: number;
  if (tt < 0.25) {
    const u = tt / 0.25;
    h = 0.55 * (1 - u * u);
  } else if (tt < 0.45) {
    const u = (tt - 0.25) / 0.2;
    h = 0.12 * 4 * u * (1 - u);
  } else {
    h = 0;
  }
  const z = 1.2 * (1 - easeOut(tt));
  return projectBall(size, 0.5, z, h, tt < 0.08 ? tt / 0.08 : 1);
}

/** A tap at (`tx`, `ty`) catches the ball drawn as `frame` (target ≥ 96 pt, §4.3). */
export function isCatchTap(frame: BallFrame, tx: number, ty: number): boolean {
  'worklet';
  const reach = Math.max(CATCH_TARGET_PT / 2, frame.r + 24);
  const dx = tx - frame.x;
  const dy = ty - frame.y;
  return dx * dx + dy * dy <= reach * reach;
}

// ── Throw gesture ────────────────────────────────────────────

/** §4.3 anti-spam: a pull shorter than this is no throw (the ball goes back to the hand). */
export const THROW_MIN_PULL_PT = 30;
/** … and neither is a release slower than this (pt/s, upwards). */
export const THROW_MIN_SPEED_PT_S = 250;
/** A release this fast (pt/s) is a full-power throw. */
export const THROW_FULL_SPEED_PT_S = 2_200;

/**
 * A finger let go after moving `dy` pt (negative = up) at `vy` pt/s: the throw's power 0…1, or
 * null when it is no throw (too short, too slow or not upwards).
 */
export function classifyRelease(dy: number, vy: number): number | null {
  'worklet';
  if (!Number.isFinite(dy) || !Number.isFinite(vy)) return null;
  if (dy > -THROW_MIN_PULL_PT || vy > -THROW_MIN_SPEED_PT_S) return null;
  return clamp01((-vy - THROW_MIN_SPEED_PT_S) / (THROW_FULL_SPEED_PT_S - THROW_MIN_SPEED_PT_S));
}
