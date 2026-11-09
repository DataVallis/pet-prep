/**
 * GAMES_SPEC §5.2–§5.4 — the simple wand hunt (David 2026-11-08 »naredi bolj preprosto igro«):
 * let go while the cat crouches = a catch. The timeline cases are the server's
 * (`backend/tests/Feature/WandSimpleHuntTest.php`) one by one; plus the hunt at a moment, the
 * cues, the stage, the finish moment, the readers and the texts.
 */
import { i18n } from '@/i18n';
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import {
  addWandRelease,
  readWandHuntResult,
  readWandHuntSession,
  scoreWandHunt,
  wandCues,
  wandDots,
  wandHuntAt,
  wandHuntFinishAt,
  wandNextBoundary,
  wandStageAt,
  wandTimeline,
  type WandCue,
  type WandHuntSession,
  type WandHuntState,
} from '@/modules/catCare/wandV2/wandRules';
import { wandV2LineTexts } from '@/modules/catCare/wandV2/wandStrings';

const BASE = {
  id: '9b1f0c1e-4c1a-4b8e-9d1e-0f2a3b4c5d6e',
  started_at: '2026-11-08T11:00:00Z',
  ends_at: '2026-11-08T11:00:50Z',
  expires_at: '2026-11-08T11:01:50Z',
};

/** The fixed hunt of the server test (wsSchedule). */
function hunt(): WandHuntSession {
  return {
    ...BASE,
    rules_version: 3,
    duration_ms: 50500,
    stalk_ms: 3000,
    first_cue_ms: 4500,
    cue_window_ms: 2000,
    after_catch_ms: 6000,
    after_miss_ms: 2500,
    max_cues: 6,
    catches_needed: 3,
    catch_clip_ms: 3500,
  };
}

describe('the rule — the server cases (WandSimpleHuntTest)', () => {
  const s = hunt();

  it('let go during the cue = a catch, at its first or its last moment; the next cue 6 s after the let-go', () => {
    const t = wandTimeline([4500], s);
    expect(t.cues[0]).toEqual({ cue_ms: 4500, caught: true, release_ms: 4500 });
    expect(t.cues[1].cue_ms).toBe(10500);
    const late = wandTimeline([6500], s);
    expect(late.cues[0].caught).toBe(true);
    expect(late.cues[1].cue_ms).toBe(12500);
  });

  it('too early: nothing happens (no catch, no fault), the cat crouches again 2.5 s after the window', () => {
    const r = scoreWandHunt([1000, 4499], s);
    expect(r.cues[0]).toEqual({ cue_ms: 4500, caught: false, release_ms: null });
    expect(r.cues[1].cue_ms).toBe(9000);
    expect(r).toMatchObject({ stars: 0, success: false, reason: 'too_few_catches', hint: 'let_go' });
  });

  it('never letting go: six cues pass, no ★, done after the last window', () => {
    const r = scoreWandHunt([], s);
    expect(r.cues.map((c) => c.cue_ms)).toEqual([4500, 9000, 13500, 18000, 22500, 27000]);
    expect(r.stars).toBe(0);
    expect(wandTimeline([], s).done_ms).toBe(29000);
    expect(wandHuntFinishAt(s, [])).toBe(29000);
  });

  it('three catches = the hunt is done and counts; a tap during a catch changes nothing', () => {
    const releases = [5200, 8000, 12000, 18500];
    const r = scoreWandHunt(releases, s);
    expect(r).toMatchObject({ success: true, reason: null, hint: null, stars: 3, min_stars: 3, max_stars: 3 });
    expect(r.cues.map((c) => c.cue_ms)).toEqual([4500, 11200, 18000]);
    expect(wandHuntFinishAt(s, releases)).toBe(18500 + 3500);
    const mixed = scoreWandHunt([5000, 16000, 23000], s);
    expect(mixed.cues.map((c) => c.caught)).toEqual([true, false, true, true]);
    expect(mixed.success).toBe(true);
  });
});

describe('the hunt at a moment', () => {
  const s = hunt();

  it('waiting → 👀 cue → catching → waiting → … → over, equal to the timeline for every decided cue', () => {
    expect(wandHuntAt(s, [], 1000).moment).toEqual({ kind: 'waiting' });
    expect(wandHuntAt(s, [], 4500).moment).toEqual({ kind: 'cue', index: 0, cueMs: 4500, endsMs: 6500 });
    expect(wandHuntAt(s, [5200], 5200).moment).toEqual({ kind: 'catching', index: 0, releaseMs: 5200 });
    expect(wandHuntAt(s, [5200], 8700).moment).toEqual({ kind: 'waiting' });
    expect(wandHuntAt(s, [5200], 11200).moment).toMatchObject({ kind: 'cue', index: 1 });
    const releases = [5200, 12000, 18500];
    const end = wandHuntAt(s, releases, 22000);
    expect(end.moment).toEqual({ kind: 'over' });
    expect(end.decided).toEqual(wandTimeline(releases, s).cues);
    expect(wandDots(end, s)).toEqual([true, true, true]);
    expect(wandDots(wandHuntAt(s, [5200], 9000), s)).toEqual([true, false, false]);
  });

  it('a missed window is decided once it is over; every cue missed → over after the last window', () => {
    expect(wandHuntAt(s, [], 6500).moment).toMatchObject({ kind: 'cue', index: 0 });
    const after = wandHuntAt(s, [], 6501);
    expect(after.moment).toEqual({ kind: 'waiting' });
    expect(after.decided).toEqual([{ cue_ms: 4500, caught: false, release_ms: null }]);
    expect(wandHuntAt(s, [], 29001).moment).toEqual({ kind: 'over' });
  });
});

