/**
 * The care games on the game layer (GAMES_SPEC §5.5 / §7.7: grooming / brushing, the weekly
 * litter change, the breed care tasks — teeth, folds, ears, nails — and the scratching post),
 * pure. NO rule changes: the server's session (segments, strokes, windows) and verdict stay
 * exactly as they were; this only decides the PRESENTATION cues of `CareGameShell`:
 *
 * - one soft haptic per COUNTED stroke (the server's merge interval — `choreProgress`);
 * - a ✓ at the end of every step that has a counted stroke (steps = the server's segments,
 *   3 → 3 dots); the last step also ends early once the game is complete (all steps hit and
 *   `min_strokes` reached — the moment a clean coat may finish early, `brushFinishAt`);
 * - never anything for a miss here (a step without a stroke just stays an open dot).
 */

export interface CareFrame {
  /** Counted strokes so far. */
  strokes: number;
  /** Per step: done (✓ shown). */
  stepsDone: boolean[];
}

export interface CareFrameRules {
  duration_ms: number;
  segments: number;
  min_strokes: number;
}

/** The frame at `elapsedMs` for the counted strokes and the steps that have one (`segmentsHit`). */
export function careFrame(rules: CareFrameRules, counted: number, segmentsHit: ReadonlySet<number>, elapsedMs: number): CareFrame {
  const n = Math.max(1, rules.segments);
  const complete = counted >= rules.min_strokes && segmentsHit.size >= n;
  const stepsDone = Array.from({ length: n }, (_, i) => {
    if (!segmentsHit.has(i)) return false;
    const end = ((i + 1) * rules.duration_ms) / n;
    return elapsedMs >= end || (i === n - 1 && complete);
  });
  return { strokes: counted, stepsDone };
}

export type CareCue = { kind: 'stroke' } | { kind: 'step'; step: number };

export function careCues(prev: CareFrame | null, next: CareFrame): CareCue[] {
  const cues: CareCue[] = [];
  if (prev !== null && next.strokes > prev.strokes) cues.push({ kind: 'stroke' });
  next.stepsDone.forEach((done, i) => {
    if (done && prev?.stepsDone[i] !== true) cues.push({ kind: 'step', step: i });
  });
  return cues;
}
