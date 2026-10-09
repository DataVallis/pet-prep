/**
 * M5-R06-08a pure game logic. The scorers mirror the server's
 * (`WandPlayService::score`, `CatChoreService::score`, `ScratchingService::score`) — same
 * counting, same thresholds, same order of reasons — and the finger → move / stroke
 * recorders produce what the server scores (one move per feather stroke, `{t, away}`).
 */
import {
  addStroke,
  addWandMove,
  beginRub,
  beginWandStroke,
  CATCH_LEAD_MS,
  choreProgress,
  choreStepAt,
  CROUCH_MS,
  countedTimes,
  endRub,
  endWandStroke,
  isTooUniform,
  moveRub,
  moveWandStroke,
  pounceStatuses,
  scoreScratching,
  scoreStrokes,
  scoreWand,
  scratchingFinishAt,
  scratchingFrameAt,
  segmentOf,
  wandFeedbackAt,
  wandFrameAt,
  wandSegmentsHit,
  WAND_MAX_MOVES,
  type Point,
  type WandMove,
  type WandTracker,
} from '@/modules/catCare/catGames';

const WAND = {
  duration_ms: 60_000,
  catch_at_ms: 60_000,
  pounces_ms: [15_000, 30_000, 45_000],
  min_away_moves: 8,
  segments: 4,
  min_move_interval_ms: 300,
  pounce_window_ms: 2_000,
};

/** A human-looking game: away moves with irregular gaps, one right after each pounce. */
const GOOD_TIMES = [2_100, 6_900, 11_300, 15_400, 19_800, 24_700, 30_600, 33_200, 38_900, 45_900, 49_100, 55_300];
const good = (): WandMove[] => GOOD_TIMES.map((t) => ({ t, away: true }));

describe('scoreWand (mirror of WandPlayService::score)', () => {
  it('a real game counts', () => {
    expect(scoreWand(good(), WAND)).toEqual({ success: true, reason: null, away_moves: 12, toward_moves: 0, segments_hit: 4, pounces_hit: 3 });
  });

  it('too few away moves (moves < 300 ms apart count once)', () => {
    const spam = [1_000, 1_100, 1_200, 1_250].map((t) => ({ t, away: true }));
    expect(scoreWand(spam, WAND)).toMatchObject({ success: false, reason: 'too_few_moves', away_moves: 1 });
  });

  it('not spread over all quarters', () => {
    const early = [500, 1_700, 3_100, 4_900, 6_200, 8_800, 10_100, 13_900, 14_600].map((t) => ({ t, away: true }));
    expect(scoreWand(early, WAND)).toMatchObject({ reason: 'not_spread', segments_hit: 1 });
  });

  it('wrong technique: fewer than half of the moves go away from the cat', () => {
    const toward = [1_000, 3_500, 8_000, 9_100, 12_000, 17_300, 21_000, 22_900, 26_000, 34_400, 36_700, 41_000, 48_200, 51_000, 52_600, 58_000, 58_900].map(
      (t) => ({ t, away: false }),
    );
    expect(scoreWand([...good(), ...toward], WAND)).toMatchObject({ reason: 'wrong_technique' });
  });

  it('missed pounce: no away move within 2 s after one', () => {
    const moves = good().filter((m) => m.t !== 30_600);
    // 30 600 was the only answer to the 30 s pounce; add a late one instead.
    moves.push({ t: 32_400, away: true });
    expect(scoreWand(moves, WAND)).toMatchObject({ reason: 'missed_pounces', pounces_hit: 2 });
  });

  it('too uniform: ≥ 6 gaps all within 60 ms (scripted)', () => {
    const scripted: WandMove[] = [];
    for (let t = 1_000; t < 60_000; t += 1_000) scripted.push({ t, away: true });
    // Pounces at whole seconds are answered by the move at that very second.
    expect(scoreWand(scripted, WAND)).toMatchObject({ reason: 'too_uniform' });
    expect(isTooUniform([0, 1_000, 2_000, 3_000, 4_000, 5_000], 6, 60)).toBe(false); // only 5 gaps
  });

  it('a schedule without pounces needs no answers', () => {
    expect(scoreWand(good(), { ...WAND, pounces_ms: [] }).success).toBe(true);
  });
});