describe('cues (sound, haptics, ★)', () => {
  const s = hunt();

  function run(releases: number[], until: number): WandCue[] {
    const out: WandCue[] = [];
    let prev: WandHuntState | null = null;
    for (let t = 0; t <= until; t += 100) {
      const next = wandHuntAt(s, releases, t);
      out.push(...wandCues(prev, next));
      prev = next;
    }
    return out;
  }

  it('a crouch per cue, the pounce at the let-go, the ★ once the catch clip played — exactly once each', () => {
    const cues = run([5200, 12000, 18500], 23000);
    expect(cues).toEqual([
      { kind: 'crouch', index: 0 },
      { kind: 'pounce', index: 0 },
      { kind: 'caught', index: 0 },
      { kind: 'crouch', index: 1 },
      { kind: 'pounce', index: 1 },
      { kind: 'caught', index: 1 },
      { kind: 'crouch', index: 2 },
      { kind: 'pounce', index: 2 },
      { kind: 'caught', index: 2 },
    ]);
  });

  it('too early / never: the cat just loses interest (no ★, no fault cue)', () => {
    const cues = run([1000], 10000);
    expect(cues).toEqual([
      { kind: 'crouch', index: 0 },
      { kind: 'lostInterest', index: 0 },
      { kind: 'crouch', index: 1 },
    ]);
  });
});

describe('stage and time', () => {
  const s = hunt();

  it('stalk → crouch loop → the catch clip from each let-go → crouch (under the ★ mask); the last catch holds', () => {
    expect(wandStageAt(s, [], 1000)).toBe('stalking');
    expect(wandStageAt(s, [], 4600)).toBe('crouch');
    expect(wandStageAt(s, [5200], 5200)).toBe('catching');
    expect(wandStageAt(s, [5200], 8800)).toBe('crouch');
    expect(wandStageAt(s, [5200, 12000, 18500], 22500)).toBe('catching');
    expect(wandStageAt(s, [], 30000)).toBe('crouch');
  });

  it('the next boundary: stalk end, cue start, window end, paw, clip end, the next cue', () => {
    expect(wandNextBoundary(s, [], 0)).toBe(3000);
    expect(wandNextBoundary(s, [], 3000)).toBe(4500);
    expect(wandNextBoundary(s, [], 4500)).toBe(6501);
    expect(wandNextBoundary(s, [], 6501)).toBe(9000);
    expect(wandNextBoundary(s, [5200], 5200)).toBe(5800);
    expect(wandNextBoundary(s, [5200], 5800)).toBe(8700);
    expect(wandNextBoundary(s, [5200], 8700)).toBe(11200);
  });
});

describe('readers', () => {
  const raw = { ...hunt(), catch_at_ms: 50500, pounces_ms: [], min_away_moves: 0, segments: 0, min_move_interval_ms: 0, pounce_window_ms: 0 };

  it('reads a simple-hunt session with every number; refuses one with a missing number or another version', () => {
    expect(readWandHuntSession(raw)).toEqual(hunt());
    for (const key of ['stalk_ms', 'first_cue_ms', 'cue_window_ms', 'after_catch_ms', 'after_miss_ms', 'max_cues', 'catches_needed', 'catch_clip_ms']) {
      const broken: Record<string, unknown> = { ...raw };
      delete broken[key];
      expect(readWandHuntSession(broken)).toBeNull();
    }
    // The strict v2 hunt (an older server) is not playable by this build.
    expect(readWandHuntSession({ ...raw, rules_version: 2 })).toBeNull();
    expect(readWandHuntSession({ ...raw, cue_window_ms: 0 })).toBeNull();
  });

  it('reads a simple-hunt result; a v1 result is not one', () => {
    const result = { session_id: BASE.id, ...scoreWandHunt([5000, 12000, 18500], hunt()) };
    expect(readWandHuntResult(result)).toEqual(result);
    expect(readWandHuntResult({ session_id: BASE.id, success: true, reason: null, away_moves: 9 })).toBeNull();
  });

  it('adds let-go moments inside the hunt only, at most 200', () => {
    expect(addWandRelease([], -1, 1000)).toEqual([]);
    expect(addWandRelease([], 1001, 1000)).toEqual([]);
    expect(addWandRelease([], 10.4, 1000)).toEqual([10]);
    const full = Array.from({ length: 200 }, (_, i) => i);
    expect(addWandRelease(full, 700, 1000)).toBe(full);
  });
});

describe('texts', () => {
  it.each(['sl', 'en'])('every game-line text fits the line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    for (const text of wandV2LineTexts()) expect(Array.from(text).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
  });
});
