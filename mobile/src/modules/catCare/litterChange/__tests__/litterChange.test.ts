/**
 * The weekly litter change's three gestures (David 2026-11-15) — the pure rules: layout (nothing
 * near the screen edge), tilt → pour, rub → spots, hold → fill, and the progress that turns them
 * into ✓ per step. The server's stroke rule is untouched (`scoreStrokes` proves a played game passes).
 */
import { HOLD_GAPS_MS } from '@/modules/catCare/brushing';
import { CHORE_STROKE_MIN_PT, CHORE_TURN_PT, beginRub, endRub, moveRub, scoreStrokes } from '@/modules/catCare/catGames';
import {
  EDGE_PT,
  FILL_FIRST_BEAT_MS,
  FILL_HOLD_MS,
  HOLD_FILL_BEATS,
  LITTER_START,
  MAX_TILT_DEG,
  POUR_CHUNKS,
  POUR_FROM,
  POUR_PER_DRAG,
  RUB_MIN_PT,
  RUB_TURN_PT,
  SPOTS,
  SPOT_REACH,
  WASH_DONE_SPOTS,
  afterFill,
  afterPour,
  afterScrub,
  bagSpout,
  cavityPoint,
  cleanMask,
  dirtySpots,
  dragProgress,
  fillLevel,
  fillRemainingMs,
  holdBeat,
  isWashed,
  leftAfter,
  litterFrame,
  litterLayout,
  litterReadout,
  pourChunks,
  pourShare,
  rubBegin,
  rubEnd,
  rubMove,
  scrubSpots,
  settleBefore,
  spotsLeft,
  tiltAt,
  trayLip,
  type LitterProgress,
} from '@/modules/catCare/litterChange/litterChange';

const PHONES: [string, number, number][] = [
  ['small phone (360 × 640), no dock', 336, 516],
  ['small phone with the hold-mode dock', 336, 428],
  ['usual phone', 369, 690],
  ['large phone', 406, 780],
  ['tablet', 744, 900],
];

describe('layout', () => {
  it.each(PHONES)('%s: bin and bag stay ≥ 24 dp inside the screen, the tray fits, the tipped tray pours into the bin', (_name, W, H) => {
    const L = litterLayout(W, H);
    // The field is inset 12 pt by the shell → EDGE_PT more = 24 dp from the screen edge.
    expect(EDGE_PT).toBe(12);
    for (const box of [L.bin, L.bag]) {
      expect(box.x - box.w / 2).toBeGreaterThanOrEqual(EDGE_PT - 0.01);
      expect(box.x + box.w / 2).toBeLessThanOrEqual(W - EDGE_PT + 0.01);
      expect(box.y - box.h / 2).toBeGreaterThanOrEqual(EDGE_PT - 0.01);
      expect(box.y + box.h / 2).toBeLessThanOrEqual(H - EDGE_PT + 0.01);
      expect(Math.min(box.w, box.h)).toBeGreaterThanOrEqual(64);
    }
    // At rest and enlarged the tray is inside the field.
    expect(L.home.x - L.trayW / 2).toBeGreaterThanOrEqual(0);
    expect(L.home.y - L.trayH / 2).toBeGreaterThanOrEqual(0);
    expect(L.bigScale).toBeGreaterThanOrEqual(1);
    expect(L.big.x - (L.trayW * L.bigScale) / 2).toBeGreaterThanOrEqual(EDGE_PT - 0.01);
    expect(L.big.y + (L.trayH * L.bigScale) / 2).toBeLessThanOrEqual(H - EDGE_PT + 0.01);
    // The drag goes DOWN towards the bin (never sideways into Android's back edge) and is a real drag.
    expect(L.uy).toBeGreaterThan(0.85);
    expect(L.pathLen).toBeGreaterThanOrEqual(60);
    // Fully tipped, the pouring corner is right above the bin's mouth.
    const lip = trayLip(L, 1);
    expect(Math.abs(lip.x - L.bin.mouthX)).toBeLessThanOrEqual(L.bin.w / 2);
    expect(lip.y).toBeLessThanOrEqual(L.bin.mouthY);
    // The bag pours into the enlarged tray.
    const spout = bagSpout(L);
    expect(spout.x).toBeGreaterThan(L.big.x - (L.trayW * L.bigScale) / 2);
    expect(spout.x).toBeLessThan(L.big.x + (L.trayW * L.bigScale) / 2);
  });
});

