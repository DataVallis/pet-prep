/**
 * M5-T14 — brushing as a scratch card (pure logic): patches per segment, a counted stroke
 * cleans the patch nearest to the finger in the current step, progress, early finish only
 * when the coat is clean, irregular hold rhythm, sparkle throttle.
 */
import {
  EARLY_FINISH_LEAD_MS,
  HOLD_GAPS_MS,
  PARTICLE_MIN_MS,
  PATCHES_PER_SEGMENT,
  applyStroke,
  brushCleanFromStrokes,
  brushFinishAt,
  brushProgress,
  holdGap,
  initialBrush,
  layoutPatches,
  normalisePoint,
  shouldSpawnParticle,
  strokesPerSegment,
  targetPatch,
  unitsPerPatch,
  type BrushState,
} from '@/modules/catCare/brushing';
import { CHORE_UNIFORM_MAX_SPREAD_MS, CHORE_UNIFORM_MIN_GAPS, isTooUniform, scoreStrokes, type ChoreRules } from '@/modules/catCare/catGames';

const RULES: ChoreRules = { duration_ms: 30_000, min_strokes: 10, segments: 3, min_stroke_interval_ms: 150 };
const MATTED: ChoreRules = { duration_ms: 60_000, min_strokes: 20, segments: 3, min_stroke_interval_ms: 150 };

function run(rules: ChoreRules, strokes: readonly number[], points: (number | null)[] = []): BrushState {
  const patches = layoutPatches(rules.segments);
  return strokes.reduce<BrushState>((s, t, i) => {
    const p = points[i];
    return applyStroke(s, rules, patches, t, p === null || p === undefined ? null : { x: p, y: 0.5 });
  }, initialBrush(patches.length));
}

describe('patch layout', () => {
  it('puts PATCHES_PER_SEGMENT patches per segment, head (segment 0) above tail', () => {
    const patches = layoutPatches(3);
    expect(patches).toHaveLength(3 * PATCHES_PER_SEGMENT);
    expect(patches.map((p) => p.segment)).toEqual([0, 0, 1, 1, 2, 2]);
    const y = (s: number) => Math.max(...patches.filter((p) => p.segment === s).map((p) => p.y));
    expect(y(0)).toBeLessThan(Math.min(...patches.filter((p) => p.segment === 1).map((p) => p.y)));
    expect(y(1)).toBeLessThan(Math.min(...patches.filter((p) => p.segment === 2).map((p) => p.y)));
    for (const p of patches) {
      expect(p.x).toBeGreaterThan(0);
      expect(p.x).toBeLessThan(1);
    }
  });

  it('a segment needs its share of min_strokes (rounded up)', () => {
    expect(strokesPerSegment(RULES)).toBe(4);
    expect(unitsPerPatch(RULES)).toBe(2);
    expect(strokesPerSegment(MATTED)).toBe(7);
    expect(unitsPerPatch(MATTED)).toBe(4);
  });
});

