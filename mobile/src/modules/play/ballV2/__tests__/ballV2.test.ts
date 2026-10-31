import { CLIP_END_GRACE_MS, createClipQueue, requestClip, clipEnded, type ClipQueueState } from '@/modules/gameLayer/clipQueue';
import { deckPlayerUrls } from '@/modules/gameLayer/deck';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { ballDeck, ballWaitingKey } from '@/modules/play/ballV2/ballDeck';
import {
  BALL_LAND_MS,
  BALL_V2_START,
  CALL_WAIT_MS,
  CATCH_WINDOW_EASY_MS,
  CATCH_WINDOW_MS,
  ballCues,
  ballDots,
  ballLine,
  ballRole,
  ballStep,
  ballTimer,
  type BallEvent,
  type BallGameState,
} from '@/modules/play/ballV2/ballMachine';
import {
  BALL_RADIUS_PT,
  CATCH_TARGET_PT,
  HAND_LINE,
  ballAtRest,
  ballInFlight,
  ballReturning,
  classifyRelease,
  isCatchTap,
  THROW_MIN_PULL_PT,
  THROW_MIN_SPEED_PT_S,
} from '@/modules/play/ballV2/ballFlight';
import { makeMedia } from '@/test-utils/fixtures';

function run(events: BallEvent[], from: BallGameState = BALL_V2_START): BallGameState {
  return events.reduce(ballStep, from);
}

const ONE_THROW: BallEvent[] = [{ type: 'throw', power: 1 }, { type: 'runEnded' }, { type: 'call' }, { type: 'fetchEnded' }, { type: 'catch' }];

describe('ball v2 round (GAMES_SPEC §4.3)', () => {
  it('a throw is throw → run → call → return → roll → catch, back in the hand', () => {
    const states = [BALL_V2_START];
    for (const e of ONE_THROW) states.push(ballStep(states[states.length - 1], e));
    expect(states.map((s) => s.phase)).toEqual(['aim', 'flying', 'call', 'returning', 'rolling', 'aim']);
    expect(states.map(ballRole)).toEqual(['waiting', 'running', 'running', 'fetching', 'waiting', 'waiting']);
    expect(states[5]).toMatchObject({ throws: 1, stars: 1 });
  });

  it('3 throws = done; »Še en krog« starts round 2 from zero', () => {
    const done = run([...ONE_THROW, ...ONE_THROW, ...ONE_THROW]);
    expect(done).toMatchObject({ phase: 'done', throws: 3, stars: 3, round: 1 });
    expect(ballStep(done, { type: 'throw', power: 1 })).toBe(done);
    expect(ballStep(done, { type: 'again' })).toEqual({ ...BALL_V2_START, round: 2 });
  });

  it('the call is optional: after the wait the dog comes back by itself; a missed ball is no ✗', () => {
    const s = run([{ type: 'throw', power: 0.5 }, { type: 'runEnded' }]);
    expect(ballTimer(s, false)).toEqual({ event: { type: 'callTimeout' }, ms: CALL_WAIT_MS });
    const back = ballStep(s, { type: 'callTimeout' });
    expect(back).toMatchObject({ phase: 'returning', called: false });
    expect(ballCues(s, back)).toEqual([]);
    const rolling = ballStep(back, { type: 'fetchEnded' });
    expect(ballTimer(rolling, false)?.ms).toBe(CATCH_WINDOW_MS);
    expect(ballTimer(rolling, true)?.ms).toBe(CATCH_WINDOW_EASY_MS);
    const missed = ballStep(rolling, { type: 'catchTimeout' });
    expect(missed).toMatchObject({ phase: 'aim', stars: 0 });
    expect(ballCues(rolling, missed)).toEqual([]);
  });

  it('anti-spam: no throw while the dog is out, events out of turn are ignored', () => {
    const flying = run([{ type: 'throw', power: 1 }]);
    expect(ballStep(flying, { type: 'throw', power: 1 })).toBe(flying);
    expect(ballStep(flying, { type: 'catch' })).toBe(flying);
    expect(ballStep(flying, { type: 'call' })).toBe(flying);
    expect(ballStep(BALL_V2_START, { type: 'runEnded' })).toBe(BALL_V2_START);
  });

  it('cues: ✓ + whoosh + bounce + tap on a throw; ✓ + whistle on a call; ★ + chime + success on a catch', () => {
    const flying = ballStep(BALL_V2_START, { type: 'throw', power: 1 });
    expect(ballCues(BALL_V2_START, flying)).toEqual([
      { kind: 'feedback', feedback: 'ok', label: 'throw' },
      { kind: 'sound', sound: 'whoosh', delayMs: 0 },
      { kind: 'sound', sound: 'bounce', delayMs: BALL_LAND_MS },
      { kind: 'haptic', haptic: 'tap' },
    ]);
    const call = ballStep(flying, { type: 'runEnded' });
    const called = ballStep(call, { type: 'call' });
    expect(ballCues(call, called)).toEqual([
      { kind: 'feedback', feedback: 'ok', label: 'called' },
      { kind: 'sound', sound: 'whistle', delayMs: 0 },
      { kind: 'haptic', haptic: 'tap' },
    ]);
    const rolling = ballStep(called, { type: 'fetchEnded' });
    const caught = ballStep(rolling, { type: 'catch' });
    expect(ballCues(rolling, caught)).toEqual([
      { kind: 'feedback', feedback: 'star', label: 'caught' },
      { kind: 'sound', sound: 'chime', delayMs: 0 },
      { kind: 'haptic', haptic: 'success' },
    ]);
  });

  it('the round is reported once, when it is done (caught or not)', () => {
    const lastRolling = run([...ONE_THROW, ...ONE_THROW, { type: 'throw', power: 1 }, { type: 'runEnded' }, { type: 'callTimeout' }, { type: 'fetchEnded' }]);
    const done = ballStep(lastRolling, { type: 'catchTimeout' });
    expect(ballCues(lastRolling, done)).toEqual([{ kind: 'roundDone', round: 1, stars: 2 }]);
    expect(ballCues(done, done)).toEqual([]);
  });

  it('never a negative haptic or feedback in any cue', () => {
    const all: BallEvent['type'][] = ['throw', 'runEnded', 'call', 'callTimeout', 'fetchEnded', 'catch', 'catchTimeout', 'again'];
    let s = BALL_V2_START;
    for (let i = 0; i < 60; i += 1) {
      const type = all[(i * 7) % all.length];
      const next = ballStep(s, type === 'throw' ? { type, power: 1 } : ({ type } as BallEvent));
      for (const cue of ballCues(s, next)) {
        if (cue.kind === 'haptic') expect(['tap', 'success']).toContain(cue.haptic);
        if (cue.kind === 'feedback') expect(['ok', 'star']).toContain(cue.feedback);
      }
      s = next;
    }
  });

  it('the rule line only before the very first throw; dots per throw', () => {
    expect(ballLine(BALL_V2_START)).toBe('hint');
    const after = run(ONE_THROW);
    expect(ballLine(after)).toBeNull();
    expect(ballDots(after)).toEqual([true, false, false]);
    expect(ballLine({ ...BALL_V2_START, round: 2 })).toBeNull();
  });
});

