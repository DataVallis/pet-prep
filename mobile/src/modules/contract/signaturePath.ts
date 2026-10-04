/**
 * Finger signature → SVG path data for `POST /api/child/contract` (M1-07b).
 *
 * The backend (`SignContractRequest`) accepts only path commands and numbers,
 * starting with a move, at most 20,000 characters. We emit `M x y` for each new
 * stroke and `L x y` for each further point, integer coordinates ≥ 0, and drop
 * points closer than MIN_POINT_DISTANCE to keep the path short.
 */

export const MAX_SVG_PATH_LENGTH = 20000;

/** Minimum distance (px) between consecutive points of a stroke. */
export const MIN_POINT_DISTANCE = 2;

/** Minimum total drawn length (px) before a signature counts — a stray tap isn't one. */
export const MIN_SIGNATURE_LENGTH = 20;

export interface Point {
  x: number;
  y: number;
}

function coord(value: number): number {
  return Number.isFinite(value) ? Math.max(0, Math.round(value)) : 0;
}

export function distance(a: Point, b: Point): number {
  return Math.hypot(a.x - b.x, a.y - b.y);
}

/** Path segment for a point: `M x y` starts a stroke, `L x y` continues it. */
export function segmentFor(point: Point, newStroke: boolean): string {
  return `${newStroke ? 'M' : 'L'}${coord(point.x)} ${coord(point.y)}`;
}

/**
 * Append a point to the path. Returns null when the result would exceed the
 * backend limit (the pad then simply stops recording).
 */
export function appendToPath(path: string, point: Point, newStroke: boolean): string | null {
  const segment = segmentFor(point, newStroke);
  const next = path.length === 0 ? segment : `${path} ${segment}`;
  return next.length <= MAX_SVG_PATH_LENGTH ? next : null;
}

/** Whether a move to `point` is far enough from the last recorded point to keep. */
export function shouldRecord(last: Point | null, point: Point): boolean {
  return last === null || distance(last, point) >= MIN_POINT_DISTANCE;
}

/** Same pattern the backend checks (commands + numbers only, starts with a move). */
const SVG_PATH_PATTERN = /^[Mm][MmLlHhVvCcSsQqTtAaZz0-9eE.,+\-\s]*$/;

export function isValidSvgPath(path: string): boolean {
  const trimmed = path.trim();
  return trimmed.length > 0 && trimmed.length <= MAX_SVG_PATH_LENGTH && SVG_PATH_PATTERN.test(trimmed);
}

/** Total drawn length of an `M`/`L` path produced by `appendToPath`. */
export function drawnLength(path: string): number {
  let total = 0;
  let last: Point | null = null;
  for (const match of path.matchAll(/([ML])(\d+) (\d+)/g)) {
    const point = { x: Number(match[2]), y: Number(match[3]) };
    if (match[1] === 'L' && last) total += distance(last, point);
    last = point;
  }
  return total;
}

/** A path is a usable signature when it is valid and long enough. */
export function hasSignature(path: string): boolean {
  return isValidSvgPath(path) && drawnLength(path) >= MIN_SIGNATURE_LENGTH;
}
