import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import {
  BALL_CLIP_FETCH_MS,
  PHASE_CLIP_MS,
  WAND_CATCH_LEAD_MS,
  PERFORMING_END_MARGIN_MS,
  PERFORMING_CUE_LEAD_MS,
  ballMomentAt,
  performingStartMs,
  phaseClipMs,
  phasePlayback,
  schoolMomentAt,
  usesPhaseClips,
  wandMomentAt,
} from '@/modules/petMedia/phasePlayer';
import { CATCH_LEAD_MS } from '@/modules/catCare/catGames';
import type { TrainingSession } from '@/modules/training/training';
import { makeMedia } from '@/test-utils/fixtures';

const url = (name: string) => `https://api.petprep.si/api/media/${name}?v=${name}&expires=1&signature=s`;

const PHASES = [
  'school_sit_attention',
  'school_sit_performing',
  'school_sit_success',
  'ball_waiting',
  'ball_running',
  'ball_fetching',
  'wand_stalking',
  'wand_pouncing',
  'wand_catching',
];

function media(overrides: Parameters<typeof makeMedia>[0] = {}) {
  return normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: url('ref'),
      videos: { idle: url('idle'), playing: url('playing') },
      state_media: true,
      poses: { sit: url('pose-sit'), empty: url('pose-empty'), crouch: url('pose-crouch'), catch: url('pose-catch') },
      phases: Object.fromEntries(PHASES.map((p) => [p, url(p)])),
      phase_groups: ['school', 'ball', 'wand'],
      ...overrides,
    }),
  );
}

/** Lead-in 2 s, cue every 6 s; trial 0 obeys after 2 s, trial 1 ignores, trial 2 obeys after 1,5 s. */
const session: Pick<TrainingSession, 'command' | 'trials' | 'duration_ms'> = {
  command: 'sit',
  duration_ms: 50_000,
  trials: [
    { index: 0, cue_at_ms: 2_000, obeys: true, obey_at_ms: 4_000, window_end_ms: 5_500 },
    { index: 1, cue_at_ms: 8_000, obeys: false, obey_at_ms: null, window_end_ms: null },
    { index: 2, cue_at_ms: 14_000, obeys: true, obey_at_ms: 15_500, window_end_ms: 17_000 },
  ],
};

describe('normalizePetMedia (M5-T09 / T10 keys)', () => {
  it('reads poses, phases and groups; drops unknown keys; an older server → none (state clips stay)', () => {
    const m = normalizePetMedia(makeMedia({ poses: { sit: url('a'), jump: url('b') }, phases: { ball_running: url('c'), school_jump_attention: url('d') }, phase_groups: ['ball', 'dance'] } as never));
    expect(m.poses).toEqual({ sit: url('a') });
    expect(m.phases).toEqual({ ball_running: url('c') });
    expect(m.phaseGroups).toEqual(['ball']);

    const older = normalizePetMedia({ status: 'ready', reference_image_url: url('ref'), videos: {} });
    expect(older.phases).toEqual({});
    expect(usesPhaseClips(older, 'school')).toBe(false);
  });
});