describe('step 1 — drag → tilt → pour', () => {
  const L = litterLayout(336, 516);

  it('the tray tilts with the drag from the first point; only a drag towards the bin counts', () => {
    expect(dragProgress(0, 0, L.ux, L.uy, L.pathLen)).toBe(0);
    expect(dragProgress(L.ux * L.pathLen * 0.5, L.uy * L.pathLen * 0.5, L.ux, L.uy, L.pathLen)).toBeCloseTo(0.5);
    expect(dragProgress(L.ux * L.pathLen * 3, L.uy * L.pathLen * 3, L.ux, L.uy, L.pathLen)).toBe(1);
    // Up, or sideways: nothing.
    expect(dragProgress(0, -200, L.ux, L.uy, L.pathLen)).toBe(0);
    expect(dragProgress(L.uy * 120, -L.ux * 120, L.ux, L.uy, L.pathLen)).toBeCloseTo(0);
    expect(dragProgress(Number.NaN, 0, L.ux, L.uy, L.pathLen)).toBe(0);
    expect(tiltAt(0)).toBe(0);
    expect(tiltAt(0.5)).toBeCloseTo(MAX_TILT_DEG / 2);
    expect(tiltAt(2)).toBe(MAX_TILT_DEG);
  });

  it('nothing pours before the tray is tipped; then the litter follows the tilt', () => {
    expect(pourShare(POUR_FROM)).toBe(0);
    expect(pourShare(POUR_FROM - 0.2)).toBe(0);
    expect(pourShare((1 + POUR_FROM) / 2)).toBeCloseTo(0.5);
    expect(pourShare(1)).toBe(1);
    expect(leftAfter(1, 0.3)).toBe(1);
    expect(leftAfter(1, (1 + POUR_FROM) / 2)).toBeCloseTo(1 - POUR_PER_DRAG / 2);
  });

  it('one drag pours at most 40 % → three full drags empty the tray, and it ends EMPTY', () => {
    let left = 1;
    const seen: number[] = [];
    for (let drag = 0; drag < 3; drag++) {
      left = leftAfter(left, 1);
      seen.push(Number(left.toFixed(2)));
    }
    expect(seen).toEqual([0.6, 0.2, 0]);
    // A crumb is not a fourth drag.
    expect(leftAfter(0.41, 1)).toBe(0);
    expect(leftAfter(0, 1)).toBe(0);
  });

  it('a stroke per poured fifth', () => {
    expect([1, 0.81, 0.8, 0.6, 0.2, 0].map(pourChunks)).toEqual([0, 0, 1, 2, 4, POUR_CHUNKS]);
  });
});

