/** GAMES_SPEC §4.4 (C1) — the 3-second rule as a pure state machine. */
import {
  CUDDLE_HEART_MS,
  CUDDLE_PAUSE_MS,
  CUDDLE_START,
  cuddleCues,
  cuddleLine,
  cuddleNextTick,
  cuddleStep,
  heartFill,
  type CuddleCue,
  type CuddleEvent,
  type CuddleState,
} from '@/modules/play/cuddleV2/cuddleMachine';

function run(events: CuddleEvent[], from: CuddleState = CUDDLE_START): { state: CuddleState; cues: CuddleCue[] } {
  let state = from;
  const cues: CuddleCue[] = [];
  for (const e of events) {
    const next = cuddleStep(state, e);
    cues.push(...cuddleCues(state, next));
    state = next;
  }
  return { state, cues };
}

/** A slow stroke from `at` for `ms` (a sample every 16 ms at `speed`). */
function stroke(at: number, ms: number, speed = 300): CuddleEvent[] {
  const events: CuddleEvent[] = [{ type: 'down', at }];
  for (let t = at; t <= at + ms; t += 16) events.push({ type: 'move', at: t, speed });
  events.push({ type: 'up', at: at + ms + 1 });
  return events;
}

describe('cuddles v2', () => {
  it('a slow 3 s stroke fills one heart, then waits; a 1 s pause asks for more', () => {
    const { state, cues } = run(stroke(0, 3050));
    expect(state).toMatchObject({ phase: 'paused', hearts: 1, fillMs: 0 });
    expect(cuddleLine(state)).toBe('wait');
    expect(cues.filter((c) => c.kind === 'haptic').length).toBe(5);
    expect(cues.filter((c) => c.kind === 'purr').length).toBe(2);
    expect(cues).toContainEqual({ kind: 'heart', hearts: 1 });
    const at = cuddleNextTick(state);
    expect(at).toBe(3051 + CUDDLE_PAUSE_MS);
    expect(cuddleStep(state, { type: 'tick', at: 3051 + CUDDLE_PAUSE_MS - 1 }).phase).toBe('paused');
    const asked = run([{ type: 'tick', at: at as number }], state);
    expect(asked.state.phase).toBe('ask');
    expect(asked.cues).toEqual([{ kind: 'ask' }]);
    expect(cuddleLine(asked.state)).toBe('more');
  });

  it('three hearts with pauses = done (one done cue)', () => {
    let s = run(stroke(0, 3050)).state;
    s = cuddleStep(s, { type: 'tick', at: 5000 });
    s = run(stroke(5000, 3050), s).state;
    s = cuddleStep(s, { type: 'tick', at: 10000 });
    const last = run(stroke(10000, 3050), s);
    expect(last.state).toMatchObject({ phase: 'done', hearts: 3 });
    expect(last.cues.filter((c) => c.kind === 'done')).toHaveLength(1);
    expect(cuddleLine(last.state)).toBe('done');
    // Done stays done.
    expect(cuddleStep(last.state, { type: 'down', at: 20000 })).toBe(last.state);
  });

  it('stroking too fast fills nothing and shows the 🐢 for 2 s', () => {
    const { state, cues } = run(stroke(0, 2000, 1500));
    expect(state.fillMs).toBe(0);
    expect(cues).toContainEqual({ kind: 'tooFast' });
    expect(cuddleNextTick(state)).toBe(state.tooFastUntil);
    expect(cuddleStep(state, { type: 'tick', at: (state.tooFastUntil as number) + 1 }).tooFastUntil).toBeNull();
  });

  it('not lifting the finger keeps the ✋ — nothing is lost; touching again too early waits again', () => {
    const full = run(stroke(0, 3050).slice(0, -1)).state;
    expect(full).toMatchObject({ phase: 'full', hearts: 1, touching: true });
    const more = run([{ type: 'move', at: 4000, speed: 200 }, { type: 'move', at: 4100, speed: 200 }], full).state;
    expect(more).toMatchObject({ phase: 'full', hearts: 1, fillMs: 0 });
    const lifted = cuddleStep(more, { type: 'up', at: 4200 });
    expect(lifted.phase).toBe('paused');
    const early = cuddleStep(lifted, { type: 'down', at: 4500 });
    expect(early.phase).toBe('full');
  });

  it('a partial stroke keeps its fill; a resting finger (no samples) fills nothing', () => {
    const half = run(stroke(0, 1500)).state;
    expect(half.phase).toBe('stroking');
    expect(half.fillMs).toBeGreaterThan(1400);
    const resting = run([{ type: 'down', at: 10000 }, { type: 'move', at: 10000, speed: 0 }, { type: 'move', at: 12000, speed: 0 }], half).state;
    expect(resting.fillMs - half.fillMs).toBeLessThanOrEqual(120);
    expect(heartFill(half, 0)).toBeCloseTo(half.fillMs / CUDDLE_HEART_MS);
    expect(heartFill(half, 1)).toBe(0);
  });

  it('»Pobožaj« held fills like slow stroking; a screen reader activate fills the heart and pauses', () => {
    let s: CuddleState = CUDDLE_START;
    for (let t = 0; t <= 3000; t += 100) s = cuddleStep(s, { type: 'hold', at: t, dtMs: 100 });
    expect(s).toMatchObject({ hearts: 1, phase: 'full' });
    s = cuddleStep(s, { type: 'up', at: 3100 });
    expect(s.phase).toBe('paused');
    const sr = cuddleStep(CUDDLE_START, { type: 'complete', at: 0 });
    expect(sr).toMatchObject({ hearts: 1, phase: 'paused', pausedAt: 0 });
    expect(cuddleStep(sr, { type: 'complete', at: 10 })).toBe(sr);
    expect(cuddleStep(sr, { type: 'tick', at: CUDDLE_PAUSE_MS }).phase).toBe('ask');
  });
});
