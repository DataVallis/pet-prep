/**
 * Hit-testing of the cleaning games (GAMES_SPEC §6.2), pure `'worklet'` functions: the gestures
 * call them on the UI thread (no JS round trip while dragging), Jest calls them as plain
 * functions. Pieces are passed as flat coordinate arrays and "done" sets as bit masks (≤ 31
 * pieces), which cross into worklets without copies of objects.
 */

/** Point (px, py) within `r` of (cx, cy). */
export function within(px: number, py: number, cx: number, cy: number, r: number): boolean {
  'worklet';
  const dx = px - cx;
  const dy = py - cy;
  return dx * dx + dy * dy <= r * r;
}

export function hasBit(mask: number, i: number): boolean {
  'worklet';
  return (mask & (1 << i)) !== 0;
}

/** All `count` pieces are in `mask`. */
export function allDone(mask: number, count: number): boolean {
  'worklet';
  const full = count >= 31 ? 0x7fffffff : (1 << count) - 1;
  return (mask & full) === full;
}

/** The nearest piece not in `doneMask` within `r` of the point, or -1. */
export function pieceUnder(xs: readonly number[], ys: readonly number[], doneMask: number, px: number, py: number, r: number): number {
  'worklet';
  let best = -1;
  let bestD = r * r;
  for (let i = 0; i < xs.length; i++) {
    if (hasBit(doneMask, i)) continue;
    const dx = px - xs[i];
    const dy = py - ys[i];
    const d = dx * dx + dy * dy;
    if (d <= bestD) {
      best = i;
      bestD = d;
    }
  }
  return best;
}

/** Distance² from (px, py) to the segment (x0, y0)–(x1, y1). */
export function segmentDistance2(x0: number, y0: number, x1: number, y1: number, px: number, py: number): number {
  'worklet';
  const dx = x1 - x0;
  const dy = y1 - y0;
  const len2 = dx * dx + dy * dy;
  const t = len2 === 0 ? 0 : Math.max(0, Math.min(1, ((px - x0) * dx + (py - y0) * dy) / len2));
  const qx = x0 + dx * t - px;
  const qy = y0 + dy * t - py;
  return qx * qx + qy * qy;
}

/**
 * Litter scoop: the clumps (not in `takenMask`) the scoop's head passed over between two
 * frames — a bit mask of the new ones. A fast drag never skips a clump (segment, not point).
 */
export function scoopPicks(xs: readonly number[], ys: readonly number[], takenMask: number, x0: number, y0: number, x1: number, y1: number, r: number): number {
  'worklet';
  let picks = 0;
  for (let i = 0; i < xs.length; i++) {
    if (hasBit(takenMask, i)) continue;
    if (segmentDistance2(x0, y0, x1, y1, xs[i], ys[i]) <= r * r) picks |= 1 << i;
  }
  return picks;
}

/** Upward speed (pt/s, negative = up) that shakes the scoop's clumps into the bag. */
export const SHAKE_VELOCITY = -700;
/** …or this far up (pt) at a lower speed when the finger lets go. */
export const SHAKE_DISTANCE = -120;

/** A shake upward: fast up, or a clear move up that was still going up when it ended. */
export function isShakeUp(velocityY: number, translationY: number): boolean {
  'worklet';
  return velocityY <= SHAKE_VELOCITY || (translationY <= SHAKE_DISTANCE && velocityY < -150);
}

/** Bits set in a mask (pieces done). */
export function countBits(mask: number): number {
  'worklet';
  let n = 0;
  let m = mask >>> 0;
  while (m !== 0) {
    n += m & 1;
    m >>>= 1;
  }
  return n;
}
