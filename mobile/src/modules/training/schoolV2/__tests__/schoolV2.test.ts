/**
 * GAMES_SPEC phase 3 — school v2 pure logic: the v2 schedule reader (only a consistent set is v2),
 * the timeline / round steps, step cues (cue sound, verdict under the mask, »waited«, round card),
 * tap feedback (first tap decides, mirror of the server), early-end gating (Š3), treat vs heart (V6),
 * the result readers, the stage plan (never the release clip) and every game line ≤ 32 chars.
 */
import { GAME_LINE_MAX_CHARS } from '@/modules/gameLayer/gameLine';
import { i18n } from '@/i18n';
import { normalizePetMedia } from '@/modules/petMedia/petMedia';
import { schoolDeck, performingMs } from '@/modules/training/schoolV2/schoolDeck';
import {
  EARLY_END_RESUME_AHEAD_MS,
  EARLY_END_SAFETY_MS,
  EARLY_END_TOLERANCE_MS,
  earlyEndOpen,
  liveOutcomes,
  maskFeedback,
  nextBoundaryAfter,
  praiseToken,
  resultRounds,
  roundStars,
  schoolBoundaries,
  schoolCues,
  schoolFrameAt,
  starCount,
  tapFeedback,
} from '@/modules/training/schoolV2/schoolV2';
import {
  isSchoolV2Session,
  readChildTraining,
  readTrainingResult,
  readTrainingSession,
  SCHOOL_V2_STRINGS,
  trainingRefusalMessage,
  TRAINING_STRINGS,
  type TrainingSession,
} from '@/modules/training/training';
import { makeEnabledTraining, makeMedia, makeSchoolV2SessionPayload, makeTrainingResult, makeSmoothTrainingSessionPayload } from '@/test-utils/fixtures';

const V2 = readTrainingSession(makeSchoolV2SessionPayload()) as TrainingSession;

describe('v2 schedule reader', () => {
  it('reads rounds, mask and breaks of a rules-v2 session', () => {
    expect(isSchoolV2Session(V2)).toBe(true);
    expect(V2.mask_ms).toBe(1_200);
    expect(V2.round_break_ms).toBe(2_500);
    expect(V2.rounds).toEqual([
      { round: 1, first_index: 0, last_index: 2, start_ms: 0, end_ms: 20_300 },
      { round: 2, first_index: 3, last_index: 5, start_ms: 22_800, end_ms: 41_100 },
    ]);
    expect(V2.round_breaks_ms).toEqual([20_300]);
    expect(V2.duration_ms).toBe(41_100);
  });

  it('a v1 schedule (no rules) and an inconsistent v2 set stay v1 — never a guessed round', () => {
    expect(isSchoolV2Session(readTrainingSession(makeSmoothTrainingSessionPayload()) as TrainingSession)).toBe(false);
    const raw = makeSchoolV2SessionPayload();
    const broken = [
      { ...raw, rounds: [raw.rounds[0]] },
      { ...raw, mask_ms: 0 },
      { ...raw, rounds: [raw.rounds[0], { ...raw.rounds[1], first_index: 4 }] },
      { ...raw, rounds: [raw.rounds[0], { ...raw.rounds[1], end_ms: 99_999 }] },
      { ...raw, rules_version: 3 },
    ];
    for (const b of broken) {
      const s = readTrainingSession(b) as TrainingSession;
      expect(s).not.toBeNull();
      expect(isSchoolV2Session(s)).toBe(false);
      expect(s.rounds).toBeUndefined();
    }
  });

  it('result: v2 keys, early end, routine flag (v1 always counts)', () => {
    const v1 = readTrainingResult(makeTrainingResult());
    expect(v1).toMatchObject({ ended_after_round: null, counts_for_routine: true });
    expect(v1?.rules_version).toBeUndefined();
    const early = readTrainingResult(makeTrainingResult({ rules_version: 2, ended_after_round: 1, counts_for_routine: false }));
    expect(early).toMatchObject({ rules_version: 2, ended_after_round: 1, counts_for_routine: false });
  });

  it('treat_token from the child state (missing → false)', () => {
    expect(readChildTraining(makeEnabledTraining({ treat_token: true })).treat_token).toBe(true);
    const { treat_token: _drop, ...older } = makeEnabledTraining();
    void _drop;
    expect(readChildTraining(older).treat_token).toBe(false);
  });

  it('the new refusal has a calm text', () => {
    expect(trainingRefusalMessage('training_round_two_started', null, null)).toBe(TRAINING_STRINGS.errors.training_round_two_started);
  });
});