describe('ball v2 flight (2.5D)', () => {
  const size = { width: 400, height: 800 };

  it('rests in the hand (bottom third), above its shadow', () => {
    const f = ballAtRest(size);
    expect(f.x).toBe(200);
    expect(f.r).toBe(BALL_RADIUS_PT);
    expect(f.y + f.r).toBeCloseTo(HAND_LINE * size.height);
    expect(f.y).toBeGreaterThan(size.height * 0.66);
    const dragged = ballAtRest(size, 10, -40);
    expect(dragged.x).toBe(210);
    expect(dragged.y).toBe(f.y - 40);
  });

  it('flies into the room (smaller), bounces and leaves the frame on the exit side', () => {
    const start = ballInFlight(size, 0, 1, 'right');
    const top = ballInFlight(size, 0.25, 1, 'right');
    const end = ballInFlight(size, 1, 1, 'right');
    expect(start.x).toBeCloseTo(200);
    expect(top.r).toBeLessThan(start.r);
    expect(top.y).toBeLessThan(start.y);
    expect(end.x - end.r).toBeGreaterThan(size.width);
    expect(ballInFlight(size, 1, 1, 'left').x + end.r).toBeLessThan(0);
    // Unknown / center: straight into the distance, fading out.
    const far = ballInFlight(size, 1, 1, null);
    expect(far.x).toBeCloseTo(200);
    expect(far.opacity).toBeCloseTo(0);
    // More power = a higher arc (picture only).
    expect(ballInFlight(size, 0.25, 1, 'left').y).toBeLessThan(ballInFlight(size, 0.25, 0, 'left').y);
  });

  it('starts from where the finger let go and the return ends in the hand', () => {
    const from = ballInFlight(size, 0, 1, 'left', 30, -60);
    expect(from.x).toBeCloseTo(230);
    const rest = ballAtRest(size);
    const back = ballReturning(size, 1);
    expect(back.x).toBeCloseTo(rest.x);
    expect(back.y).toBeCloseTo(rest.y);
    expect(ballReturning(size, 0).r).toBeLessThan(rest.r);
  });

  it('the rolling ball is caught by a tap within its 96 pt target', () => {
    const f = ballAtRest(size);
    expect(isCatchTap(f, f.x + CATCH_TARGET_PT / 2 - 1, f.y)).toBe(true);
    expect(isCatchTap(f, f.x, f.y + 70)).toBe(false);
  });

  it('a throw: up ≥ 30 pt AND fast enough; anything else goes back to the hand', () => {
    expect(classifyRelease(-THROW_MIN_PULL_PT + 1, -2_000)).toBeNull();
    expect(classifyRelease(-120, -THROW_MIN_SPEED_PT_S + 1)).toBeNull();
    expect(classifyRelease(80, 2_000)).toBeNull();
    expect(classifyRelease(Number.NaN, -500)).toBeNull();
    expect(classifyRelease(-40, -THROW_MIN_SPEED_PT_S)).toBe(0);
    expect(classifyRelease(-200, -9_000)).toBe(1);
  });
});