describe('applyStroke / brushProgress', () => {
  it('a stroke cleans one unit of the nearest open patch of the current segment', () => {
    const patches = layoutPatches(3);
    const right = patches.filter((p) => p.segment === 0).sort((a, b) => b.x - a.x)[0];
    const s = applyStroke(initialBrush(patches.length), RULES, patches, 1_000, { x: 0.95, y: 0.3 });
    expect(s.cleaned[right.id]).toBe(1);
    expect(s.cleaned.reduce((a, b) => a + b, 0)).toBe(1);
    const p = brushProgress(s, RULES, patches);
    expect(p.dirt[right.id]).toBe(0.5);
    expect(p.percent).toBe(8);
    expect(p.allClean).toBe(false);
  });

  it('without a point (button, hold, VoiceOver) the first open patch of the step is cleaned', () => {
    const patches = layoutPatches(3);
    const s = applyStroke(initialBrush(patches.length), RULES, patches, 12_000, null);
    expect(targetPatch(patches, initialBrush(6).cleaned, 2, 1, null)?.id).toBe(2);
    expect(s.cleaned).toEqual([0, 0, 1, 0, 0, 0]);
  });

  it('strokes closer than the server interval count once; out-of-session strokes are dropped', () => {
    const s = run(RULES, [1_000, 1_100, 1_149, 1_150, -5, 30_001]);
    expect(s.cleaned.reduce((a, b) => a + b, 0)).toBe(2);
    expect(s.lastCountedMs).toBe(1_150);
  });

  it('a clean step absorbs further strokes; other steps stay dirty (bar < 100 %)', () => {
    const s = run(RULES, [500, 1_500, 2_500, 3_500, 4_500, 5_500]);
    const p = brushProgress(s, RULES, layoutPatches(3));
    expect(p.dirt.slice(0, 2)).toEqual([0, 0]);
    expect(p.dirt.slice(2)).toEqual([1, 1, 1, 1]);
    expect(p.percent).toBe(33);
    expect(p.allClean).toBe(false);
  });

  it('all patches clean = 100 % and the server would count the same strokes', () => {
    const strokes = [500, 1_700, 2_600, 4_100, 10_300, 11_900, 12_800, 14_700, 20_200, 21_600, 22_900, 24_000];
    const s = run(RULES, strokes);
    const p = brushProgress(s, RULES, layoutPatches(3));
    expect(p.allClean).toBe(true);
    expect(p.percent).toBe(100);
    expect(p.fraction).toBe(1);
    expect(scoreStrokes(strokes, RULES)).toMatchObject({ success: true, reason: null });
    expect(brushCleanFromStrokes(RULES, strokes)).toBe(true);
  });

  it('percent stays below 100 until the last unit is cleaned', () => {
    const s = run(RULES, [500, 1_700, 2_600, 4_100, 10_300, 11_900, 12_800, 14_700, 20_200, 21_600, 22_900]);
    expect(brushProgress(s, RULES, layoutPatches(3)).percent).toBe(91);
  });
});

describe('finishing', () => {
  it('finishes at the end as before, or EARLY_FINISH_LEAD_MS early once clean', () => {
    expect(brushFinishAt(RULES, [1_000, 10_500, 20_900])).toBe(30_000);
    const clean = [500, 1_700, 2_600, 4_100, 10_300, 11_900, 12_800, 14_700, 20_200, 21_600, 22_900, 24_000];
    expect(brushFinishAt(RULES, clean)).toBe(30_000 - EARLY_FINISH_LEAD_MS);
    // Inside the server's tolerance (finish_early_tolerance_seconds = 3).
    expect(EARLY_FINISH_LEAD_MS).toBeLessThan(3_000);
  });

  it('a step without its share never counts as clean (server: not_spread)', () => {
    const strokes = [500, 1_700, 2_600, 4_100, 5_000, 6_000, 10_300, 11_900, 12_800, 14_700];
    expect(brushCleanFromStrokes(RULES, strokes)).toBe(false);
    expect(brushCleanFromStrokes(MATTED, strokes)).toBe(false);
  });
});

describe('hold rhythm and sparkles', () => {
  it('the hold rhythm is never machine-regular for the server', () => {
    const times: number[] = [];
    let t = 450;
    for (let n = 0; n < 40; n++) {
      times.push(t);
      t += holdGap(n);
    }
    expect(isTooUniform(times, CHORE_UNIFORM_MIN_GAPS, CHORE_UNIFORM_MAX_SPREAD_MS)).toBe(false);
    expect(Math.min(...HOLD_GAPS_MS)).toBeGreaterThan(150);
    expect(holdGap(-1)).toBe(HOLD_GAPS_MS[HOLD_GAPS_MS.length - 1]);
    // A 30 s hold brushes a clean coat.
    expect(brushCleanFromStrokes(RULES, times.filter((x) => x <= 28_000))).toBe(true);
  });

  it('sparkles are throttled by time and travel', () => {
    expect(shouldSpawnParticle(null, 0, { x: 0, y: 0 })).toBe(true);
    const last = { t: 0, p: { x: 0, y: 0 } };
    expect(shouldSpawnParticle(last, PARTICLE_MIN_MS - 1, { x: 50, y: 0 })).toBe(false);
    expect(shouldSpawnParticle(last, PARTICLE_MIN_MS, { x: 5, y: 0 })).toBe(false);
    expect(shouldSpawnParticle(last, PARTICLE_MIN_MS, { x: 20, y: 0 })).toBe(true);
  });

  it('normalises stage points and refuses an unknown size', () => {
    expect(normalisePoint({ x: 150, y: 600 }, { width: 300, height: 400 })).toEqual({ x: 0.5, y: 1 });
    expect(normalisePoint({ x: 1, y: 1 }, { width: 0, height: 0 })).toBeNull();
  });
});
