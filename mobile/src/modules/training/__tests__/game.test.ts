/**
 * M5-R03 mini-game logic: slots and outcomes mirror the server's
 * `TrainingService::score`, taps are recorded once per slot as whole ms, frames follow
 * the schedule, the session clock is monotonic and re-anchors after the background.
 */
import {
  addTap,
  canStillFinish,
  feedbackAt,
  firstTapsBySlot,
  frameAt,
  liveOutcome,
  MAX_TAPS,
  MAX_TOUCH_AGE_MS,
  missedWhileAway,
  SessionClock,
  TouchTimeMapper,
  slotIndex,
  trialOutcome,
} from '@/modules/training/game';
import { readTrainingSession, type TrainingSession, type TrialOutcome } from '@/modules/training/training';
import { makeTrainingSessionPayload } from '@/test-utils/fixtures';

const SESSION = readTrainingSession(makeTrainingSessionPayload()) as TrainingSession;

/** The server's scoring (PHP `TrainingService::score`) — a reference to compare against. */
function serverScore(session: TrainingSession, taps: number[]): TrialOutcome[] {
  const sorted = [...taps].sort((a, b) => a - b);
  return session.trials.map((trial, i) => {
    const from = trial.cue_at_ms;
    const to = session.trials[i + 1]?.cue_at_ms ?? Number.MAX_SAFE_INTEGER;
    const first = sorted.find((t) => t >= from && t < to) ?? null;
    if (trial.obeys && trial.obey_at_ms !== null) {
      if (first === null) return 'no_praise';
      if (first < trial.obey_at_ms + session.min_reaction_ms) return 'too_early';
      if (first <= trial.obey_at_ms + session.praise_window_ms) return 'in_time';
      return 'too_late';
    }
    return first === null ? 'waited' : 'praised_without_obeying';
  });
}

describe('slots and outcomes', () => {
  it('slotIndex: lead-in → null, [cue, next cue) → i, last slot open-ended', () => {
    expect(slotIndex(SESSION.trials, 0)).toBeNull();
    expect(slotIndex(SESSION.trials, 1_999)).toBeNull();
    expect(slotIndex(SESSION.trials, 2_000)).toBe(0);
    expect(slotIndex(SESSION.trials, 7_999)).toBe(0);
    expect(slotIndex(SESSION.trials, 8_000)).toBe(1);
    expect(slotIndex(SESSION.trials, 49_999)).toBe(7);
  });

  it('trialOutcome: early / in time (window inclusive) / late / none / waited / without obeying', () => {
    const obeys = SESSION.trials[0]; // obey 3000, window to 4500
    expect(trialOutcome(obeys, 2_999, 1_500)).toBe('too_early');
    expect(trialOutcome(obeys, 3_000, 1_500)).toBe('in_time');
    expect(trialOutcome(obeys, 4_500, 1_500)).toBe('in_time');
    expect(trialOutcome(obeys, 4_501, 1_500)).toBe('too_late');
    // PR #53 reaction floor: faster than 150 ms after the dog obeys can't be a reaction.
    expect(trialOutcome(obeys, 3_149, 1_500, 150)).toBe('too_early');
    expect(trialOutcome(obeys, 3_150, 1_500, 150)).toBe('in_time');
    expect(trialOutcome(obeys, 4_500, 1_500, 150)).toBe('in_time');
    expect(trialOutcome(obeys, 4_501, 1_500, 150)).toBe('too_late');
    expect(trialOutcome(obeys, null, 1_500)).toBe('no_praise');
    const ignores = SESSION.trials[1];
    expect(trialOutcome(ignores, null, 1_500)).toBe('waited');
    expect(trialOutcome(ignores, 9_000, 1_500)).toBe('praised_without_obeying');
  });

  it('local verdicts equal the server scoring for the same taps', () => {
    // 33_050 / 39_100: inside the reaction floor of cues 5 (obey 33_000) and 6 (obey 39_000).
    const taps = [500, 2_100, 3_400, 3_600, 9_500, 14_100, 15_200, 21_000, 26_500, 33_050, 39_100, 45_000];
    const firsts = firstTapsBySlot(SESSION.trials, taps);
    const local = SESSION.trials.map((_, i) => liveOutcome(SESSION, i, firsts.get(i) ?? null, SESSION.duration_ms));
    expect(local).toEqual(serverScore(SESSION, taps));
  });
});