describe('step 2 — rub → spots', () => {
  it('a field point maps into the cavity of the enlarged tray', () => {
    const L = litterLayout(336, 516);
    const centre = cavityPoint(L.big.x, L.big.y, L.big.x, L.big.y, L.trayW, L.bigScale);
    expect(centre.u).toBeCloseTo(0.5);
    expect(centre.v).toBeGreaterThan(0.3);
    expect(centre.v).toBeLessThan(0.6);
    const corner = cavityPoint(L.big.x - (L.trayW * L.bigScale) / 2, L.big.y - (L.trayH * L.bigScale) / 2, L.big.x, L.big.y, L.trayW, L.bigScale);
    expect(corner.u).toBeLessThan(0);
    expect(corner.v).toBeLessThan(0);
  });

  it('coverage: a spot fades with the travel over it — about two passes; a resting finger cleans nothing', () => {
    const s = SPOTS[0];
    let dirt: readonly number[] = dirtySpots();
    // Holding still on the spot.
    expect(scrubSpots(dirt, s.u, s.v, s.u, s.v)).toBe(dirt);
    // One pass through it: partly clean.
    dirt = scrubSpots(dirt, s.u - 0.1, s.v, s.u + 0.1, s.v);
    expect(dirt[0]).toBeGreaterThan(0);
    expect(dirt[0]).toBeLessThan(1);
    expect(dirt.slice(1).every((v) => v === 1 || SPOTS.some((o, i) => i > 0 && Math.abs(o.u - s.u) < 0.2 && v < 1))).toBe(true);
    // Back again: gone.
    dirt = scrubSpots(dirt, s.u + 0.1, s.v, s.u - 0.1, s.v);
    expect(dirt[0]).toBe(0);
    expect(cleanMask(dirt) & 1).toBe(1);
  });

  it('a rub that misses the spot leaves it; one long coarse sample never cleans a spot at once', () => {
    const s = SPOTS[2];
    const far = scrubSpots(dirtySpots(), s.u - 0.1, s.v - 0.6, s.u + 0.1, s.v - 0.6);
    expect(far[2]).toBe(1);
    const long = scrubSpots(dirtySpots(), -2, s.v, 3, s.v);
    expect(long[2]).toBeGreaterThan(0.3);
    expect(SPOT_REACH).toBeLessThan(0.15);
  });

  it('»most spots«: clean at 7 of 8', () => {
    expect(SPOTS).toHaveLength(8);
    expect(WASH_DONE_SPOTS).toBe(7);
    expect(spotsLeft(0)).toBe(8);
    expect(isWashed(0b00111111)).toBe(false);
    expect(isWashed(0b01111111)).toBe(true);
    expect(spotsLeft(0b01111111)).toBe(1);
  });

  it('rub strokes on the UI thread are the same rule as the old rub (`moveRub` / `endRub`)', () => {
    expect([RUB_MIN_PT, RUB_TURN_PT]).toEqual([CHORE_STROKE_MIN_PT, CHORE_TURN_PT]);
    const path: [number, number][] = [];
    for (let lap = 0; lap < 4; lap++) {
      for (let x = 0; x <= 120; x += 12) path.push([lap % 2 === 0 ? x : 120 - x, 50 + lap * 3]);
    }
    let rub = rubBegin(path[0][0], path[0][1]);
    let old = beginRub({ x: path[0][0], y: path[0][1] }, 0);
    let strokes = 0;
    let oldStrokes = 0;
    path.slice(1).forEach(([x, y], i) => {
      const a = rubMove(rub, x, y);
      rub = a.rub;
      if (a.stroke) strokes++;
      const b = moveRub(old, { x, y }, i);
      old = b.tracker;
      if (b.stroke !== null) oldStrokes++;
    });
    expect(strokes).toBe(oldStrokes);
    expect(strokes).toBe(3);
    expect(rubEnd(rub)).toBe(endRub(old, 99) !== null);
    // A tap / a resting finger is no stroke.
    expect(rubEnd(rubBegin(10, 10))).toBe(false);
    expect(rubMove(rubBegin(10, 10), Number.NaN, 0).stroke).toBe(false);
  });
});

describe('step 3 — hold → fill', () => {
  it('the level rises with the hold, pauses when released, and can never pass the line', () => {
    expect(fillLevel(0, 0)).toBe(0);
    expect(fillLevel(0, FILL_HOLD_MS / 2)).toBeCloseTo(0.5);
    expect(fillLevel(0.5, FILL_HOLD_MS / 4)).toBeCloseTo(0.75);
    expect(fillLevel(0, FILL_HOLD_MS)).toBe(1);
    expect(fillLevel(0.9, 60_000)).toBe(1);
    expect(fillLevel(0.4, -50)).toBe(0.4);
    expect(fillLevel(0.4, Number.NaN)).toBe(0.4);
    expect(fillRemainingMs(0)).toBe(FILL_HOLD_MS);
    expect(fillRemainingMs(0.5)).toBe(FILL_HOLD_MS / 2);
    expect(fillRemainingMs(1)).toBe(0);
  });

  it('the pour beats are irregular (never machine-regular for the server) and start soon', () => {
    expect(FILL_FIRST_BEAT_MS).toBeLessThan(500);
    expect(Math.max(...HOLD_GAPS_MS) - Math.min(...HOLD_GAPS_MS)).toBeGreaterThan(40);
  });
});