describe('timeline and rounds', () => {
  const at = (ms: number) => schoolFrameAt(V2, ms);

  it('lead-in with the rule line, then an obeyed cue: listening → performing (no line) → window → mask → calm', () => {
    expect(at(0)).toMatchObject({ kind: 'lead', line: 'lead', chip: false, role: 'attention' });
    expect(at(2_000)).toMatchObject({ kind: 'listening', trial: 0, chip: true, icon: 'ear', role: 'attention', line: null });
    expect(at(2_500)).toMatchObject({ kind: 'performing', trial: 0, chip: true, icon: 'ear', role: 'performing', line: null });
    expect(at(5_500)).toMatchObject({ kind: 'window', trial: 0, chip: false, icon: null, role: 'success' });
    expect(at(7_000)).toMatchObject({ kind: 'mask', trial: 0, role: 'attention', fromMs: 7_000, toMs: 8_200 });
    expect(at(8_200)).toMatchObject({ kind: 'calm', trial: 0, role: 'attention', toMs: 8_900 });
  });

  it('an ignored cue: chip 1.5 s, then the distraction + »Ne uboga — počakaj.« until the slot ends (≥ 2.9 s)', () => {
    expect(at(8_900)).toMatchObject({ kind: 'ignoredCue', trial: 1, chip: true, line: null });
    expect(at(10_400)).toMatchObject({ kind: 'ignoredCue', chip: false });
    const ignoring = at(10_500);
    expect(ignoring).toMatchObject({ kind: 'ignoring', trial: 1, icon: 'ignore', line: 'ignores', role: 'attention' });
    expect(ignoring.toMs - ignoring.fromMs).toBeGreaterThanOrEqual(2_900);
  });

  it('round 1 ends after cue 3 → 2.5 s break → round 2 (no new lead-in) → over', () => {
    expect(at(20_299)).toMatchObject({ kind: 'calm', trial: 2, round: 0 });
    expect(at(20_300)).toMatchObject({ kind: 'break', round: 0, trial: null, fromMs: 20_300, toMs: 22_800 });
    expect(at(22_800)).toMatchObject({ kind: 'listening', trial: 3, round: 1 });
    expect(at(41_100)).toMatchObject({ kind: 'over' });
  });

  it('never a release role, and the dog is visibly performing only between perform_at and obey_at', () => {
    for (let ms = 0; ms < V2.duration_ms; ms += 50) {
      const f = at(ms);
      expect(['attention', 'performing', 'success']).toContain(f.role);
      if (f.kind === 'performing') expect(f.line).toBeNull();
    }
  });

  it('boundaries hold every change; nextBoundaryAfter walks them', () => {
    const b = schoolBoundaries(V2);
    for (const ms of [0, 2_000, 2_500, 5_500, 7_000, 8_200, 8_900, 10_400, 10_500, 20_300, 22_800, 41_100]) expect(b).toContain(ms);
    expect(nextBoundaryAfter(b, 7_000)).toBe(8_200);
    expect(nextBoundaryAfter(b, 41_100)).toBeNull();
    // Between two boundaries the frame never changes.
    for (let k = 0; k + 1 < b.length; k += 1) {
      const a = schoolFrameAt(V2, b[k]);
      const z = schoolFrameAt(V2, b[k + 1] - 1);
      expect({ kind: z.kind, chip: z.chip, trial: z.trial }).toEqual({ kind: a.kind, chip: a.chip, trial: a.trial });
    }
  });
});