describe('wand moves', () => {
  it('addWandMove keeps bounds and the 600 cap', () => {
    expect(addWandMove([], { t: -1, away: true }, 60_000)).toEqual([]);
    expect(addWandMove([], { t: 60_001, away: true }, 60_000)).toEqual([]);
    expect(addWandMove([], { t: 1_234.6, away: false }, 60_000)).toEqual([{ t: 1_235, away: false }]);
    const full = Array.from({ length: WAND_MAX_MOVES }, (_, i) => ({ t: i, away: true }));
    expect(addWandMove(full, { t: 5, away: true }, 60_000)).toBe(full);
  });

  const CAT: Point = { x: 150, y: 400 };

  /** Drag along points (t increasing by `stepMs`), collecting the moves. */
  function drag(points: Point[], t0: number, stepMs = 16): WandMove[] {
    const moves: WandMove[] = [];
    let tracker: WandTracker = beginWandStroke(points[0], t0, CAT);
    points.slice(1).forEach((p, i) => {
      const step = moveWandStroke(tracker, p, t0 + (i + 1) * stepMs, CAT);
      tracker = step.tracker;
      if (step.move) moves.push(step.move);
    });
    const end = endWandStroke(tracker, null, t0 + points.length * stepMs, CAT);
    if (end) moves.push(end);
    return moves;
  }

  const line = (from: Point, to: Point, n: number): Point[] =>
    Array.from({ length: n + 1 }, (_, i) => ({ x: from.x + ((to.x - from.x) * i) / n, y: from.y + ((to.y - from.y) * i) / n }));

  it('a stroke away from the cat is one "away" move at its furthest point', () => {
    const moves = drag(line({ x: 150, y: 300 }, { x: 150, y: 180 }, 10), 5_000);
    expect(moves).toEqual([{ t: 5_000 + 10 * 16, away: true }]);
  });

  it('a stroke towards the cat is a "toward" move', () => {
    expect(drag(line({ x: 150, y: 150 }, { x: 150, y: 300 }, 10), 0)).toEqual([{ t: 160, away: false }]);
  });

  it('back and forth without lifting: one move per direction, timed at the turning point', () => {
    const out = line({ x: 150, y: 300 }, { x: 150, y: 200 }, 10);
    const back = line({ x: 150, y: 200 }, { x: 150, y: 290 }, 9).slice(1);
    const moves = drag([...out, ...back], 0);
    expect(moves).toEqual([
      { t: 160, away: true },
      { t: 19 * 16, away: false },
    ]);
  });

  it('circling around the cat (no change of distance) is no move', () => {
    const circle = Array.from({ length: 40 }, (_, i) => ({ x: CAT.x + 150 * Math.cos(i / 10), y: CAT.y - 150 * Math.sin(i / 10) }));
    expect(drag(circle, 0)).toEqual([]);
  });

  it('a tiny wiggle is no move; a very long sweep is split by path length', () => {
    expect(drag(line({ x: 150, y: 300 }, { x: 150, y: 290 }, 4), 0)).toEqual([]);
    const sweep = drag(line({ x: 150, y: 390 }, { x: 150, y: -10 }, 40), 0);
    expect(sweep.length).toBeGreaterThanOrEqual(2);
    expect(sweep.every((m) => m.away)).toBe(true);
  });
});

describe('wand frame, pounces, feedback', () => {
  it('stalks, crouches before a pounce, pounces, catches at the end', () => {
    expect(wandFrameAt(WAND, 5_000)).toMatchObject({ cat: 'stalking', pounceOpen: null, segment: 0, secondsLeft: 55 });
    expect(wandFrameAt(WAND, 15_000 - CROUCH_MS + 1).cat).toBe('crouching');
    expect(wandFrameAt(WAND, 15_100)).toMatchObject({ cat: 'pouncing', pounceOpen: 0 });
    expect(wandFrameAt(WAND, 16_500)).toMatchObject({ cat: 'stalking', pounceOpen: 0 });
    expect(wandFrameAt(WAND, 17_100).pounceOpen).toBeNull();
    expect(wandFrameAt(WAND, 60_000 - CATCH_LEAD_MS).cat).toBe('catching');
    expect(wandFrameAt(WAND, 60_000)).toMatchObject({ cat: 'caught', over: true, secondsLeft: 0, segment: 3 });
  });

  it('pounce statuses follow the counted away moves', () => {
    const moves: WandMove[] = [
      { t: 15_500, away: true },
      { t: 30_200, away: false },
    ];
    expect(pounceStatuses(WAND, moves, 31_000)).toEqual(['answered', 'open', 'upcoming']);
    expect(pounceStatuses(WAND, moves, 33_000)).toEqual(['answered', 'missed', 'upcoming']);
  });

  it('quarter dots: only away moves count', () => {
    expect([...wandSegmentsHit(WAND, [{ t: 1_000, away: true }, { t: 20_000, away: false }, { t: 59_000, away: true }])].sort()).toEqual([0, 3]);
  });

  it('feedback for the newest move only while it is fresh', () => {
    expect(wandFeedbackAt(WAND, [{ t: 10_000, away: true }], 10_500)).toBe('away');
    expect(wandFeedbackAt(WAND, [{ t: 10_000, away: false }], 10_500)).toBe('toward');
    expect(wandFeedbackAt(WAND, [{ t: 15_600, away: true }], 15_700)).toBe('pounce');
    expect(wandFeedbackAt(WAND, [{ t: 10_000, away: true }], 12_000)).toBeNull();
    expect(wandFeedbackAt(WAND, [], 1_000)).toBeNull();
  });
});