describe('addTap', () => {
  it('keeps the first tap per slot as whole ms; ignores lead-in, repeats and taps after the end', () => {
    let taps: readonly number[] = [];
    taps = addTap(SESSION, taps, 1_000.4); // lead-in
    expect(taps).toEqual([]);
    taps = addTap(SESSION, taps, 3_100.6);
    taps = addTap(SESSION, taps, 3_300); // same slot
    taps = addTap(SESSION, taps, 9_000);
    taps = addTap(SESSION, taps, 50_001); // after the end
    expect(taps).toEqual([3_101, 9_000]);
    const same = addTap(SESSION, taps, 9_100);
    expect(same).toBe(taps);
  });

  it('never exceeds the server cap', () => {
    const many = Array.from({ length: MAX_TAPS }, (_, i) => i);
    expect(addTap(SESSION, many, 3_000)).toBe(many);
  });
});

describe('frameAt / feedbackAt', () => {
  it('lead-in → cue → dog obeys after its delay → window closes', () => {
    expect(frameAt(SESSION, 0)).toMatchObject({ slot: null, dog: 'waiting', cueVisible: false, secondsLeft: 50 });
    expect(frameAt(SESSION, 2_000)).toMatchObject({ slot: 0, dog: 'listening', cueVisible: true, windowOpen: false });
    expect(frameAt(SESSION, 3_000)).toMatchObject({ slot: 0, dog: 'obeying', windowOpen: false });
    expect(frameAt(SESSION, 3_150)).toMatchObject({ slot: 0, dog: 'obeying', windowOpen: true });
    expect(frameAt(SESSION, 4_600)).toMatchObject({ dog: 'obeying', windowOpen: false, cueVisible: false });
    expect(frameAt(SESSION, 6_000)).toMatchObject({ dog: 'listening' });
  });

  it('a cue the dog ignores shows it after a moment', () => {
    expect(frameAt(SESSION, 8_500)).toMatchObject({ slot: 1, dog: 'listening' });
    expect(frameAt(SESSION, 8_800)).toMatchObject({ slot: 1, dog: 'ignoring' });
  });

  it('over at the duration', () => {
    expect(frameAt(SESSION, 50_000)).toMatchObject({ over: true, slot: null, secondsLeft: 0, progress: 1 });
  });

  it('verdict appears at once for a tap; a "waited" shows briefly after the next cue', () => {
    expect(feedbackAt(SESSION, [], 2_500)).toBeNull();
    expect(feedbackAt(SESSION, [2_500], 2_500)).toEqual({ slot: 0, outcome: 'too_early' });
    expect(feedbackAt(SESSION, [3_200], 3_200)).toEqual({ slot: 0, outcome: 'in_time' });
    expect(feedbackAt(SESSION, [3_100], 3_100)).toEqual({ slot: 0, outcome: 'too_early' });
    // No tap: the missed window is final once it closed.
    expect(feedbackAt(SESSION, [], 4_600)).toEqual({ slot: 0, outcome: 'no_praise' });
    // Slot 1 (no obey) is only "waited" once slot 2 begins …
    expect(feedbackAt(SESSION, [], 13_000)).toBeNull();
    expect(feedbackAt(SESSION, [], 14_200)).toEqual({ slot: 1, outcome: 'waited' });
    // … and gives way after the carry time.
    expect(feedbackAt(SESSION, [], 15_600)).toBeNull();
    expect(feedbackAt(SESSION, [], 50_000)).toEqual({ slot: 7, outcome: 'waited' });
  });
});