describe('progress', () => {
  it('each step is done only by its own work; done never comes from the clock', () => {
    let p: LitterProgress = LITTER_START;
    expect(p.done).toEqual([false, false, false]);
    p = afterPour(p, 0.6);
    p = afterPour(p, 0.8); // never back up
    expect(p.left).toBe(0.6);
    expect(p.done[0]).toBe(false);
    p = afterPour(p, 0);
    expect(p.done).toEqual([true, false, false]);

    p = afterScrub(p, 0b00000111);
    expect(p.done[1]).toBe(false);
    expect(afterScrub(p, 0b00000001)).toBe(p);
    p = afterScrub(p, 0b01111000);
    expect(p.done).toEqual([true, true, false]);
    // The last spot goes with the sparkle.
    expect(spotsLeft(p.clean)).toBe(0);

    p = afterFill(p, 0.5);
    p = afterFill(p, 0.3); // never back down
    expect(p.fill).toBe(0.5);
    expect(p.done[2]).toBe(false);
    p = afterFill(p, 7);
    expect(p.fill).toBe(1);
    expect(p.done).toEqual([true, true, true]);
  });

  it('a step whose time ran out is settled (the picture stays true) WITHOUT its ✓', () => {
    const half = afterPour(LITTER_START, 0.6);
    const two = settleBefore(half, 2);
    expect(two.left).toBe(0);
    expect(two.done).toEqual([false, false, false]);
    expect(settleBefore(two, 2)).toBe(two);
    const three = settleBefore(afterScrub(two, 0b11), 3);
    expect(spotsLeft(three.clean)).toBe(0);
    expect(three.done).toEqual([false, false, false]);
    expect(settleBefore(LITTER_START, 1)).toBe(LITTER_START);
  });

  it('hold mode: the button does the same work in equal beats', () => {
    let p: LitterProgress = LITTER_START;
    for (let i = 0; i < POUR_CHUNKS; i++) p = holdBeat(p, 1);
    expect(p.left).toBe(0);
    expect(p.done[0]).toBe(true);
    expect(holdBeat(p, 1)).toBe(p);
    for (let i = 0; i < WASH_DONE_SPOTS; i++) {
      expect(p.done[1]).toBe(false);
      p = holdBeat(p, 2);
    }
    expect(p.done[1]).toBe(true);
    expect(holdBeat(p, 2)).toBe(p);
    for (let i = 0; i < HOLD_FILL_BEATS; i++) {
      expect(p.done[2]).toBe(false);
      p = holdBeat(p, 3);
    }
    expect(p.fill).toBe(1);
    expect(p.done).toEqual([true, true, true]);
    expect(holdBeat(p, 3)).toBe(p);
  });

  it('the frame gives the shell its ✓ per finished step; the readout says what the drawing shows', () => {
    const p = afterPour(LITTER_START, 0);
    expect(litterFrame(4, p)).toEqual({ strokes: 4, stepsDone: [true, false, false] });
    expect(litterReadout(afterPour(LITTER_START, 0.6), 1)).toEqual({ kind: 'old', value: 60 });
    expect(litterReadout(afterScrub(p, 0b111), 2)).toEqual({ kind: 'spots', value: 5 });
    expect(litterReadout(afterFill(p, 0.5), 3)).toEqual({ kind: 'fresh', value: 50 });
  });
});

describe('the server rule is unchanged', () => {
  const rules = { duration_ms: 30_000, min_strokes: 10, segments: 3, min_stroke_interval_ms: 150 };

  it('a child who finishes each gesture in its part passes: ≥ 10 counted strokes in all three parts, not machine-regular', () => {
    // Step 1: three drags (a stroke per poured fifth — two fifths in one drag merge on the server).
    const pour = [1_500, 1_500, 3_200, 3_200, 4_900];
    // Step 2: the fewest rub strokes that can clean the tray: one long rub, lifted once.
    const rub = [15_000];
    // Step 3: one hold to the line — a beat after 300 ms, then the irregular gaps.
    const beats: number[] = [];
    let t = 21_000 + FILL_FIRST_BEAT_MS;
    for (let n = 1; t < 21_000 + FILL_HOLD_MS; n++) {
      beats.push(t);
      t += HOLD_GAPS_MS[n % HOLD_GAPS_MS.length];
    }
    expect(beats).toHaveLength(7);
    const score = scoreStrokes([...pour, ...rub, ...beats], rules);
    expect(score).toEqual({ success: true, reason: null, strokes: 11, segments_hit: 3 });
  });

  it('skipping a step still fails on the server exactly as before (`not_spread`)', () => {
    expect(scoreStrokes([1_000, 2_000, 3_000, 4_000, 5_000, 21_000, 22_000, 23_000, 24_000, 25_000, 26_000], rules).reason).toBe('not_spread');
  });
});