describe('school: command phase → clip (server schedule)', () => {
  it('attention → performing (ends at the obey moment) → success held through the praise window', () => {
    // performing of trial 0 starts at obey − 3 s − margin = 900 ms.
    expect(schoolMomentAt(session, 500)).toMatchObject({ phase: 'attention', clip: 'school_sit_attention', pose: null });
    expect(schoolMomentAt(session, 899).phase).toBe('attention');
    expect(schoolMomentAt(session, 900)).toMatchObject({ phase: 'performing', clip: 'school_sit_performing', pose: 'sit' });
    expect(schoolMomentAt(session, 900 + PHASE_CLIP_MS - 1).phase).toBe('performing');
    expect(schoolMomentAt(session, 900 + PHASE_CLIP_MS)).toMatchObject({ phase: 'success', clip: 'school_sit_success', pose: 'sit' });
    expect(schoolMomentAt(session, 5_499).phase).toBe('success');
    // After the praise window: the next trial's attention (the dog waits for the next cue).
    expect(schoolMomentAt(session, 5_500)).toMatchObject({ phase: 'attention', instance: '1:attention' });
  });

  it('QA M1: the end pose is on screen at obey_at and through the whole server praise window', () => {
    for (const [i, trial] of session.trials.entries()) {
      if (!trial.obeys || trial.obey_at_ms === null || trial.window_end_ms === null) continue;
      const start = performingStartMs(session.trials, i);
      expect(start).not.toBeNull();
      // The clip has finished (end frame held) just before the text says the dog obeys…
      expect((start as number) + PHASE_CLIP_MS).toBe(trial.obey_at_ms - PERFORMING_END_MARGIN_MS);
      // …and every moment a praise counts (obey + 150 … window end) shows the held pose.
      for (let t = trial.obey_at_ms; t < trial.window_end_ms; t += 50) {
        expect(schoolMomentAt(session, t)).toMatchObject({ phase: 'success', pose: 'sit' });
      }
    }
  });

  it('QA M1: performing never starts inside the previous trial\'s praise window (end lands as soon as it can)', () => {
    const tight = {
      ...session,
      trials: [
        { index: 0, cue_at_ms: 2_000, obeys: true, obey_at_ms: 4_500, window_end_ms: 6_000 },
        { index: 1, cue_at_ms: 8_000, obeys: true, obey_at_ms: 8_800, window_end_ms: 10_300 },
      ],
    };
    expect(performingStartMs(tight.trials, 1)).toBe(6_000);
    expect(schoolMomentAt(tight, 5_999)).toMatchObject({ phase: 'success', instance: '0:success' });
    expect(schoolMomentAt(tight, 6_000)).toMatchObject({ phase: 'performing', instance: '1:performing' });
  });

  it('a cue the dog ignores → the standing pose only (no clip that would show it attending)', () => {
    expect(schoolMomentAt(session, 9_000)).toMatchObject({ phase: null, clip: null, pose: null });
    expect(phasePlayback(media(), schoolMomentAt(session, 9_000)).current).toMatchObject({ kind: 'image', url: url('ref') });
  });

  it('each trial is its own instance: the same clip replays from the start for the next obey', () => {
    expect(schoolMomentAt(session, 1_000).instance).not.toBe(schoolMomentAt(session, 13_000).instance);
    const a = phasePlayback(media(), schoolMomentAt(session, 1_000)).current;
    const b = phasePlayback(media(), schoolMomentAt(session, 13_000)).current;
    expect(a.kind === 'clip' && b.kind === 'clip' && a.key !== b.key).toBe(true);
  });

  it('a phase clip plays once (loop false) with its pose under it; never a state clip', () => {
    const p = phasePlayback(media(), schoolMomentAt(session, 1_000));
    expect(p.current).toMatchObject({ kind: 'clip', url: url('school_sit_performing'), loop: false, transition: null, state: null });
    expect(p.still).toBe(url('pose-sit'));
  });

  it('a missing clip → its pose image (sitting), never the idle / playing state clip (§0 rule 1)', () => {
    const m = media({ phases: {} });
    expect(phasePlayback(m, schoolMomentAt(session, 4_000)).current).toEqual({ kind: 'image', url: url('pose-sit'), key: expect.any(String), state: null });
    // A missing pose too → the reference image (standing), still no clip.
    expect(phasePlayback(media({ phases: {}, poses: {} }), schoolMomentAt(session, 4_000)).current).toMatchObject({ kind: 'image', url: url('ref') });
  });

  it('picture mode: pose images only — no clip is mounted (nothing downloaded)', () => {
    expect(phasePlayback(media(), schoolMomentAt(session, 1_000), 'picture').current).toMatchObject({ kind: 'image', url: url('pose-sit') });
    expect(phasePlayback(media(), schoolMomentAt(session, 500), 'picture').current).toMatchObject({ kind: 'image', url: url('ref') });
  });

  it('a clip the player failed on → the pose', () => {
    expect(phasePlayback(media(), schoolMomentAt(session, 1_000), 'video', () => false).current.kind).toBe('image');
  });
});