describe('cues per step', () => {
  const cuesAt = (fromMs: number, toMs: number, taps: number[] = []) => schoolCues(V2, schoolFrameAt(V2, fromMs), schoolFrameAt(V2, toMs), taps, toMs);

  it('a new cue plays the cue sound (also after the lead-in and the break); the same step plays nothing', () => {
    expect(cuesAt(1_999, 2_000)).toEqual([{ kind: 'cueSound' }]);
    expect(cuesAt(22_799, 22_800)).toEqual([{ kind: 'cueSound' }]);
    expect(cuesAt(2_100, 2_200)).toEqual([]);
  });

  it('the window announces »obeys«', () => {
    expect(cuesAt(5_499, 5_500)).toEqual([{ kind: 'announce', what: 'obeys' }]);
  });

  it('at release: the verdict under the mask for the whole mask (✓ in time / ✗ too early / ✗ missed)', () => {
    expect(cuesAt(6_999, 7_000, [5_900])).toEqual([{ kind: 'feedback', feedback: { kind: 'ok', label: 'inTime', mask: true, totalMs: 1_200 } }]);
    expect(cuesAt(6_999, 7_000, [3_000])).toEqual([{ kind: 'feedback', feedback: { kind: 'miss', label: 'early', mask: true, totalMs: 1_200 } }]);
    expect(cuesAt(6_999, 7_000)).toEqual([{ kind: 'feedback', feedback: { kind: 'miss', label: 'missed', mask: true, totalMs: 1_200 } }]);
  });

  it('an ignored cue that ended untapped → »Prav, da si počakal(a)!«; a tapped one says nothing more', () => {
    expect(cuesAt(13_399, 13_400)).toEqual([{ kind: 'feedback', feedback: { kind: 'ok', label: 'waited', mask: false, totalMs: 1_000 } }, { kind: 'cueSound' }]);
    expect(cuesAt(13_399, 13_400, [11_000])).toEqual([{ kind: 'cueSound' }]);
  });

  it('the break shows the round card for the break', () => {
    expect(cuesAt(20_299, 20_300)).toEqual([{ kind: 'round', round: 1, totalMs: 2_500 }]);
  });

  it('mask feedback never shorter than the layer can show', () => {
    expect(maskFeedback('in_time', 50).totalMs).toBe(400);
  });
});

describe('tap feedback (first tap decides — mirror of TrainingService::score)', () => {
  const fb = (ms: number, trial: number) => tapFeedback(V2, trial, ms, schoolFrameAt(V2, ms));
  it('in time from obey + 150 to the window end', () => {
    expect(fb(5_650, 0)).toMatchObject({ kind: 'ok', label: 'inTime', mask: false });
    expect(fb(7_000, 0)).toMatchObject({ label: 'inTime' });
  });
  it('too early while performing / before obey + 150', () => {
    expect(fb(4_000, 0)).toMatchObject({ kind: 'miss', label: 'early' });
    expect(fb(5_600, 0)).toMatchObject({ label: 'early' });
  });
  it('too late: inside the mask it stays a mask, after it a short ✗', () => {
    expect(fb(7_500, 0)).toMatchObject({ label: 'late', mask: true, totalMs: 700 });
    expect(fb(8_500, 0)).toMatchObject({ label: 'late', mask: false });
  });
  it('a tap while the dog ignores the cue', () => {
    expect(fb(11_000, 1)).toMatchObject({ kind: 'miss', label: 'notObeyed' });
  });
  it('stars = in time + rightly waited; per round', () => {
    const outcomes = liveOutcomes(V2, [5_900, 16_000, 26_500], 41_100);
    // 0 in time, 1 waited, 2 early | 3 in time, 4 waited, 5 no praise
    expect(starCount(outcomes)).toBe(4);
    expect(roundStars(V2, outcomes, 0)).toBe(2);
    expect(roundStars(V2, outcomes, 1)).toBe(2);
  });
});