describe('ball v2 deck (§8.1)', () => {
  const ballMedia = (extra: Record<string, unknown> = {}) =>
    normalizePetMedia(
      makeMedia({
        status: 'ready',
        reference_image_url: 'https://x/ref.png',
        poses: { empty: 'https://x/empty.png' },
        phases: { ball_waiting: 'https://x/w.mp4', ball_running: 'https://x/r.mp4', ball_fetching: 'https://x/f.mp4', ...extra },
        phase_groups: ['ball'],
        phase_seconds: { ball_running: 4, ball_fetching: 3 },
        ...({ phase_meta: { ball_running: { exit_side: 'left' } } } as object),
      }),
    );

  it('reads exit_side and the derived waiting loop', () => {
    expect(ballMedia().phaseMeta.ballExitSide).toBe('left');
    expect(ballWaitingKey(ballMedia())).toBe('ball_waiting');
    expect(ballWaitingKey(ballMedia({ ball_waiting_loop: 'https://x/wl.mp4' }))).toBe('ball_waiting_loop');
    expect(normalizePetMedia(makeMedia({ ...({ phase_meta: { ball_running: { exit_side: 'up' } } } as object) })).phaseMeta.ballExitSide).toBeNull();
  });

  it('uses ball_waiting_loop when present, else loops ball_waiting', () => {
    const plain = ballDeck(ballMedia(), 'video');
    expect(plain.clips[0]).toMatchObject({ role: 'waiting', url: 'https://x/w.mp4', loop: true });
    const looped = ballDeck(ballMedia({ ball_waiting_loop: 'https://x/wl.mp4' }), 'video');
    expect(looped.clips[0]).toMatchObject({ role: 'waiting', url: 'https://x/wl.mp4', loop: true });
    expect(looped.clips[1]).toMatchObject({ role: 'running', url: 'https://x/r.mp4', loop: false, startStill: 'https://x/ref.png', picture: 'https://x/empty.png' });
    expect(looped.clips[2]).toMatchObject({ role: 'fetching', url: 'https://x/f.mp4', loop: false, startStill: 'https://x/empty.png' });
    expect(deckPlayerUrls(looped.clips)).toHaveLength(3);
  });

  it('the chain never jumps: hub → run (crossfade), run end → return (same frame), return end → hub (same frame)', () => {
    const plan = ballDeck(ballMedia(), 'video');
    expect(plan.specs.find((s) => s.role === 'running')).toMatchObject({ durationMs: 4_000, endGraceMs: CLIP_END_GRACE_MS });
    let q: ClipQueueState = createClipQueue(plan.specs, plan.initial, 0);
    q = requestClip(q, 'running', 0);
    expect(q.lastSwitch?.kind).toBe('crossfade');
    q = clipEnded(q, 'running', q.instance, 4_000);
    q = requestClip(q, 'fetching', 5_000);
    expect(q.current).toBe('fetching');
    expect(q.lastSwitch?.kind).toBe('instant');
    q = clipEnded(q, 'fetching', q.instance, 8_000);
    q = requestClip(q, 'waiting', 8_000);
    expect(q.current).toBe('waiting');
    expect(q.lastSwitch?.kind).toBe('instant');
  });

  it('picture mode: no clip, the empty room while the dog is out, the game runs on the stored lengths', () => {
    const plan = ballDeck(ballMedia(), 'picture');
    expect(plan.clips.every((c) => c.url === null)).toBe(true);
    expect(plan.clips.map((c) => c.picture)).toEqual(['https://x/ref.png', 'https://x/empty.png', 'https://x/ref.png']);
    expect(plan.specs.every((s) => s.endGraceMs === 0)).toBe(true);
  });

  it('an older set without ball clips: the playing loop stands in for every role (one player)', () => {
    const media = normalizePetMedia(makeMedia({ status: 'ready', reference_image_url: 'https://x/ref.png', videos: { idle: 'https://x/idle.mp4', playing: 'https://x/play.mp4' }, states: ['idle', 'playing'] }));
    const plan = ballDeck(media, 'video');
    expect(plan.clips.map((c) => c.url)).toEqual(['https://x/play.mp4', 'https://x/play.mp4', 'https://x/play.mp4']);
    expect(deckPlayerUrls(plan.clips)).toEqual(['https://x/play.mp4']);
    expect(plan.specs.map((s) => [s.startPose, s.endPose, s.durationMs])).toEqual([
      ['stand', 'stand', 0],
      ['stand', 'stand', 3_000],
      ['stand', 'stand', 3_000],
    ]);
  });
});