describe('school: 5 s performing clips (David 2026-10-23)', () => {
  const five = () => media({ phase_seconds: { school_sit_performing: 5, school_sit_success: 3 } });

  it('normalizePetMedia reads phase_seconds (ms); unknown keys / bad values are dropped; older server → 3 s', () => {
    const m = normalizePetMedia(makeMedia({ phases: { school_sit_performing: url('p') }, phase_seconds: { school_sit_performing: 5, ball_running: 'x', school_jump_attention: 4, wand_pouncing: 99 } } as never));
    expect(m.phaseMs).toEqual({ school_sit_performing: 5_000 });
    expect(phaseClipMs(m, 'school_sit_performing')).toBe(5_000);
    expect(phaseClipMs(m, 'ball_running')).toBe(PHASE_CLIP_MS);
    expect(phaseClipMs(media(), 'school_sit_performing')).toBe(PHASE_CLIP_MS);
  });

  it('QA #363 M2: trial 2 after an IGNORED trial — the 5 s clip starts at the cue − lead, skips its slow start, ends in the pose at obey − margin', () => {
    // cue 14 000, obey 15 500: the natural 5 s start (10 400) would be 3.6 s before the cue.
    expect(performingStartMs(session.trials, 2, 5_000)).toBe(14_000 - PERFORMING_CUE_LEAD_MS);
    expect(schoolMomentAt(session, 13_699, five()).phase).not.toBe('performing');
    const p = schoolMomentAt(session, 13_700, five());
    expect(p).toMatchObject({ phase: 'performing', clip: 'school_sit_performing', instance: '2:performing' });
    // Window 13 700 … 15 400 = 1 700 ms of a 5 000 ms clip → start 3 300 ms into it.
    expect(p.startAtMs).toBe(3_300);
    expect(schoolMomentAt(session, 15_399, five()).phase).toBe('performing');
    for (let t = 15_400; t < 17_000; t += 100) expect(schoolMomentAt(session, t, five())).toMatchObject({ phase: 'success', pose: 'sit' });
  });

  it('a window shorter than the clip: it starts later INTO the clip so the pose still lands on time (seek clamped to the clip)', () => {
    // Trial 0: lead-in, cue 2 000, obey 4 000 → start 1 700 (cue − lead), 2 200 ms window → skip 2 800.
    expect(schoolMomentAt(session, 1_699, five())).toMatchObject({ phase: 'attention' });
    const p = schoolMomentAt(session, 1_700, five());
    expect(p).toMatchObject({ phase: 'performing', startAtMs: 2_800 });
    expect(phasePlayback(five(), p).current).toMatchObject({ kind: 'clip', url: url('school_sit_performing'), startAtMs: 2_800, loop: false });
    expect(schoolMomentAt(session, 3_899, five()).phase).toBe('performing');
    expect(schoolMomentAt(session, 3_900, five())).toMatchObject({ phase: 'success', pose: 'sit' });
    // Never inside the previous trial's praise window; attention in between; never before cue − lead.
    const tight = { ...session, trials: [
      { index: 0, cue_at_ms: 2_000, obeys: true, obey_at_ms: 4_500, window_end_ms: 6_000 },
      { index: 1, cue_at_ms: 8_000, obeys: true, obey_at_ms: 8_800, window_end_ms: 10_300 },
    ] };
    expect(schoolMomentAt(tight, 5_999, five())).toMatchObject({ phase: 'success', instance: '0:success' });
    expect(schoolMomentAt(tight, 6_500, five())).toMatchObject({ phase: 'attention', instance: '1:attention' });
    expect(schoolMomentAt(tight, 7_700, five())).toMatchObject({ phase: 'performing', instance: '1:performing', startAtMs: 5_000 - (8_700 - 7_700) });
    // A praise window that ends after obey − margin: the seek never passes the clip's end.
    const late = { ...session, trials: [
      { index: 0, cue_at_ms: 2_000, obeys: true, obey_at_ms: 4_000, window_end_ms: 9_000 },
      { index: 1, cue_at_ms: 8_000, obeys: true, obey_at_ms: 8_800, window_end_ms: 10_300 },
    ] };
    const m = schoolMomentAt(late, 9_000, five());
    expect(m.phase === 'performing' ? (m.startAtMs ?? 0) : 0).toBeLessThanOrEqual(5_000);
  });

  it('an older 3 s performing clip (no phase_seconds) keeps the 3 s timing', () => {
    expect(schoolMomentAt(session, 12_399, media()).phase).not.toBe('performing');
    expect(schoolMomentAt(session, 12_400, media())).toMatchObject({ phase: 'performing' });
    expect(schoolMomentAt(session, 12_400, media()).startAtMs).toBeUndefined();
  });
});

describe('ball game: waiting → running → fetching (app draws the ball)', () => {
  it('follows the throw clock; the ball is out for both clips', () => {
    expect(ballMomentAt(0, null)).toMatchObject({ phase: 'waiting', clip: 'ball_waiting', pose: null });
    expect(ballMomentAt(1, 0)).toMatchObject({ phase: 'running', clip: 'ball_running', pose: 'empty' });
    expect(ballMomentAt(1, PHASE_CLIP_MS)).toMatchObject({ phase: 'fetching', clip: 'ball_fetching', pose: null });
    expect(BALL_CLIP_FETCH_MS).toBe(2 * PHASE_CLIP_MS);
    expect(ballMomentAt(1, 0).instance).not.toBe(ballMomentAt(2, 0).instance);
  });

  it('missing running clip → the empty room pose (the dog is away), never the playing clip', () => {
    expect(phasePlayback(media({ phases: {} }), ballMomentAt(1, 0)).current).toMatchObject({ kind: 'image', url: url('pose-empty') });
  });
});

describe('wand game: stalking → pouncing → catching (app draws the feather)', () => {
  const wand = { pounces_ms: [10_000, 20_000], duration_ms: 60_000 };

  it('stalks into the crouch, pounces per pounce, waits crouched between, catches at the end', () => {
    expect(wandMomentAt(wand, 1_000)).toMatchObject({ phase: 'stalking', clip: 'wand_stalking', pose: 'crouch' });
    expect(wandMomentAt(wand, 10_000)).toMatchObject({ phase: 'pouncing', clip: 'wand_pouncing', instance: 'pounce:0' });
    expect(wandMomentAt(wand, 10_000 + PHASE_CLIP_MS)).toMatchObject({ phase: 'stalking', clip: null, pose: 'crouch' });
    expect(wandMomentAt(wand, 20_500).instance).toBe('pounce:1');
    expect(wandMomentAt(wand, 60_000 - WAND_CATCH_LEAD_MS)).toMatchObject({ phase: 'catching', clip: 'wand_catching', pose: 'catch' });
    // Same lead as the drawn game (catGames.CATCH_LEAD_MS) — text and picture agree.
    expect(WAND_CATCH_LEAD_MS).toBe(CATCH_LEAD_MS);
  });

  it('between pounces the crouch pose (still), not a replay of stalking', () => {
    expect(phasePlayback(media(), wandMomentAt(wand, 15_000)).current).toMatchObject({ kind: 'image', url: url('pose-crouch') });
  });
});