describe('early end (Š3)', () => {
  it('only in the break, only for v2', () => {
    expect(earlyEndOpen(V2, 20_299, 0)).toBe(false);
    expect(earlyEndOpen(V2, 20_300, 0)).toBe(true);
    expect(earlyEndOpen(V2, 22_799, 0)).toBe(true);
    expect(earlyEndOpen(V2, 22_800, 0)).toBe(false);
    expect(earlyEndOpen(readTrainingSession(makeSmoothTrainingSessionPayload()) as TrainingSession, 20_300, 0)).toBe(false);
  });

  it('closes early when the server runs ahead (its limit: round 2 start + 2 s, minus a safety margin)', () => {
    const limit = 22_800 + EARLY_END_TOLERANCE_MS - EARLY_END_SAFETY_MS;
    expect(earlyEndOpen(V2, 21_000, limit - 21_000)).toBe(true);
    expect(earlyEndOpen(V2, 21_000, limit - 21_000 + 1)).toBe(false);
    // A resumed session (whole-second started_at) still gets part of the break.
    expect(earlyEndOpen(V2, 20_300, EARLY_END_RESUME_AHEAD_MS)).toBe(true);
    // A server far ahead (slow stage) → never offered; round 2 just plays.
    expect(earlyEndOpen(V2, 20_300, 5_000)).toBe(false);
  });
});

describe('treat vs heart (V6)', () => {
  it('a treat only when the family bought treats; display only', () => {
    expect(praiseToken({ treat_token: true })).toBe('treat');
    expect(praiseToken({ treat_token: false })).toBe('heart');
  });
});

describe('result rounds', () => {
  it('groups cues 0–2 / 3–5; an early end has one round', () => {
    const six = [5, 1, 0, 3, 2, 4].map((index) => ({ index }));
    expect(resultRounds(six).map((r) => r.map((t) => t.index))).toEqual([[0, 1, 2], [3, 4, 5]]);
    expect(resultRounds([{ index: 0 }, { index: 1 }, { index: 2 }])).toHaveLength(1);
  });
});

describe('stage plan', () => {
  const URL = (k: string) => `https://api.petprep.si/m/${k}.mp4`;
  const media = normalizePetMedia(
    makeMedia({
      status: 'ready',
      reference_image_url: 'https://api.petprep.si/m/ref.png',
      poses: { sit: 'https://api.petprep.si/m/sit.png' },
      phases: {
        school_sit_attention_loop: URL('att_loop'),
        school_sit_attention: URL('att'),
        school_sit_performing: URL('perf'),
        school_sit_success_loop: URL('succ_loop'),
        school_sit_success: URL('succ'),
        school_sit_release: URL('release'),
      },
      phase_groups: ['school'],
      phase_seconds: { school_sit_performing: 5 },
    }),
  );

  it('never mounts the release clip; attention/success may be left any time, performing plays to its end', () => {
    const plan = schoolDeck(media, 'sit', 'video', V2);
    expect(plan.clips.map((c) => c.url)).toEqual([URL('att_loop'), URL('perf'), URL('succ_loop')]);
    expect(plan.clips.some((c) => c.url === URL('release'))).toBe(false);
    expect(plan.specs.map((s) => [s.role, s.loop, s.startPose, s.endPose])).toEqual([
      ['attention', true, 'stand', 'stand'],
      ['performing', false, 'stand', 'pose'],
      ['success', true, 'pose', 'pose'],
    ]);
    // Timed by the SERVER's schedule (obey − perform), not the stored 5 s.
    expect(performingMs(V2, media, 'sit')).toBe(3_000);
    expect(plan.specs[1].durationMs).toBe(3_000);
  });

  it('picture mode: no clip; the pose image only for success (performing shows standing — no early hint)', () => {
    const plan = schoolDeck(media, 'sit', 'picture', V2);
    expect(plan.clips.every((c) => c.url === null)).toBe(true);
    expect(plan.clips.map((c) => c.picture)).toEqual(['https://api.petprep.si/m/ref.png', 'https://api.petprep.si/m/ref.png', 'https://api.petprep.si/m/sit.png']);
  });
});

describe('texts', () => {
  it.each(['sl', 'en'])('every school v2 game line fits one line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    const lines = [SCHOOL_V2_STRINGS.lead, SCHOOL_V2_STRINGS.leadHeart, SCHOOL_V2_STRINGS.ignores, SCHOOL_V2_STRINGS.keepGoing, TRAINING_STRINGS.starting];
    for (const line of lines) expect(Array.from(line).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    for (const label of Object.values(SCHOOL_V2_STRINGS.fb)) expect(Array.from(label).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    expect(SCHOOL_V2_STRINGS.round(1)).toContain('1');
    await i18n.changeLanguage('sl');
  });
});
