/**
 * Puddle wiping, scratch-card style (GAMES_SPEC §6.2), pure — the game layer feeds it the cloth's
 * path and draws the wiped strokes; the puddle is clean at `WIPE_DONE` (~90 %) of its area.
 *
 * Coordinates are normalised to the puddle's box (u, v ∈ 0–1); the puddle is the ellipse inside
 * that box. The grid is immutable: every wipe returns a new grid (or the same one when nothing
 * changed), so React state can hold it directly.
 */

export const WIPE_DONE = 0.9;
export const WIPE_COLS = 24;
export const WIPE_ROWS = 12;

export interface WipeGrid {
  cols: number;
  rows: number;
  /** Cell is part of the puddle (inside the ellipse). */
  inside: readonly boolean[];
  wiped: readonly boolean[];
  insideCount: number;
  wipedCount: number;
}

export function createWipeGrid(cols = WIPE_COLS, rows = WIPE_ROWS): WipeGrid {
  const inside: boolean[] = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const u = (c + 0.5) / cols - 0.5;
      const v = (r + 0.5) / rows - 0.5;
      inside.push((u * u) / 0.25 + (v * v) / 0.25 <= 1);
    }
  }
  return { cols, rows, inside, wiped: inside.map(() => false), insideCount: inside.filter(Boolean).length, wipedCount: 0 };
}

/** Wipe a round cloth at (u, v) with radii (ru, rv) in puddle units. */
export function wipeAt(grid: WipeGrid, u: number, v: number, ru: number, rv: number): WipeGrid {
  let wiped: boolean[] | null = null;
  let count = grid.wipedCount;
  const c0 = Math.max(0, Math.floor((u - ru) * grid.cols));
  const c1 = Math.min(grid.cols - 1, Math.floor((u + ru) * grid.cols));
  const r0 = Math.max(0, Math.floor((v - rv) * grid.rows));
  const r1 = Math.min(grid.rows - 1, Math.floor((v + rv) * grid.rows));
  for (let r = r0; r <= r1; r++) {
    for (let c = c0; c <= c1; c++) {
      const i = r * grid.cols + c;
      if (!grid.inside[i] || (wiped ?? grid.wiped)[i]) continue;
      const du = ((c + 0.5) / grid.cols - u) / ru;
      const dv = ((r + 0.5) / grid.rows - v) / rv;
      if (du * du + dv * dv > 1) continue;
      wiped ??= [...grid.wiped];
      wiped[i] = true;
      count++;
    }
  }
  return wiped === null ? grid : { ...grid, wiped, wipedCount: count };
}

/** Wipe along a stroke from (u0, v0) to (u1, v1) (samples every half radius — no gaps). */
export function wipeSegment(grid: WipeGrid, u0: number, v0: number, u1: number, v1: number, ru: number, rv: number): WipeGrid {
  const steps = Math.max(1, Math.ceil(Math.hypot((u1 - u0) / ru, (v1 - v0) / rv) * 2));
  let g = grid;
  for (let s = 0; s <= steps; s++) {
    const t = s / steps;
    g = wipeAt(g, u0 + (u1 - u0) * t, v0 + (v1 - v0) * t, ru, rv);
  }
  return g;
}

/** Share of the puddle that is wiped (0–1). */
export function wipeCoverage(grid: WipeGrid): number {
  return grid.insideCount === 0 ? 1 : grid.wipedCount / grid.insideCount;
}

export function isWiped(grid: WipeGrid): boolean {
  return wipeCoverage(grid) >= WIPE_DONE;
}

/** Progress shown to the child, in steps of 10 % (the line / a11y don't flicker per cell). */
export function wipeStep(grid: WipeGrid): number {
  return Math.min(10, Math.floor(wipeCoverage(grid) * 10));
}

/**
 * »Lažje kretnje« / screen reader: holding the button wipes on its own along a zig-zag over the
 * puddle. Step n → the n-th short stroke (u0, v0, u1, v1); rows from top to bottom, then again.
 */
export const AUTO_WIPE_ROWS = 5;
export const AUTO_WIPE_STEPS_PER_ROW = 6;

export function autoWipeStroke(step: number): [number, number, number, number] {
  const perRow = AUTO_WIPE_STEPS_PER_ROW;
  const s = ((Math.trunc(step) % (AUTO_WIPE_ROWS * perRow)) + AUTO_WIPE_ROWS * perRow) % (AUTO_WIPE_ROWS * perRow);
  const row = Math.floor(s / perRow);
  const k = s % perRow;
  const v = 0.12 + (row / (AUTO_WIPE_ROWS - 1)) * 0.76;
  const forward = row % 2 === 0;
  const a = 0.06 + (k / perRow) * 0.88;
  const b = 0.06 + ((k + 1) / perRow) * 0.88;
  return forward ? [a, v, b, v] : [1 - a, v, 1 - b, v];
}

/** Cloth radius for the auto wipe (puddle units) — wide enough that one pass covers the rows. */
export const AUTO_WIPE_RADIUS = { u: 0.12, v: 0.2 } as const;