describe('stroke games (mirror of CatChoreService::score)', () => {
  const GROOM = { duration_ms: 30_000, min_strokes: 10, segments: 3, min_stroke_interval_ms: 150 };
  const human = [800, 2_300, 3_100, 5_900, 8_200, 10_700, 12_000, 14_800, 18_100, 20_500, 23_900, 27_300];

  it('enough strokes over all thirds count', () => {
    expect(scoreStrokes(human, GROOM)).toEqual({ success: true, reason: null, strokes: 12, segments_hit: 3 });
  });

  it('too few (strokes < 150 ms apart count once)', () => {
    expect(scoreStrokes([1_000, 1_050, 1_100, 5_000], GROOM)).toMatchObject({ reason: 'too_few_strokes', strokes: 2 });
  });

  it('not spread', () => {
    expect(scoreStrokes([500, 1_000, 1_700, 2_300, 3_000, 3_600, 4_400, 5_000, 6_100, 7_000, 8_200], GROOM)).toMatchObject({ reason: 'not_spread' });
  });

  it('too uniform: ≥ 8 gaps within 40 ms', () => {
    const scripted = Array.from({ length: 12 }, (_, i) => 1_000 + i * 2_500);
    expect(scoreStrokes(scripted, GROOM)).toMatchObject({ reason: 'too_uniform' });
  });

  it('matted grooming: 20 strokes in 60 s', () => {
    const matted = { ...GROOM, duration_ms: 60_000, min_strokes: 20 };
    expect(scoreStrokes(human, matted)).toMatchObject({ reason: 'too_few_strokes' });
  });

  it('addStroke bounds, progress, steps', () => {
    expect(addStroke([], 31_000, 30_000)).toEqual([]);
    expect(addStroke([], 12.4, 30_000)).toEqual([12]);
    expect(choreProgress(GROOM, [100, 200, 15_000])).toEqual({ strokes: 2, segmentsHit: new Set([0, 1]) });
    expect(choreStepAt(GROOM, 0)).toBe(1);
    expect(choreStepAt(GROOM, 10_000)).toBe(2);
    expect(choreStepAt(GROOM, 29_999)).toBe(3);
    expect(choreStepAt(GROOM, 31_000)).toBe(3);
    expect(segmentOf(30_000, 30_000, 3)).toBe(2);
    expect(countedTimes([300, 100, 200], 150)).toEqual([100, 300]);
  });

  it('rubbing: one stroke per lift, one per turn while rubbing back and forth', () => {
    let rub = beginRub({ x: 0, y: 0 }, 0);
    const strokes: number[] = [];
    const path: Array<[number, number]> = [
      [20, 100],
      [50, 200],
      [30, 300], // turned back after 50 pt → stroke at 200 ms
      [0, 400],
      [-10, 500],
      [40, 600], // turned again → stroke at 500 ms
    ];
    for (const [x, t] of path) {
      const step = moveRub(rub, { x, y: 0 }, t);
      rub = step.tracker;
      if (step.stroke !== null) strokes.push(step.stroke);
    }
    expect(strokes).toEqual([200, 500]);
    expect(endRub(rub, 700)).toBe(700);
    expect(endRub(beginRub({ x: 0, y: 0 }, 0), 100)).toBeNull();
  });
});

describe('scratching (mirror of ScratchingService::score)', () => {
  const R = { land_at_ms: 1_200, praise_window_ms: 3_000, min_reaction_ms: 150 };

  it('in time = 150 ms … 3 s after the landing', () => {
    expect(scoreScratching(1_350, R)).toEqual({ success: true, reason: null, delay_ms: 150 });
    expect(scoreScratching(4_200, R)).toMatchObject({ success: true, delay_ms: 3_000 });
    expect(scoreScratching(1_349, R)).toMatchObject({ reason: 'too_early' });
    expect(scoreScratching(500, R)).toMatchObject({ reason: 'too_early', delay_ms: -700 });
    expect(scoreScratching(4_201, R)).toMatchObject({ reason: 'too_late' });
    expect(scoreScratching(null, R)).toMatchObject({ reason: 'no_praise', delay_ms: null });
  });

  it('frames and the finish moment (never before the landing)', () => {
    expect(scratchingFrameAt(R, 600)).toEqual({ phase: 'carrying', carry: 0.5 });
    expect(scratchingFrameAt(R, 1_200).phase).toBe('landed');
    expect(scratchingFrameAt(R, 4_300).phase).toBe('over');
    expect(scratchingFinishAt(R, null)).toBe(4_200);
    expect(scratchingFinishAt(R, 2_000)).toBe(2_000);
    expect(scratchingFinishAt(R, 400)).toBe(1_200);
  });
});