describe('SessionClock', () => {
  it('measures monotonic ms and follows the wall clock after a sleep', () => {
    let mono = 1_000;
    let wall = 1_700_000_000_000;
    const clock = new SessionClock({ mono: () => mono, wall: () => wall });
    mono += 1_234.4;
    wall += 1_234;
    expect(clock.elapsed()).toBe(1_234);
    // The phone slept 30 s: the monotonic clock stood still, the wall clock didn't.
    wall += 30_000;
    expect(clock.elapsed()).toBe(1_234);
    clock.resync();
    expect(clock.elapsed()).toBe(31_234);
    // Small drift (< threshold) is left alone.
    wall += 100;
    clock.resync();
    expect(clock.elapsed()).toBe(31_234);
  });

  it('resumes a session that started earlier (app restart)', () => {
    let mono = 5;
    const wall = 1_700_000_000_000;
    const clock = new SessionClock({ mono: () => mono, wall: () => wall }, 12_000);
    expect(clock.elapsed()).toBe(12_000);
    expect(clock.wallElapsed()).toBe(12_000);
    mono += 500;
    expect(clock.elapsed()).toBe(12_500);
  });
});

describe('canStillFinish', () => {
  it('compares in server time with a safety margin', () => {
    const exp = Date.parse(SESSION.expires_at);
    expect(canStillFinish(SESSION, exp - 10_000)).toBe(true);
    expect(canStillFinish(SESSION, exp - 2_000)).toBe(false);
    expect(canStillFinish(SESSION, exp + 1)).toBe(false);
  });
});

describe('TouchTimeMapper (QA: tap time from the touch event)', () => {
  it('learns the clock offset from the touches; a slow handler no longer delays the tap', () => {
    const m = new TouchTimeMapper();
    // Event clock = mono − 1 000 000. First touch: handler ran 20 ms after the touch.
    expect(m.map(9_000, 1_009_020)).toBe(1_009_020); // best offset so far = handler time
    // Second touch: handler ran 180 ms late (busy JS) — mapped back to 20 ms after the touch.
    expect(m.map(15_000, 1_015_180)).toBe(1_015_020);
    // A faster handler improves the estimate.
    expect(m.map(20_000, 1_020_005)).toBe(1_020_005);
    expect(m.map(25_000, 1_025_100)).toBe(1_025_005);
  });

  it('missing / implausible timestamps fall back to the handler time', () => {
    const m = new TouchTimeMapper();
    expect(m.map(undefined, 500)).toBe(500);
    expect(m.map(null, 500)).toBe(500);
    expect(m.map(0, 500)).toBe(500);
    m.map(1_000, 1_010);
    // Mapped far older than allowed → handler time.
    expect(m.map(1_000, 1_010 + MAX_TOUCH_AGE_MS + 100)).toBe(1_010 + MAX_TOUCH_AGE_MS + 100);
  });

  it('SessionClock.elapsedAt measures a mapped instant', () => {
    let mono = 100;
    const clock = new SessionClock({ mono: () => mono, wall: () => 0 });
    mono = 3_400;
    expect(clock.elapsedAt(3_250)).toBe(3_150);
    expect(clock.monoNow()).toBe(3_400);
  });
});

describe('missedWhileAway (resume)', () => {
  it('cues whose chance ended before the resume are neutral; the current one is not', () => {
    // Resumed at 21 000: cue 0 window ended 4 500, cue 1 (no obey) slot ended 14 000,
    // cue 2 window 15 000–16 500, cue 3 obeys at 21 000 (still open).
    expect(missedWhileAway(SESSION, 0, 21_000)).toBe(true);
    expect(missedWhileAway(SESSION, 1, 21_000)).toBe(true);
    expect(missedWhileAway(SESSION, 2, 21_000)).toBe(true);
    expect(missedWhileAway(SESSION, 3, 21_000)).toBe(false);
    expect(missedWhileAway(SESSION, 0, null)).toBe(false);
    // No verdict is shown for them either.
    expect(feedbackAt(SESSION, [], 20_500, 20_000)).toBeNull();
    expect(feedbackAt(SESSION, [], 20_500)).toEqual({ slot: 2, outcome: 'no_praise' });
  });
});

