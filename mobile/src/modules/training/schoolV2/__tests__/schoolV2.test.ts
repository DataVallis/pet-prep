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
import { ruleText } from '@/modules/training/schoolV2/SchoolRuleStrip';
import {
  BREAK_COUNTDOWN_MS,
  EARLY_END_RESUME_AHEAD_MS,
  EARLY_END_SAFETY_MS,
  EARLY_END_TOLERANCE_MS,
  breakCountdown,
  commonMistake,
  earlyEndOpen,
  liveOutcomes,
  praiseHintEnabled,
  praiseHintOn,
  resultHeadline,
  tokenWaiting,
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
  tapHintOn,
  isTokenDrag,
  TOKEN_DRAG_START_PT,
  PRAISE_HINT_BELOW_PROGRESS,
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

describe('clarity layer (David\'s device test 2026-10-31)', () => {
  const at = (ms: number) => schoolFrameAt(V2, ms);

  it('beginner hint: on below 50 % progress, off at and above 50 %', () => {
    expect(praiseHintEnabled(0)).toBe(true);
    expect(praiseHintEnabled(49)).toBe(true);
    expect(praiseHintEnabled(50)).toBe(false);
    expect(praiseHintEnabled(100)).toBe(false);
  });

  it('the hint shows only in the praise window (obey_at … release_at), never while performing or ignoring', () => {
    expect(praiseHintOn(at(5_499), true)).toBe(false);
    expect(praiseHintOn(at(5_500), true)).toBe(true);
    expect(praiseHintOn(at(6_999), true)).toBe(true);
    expect(praiseHintOn(at(7_000), true)).toBe(false);
    expect(praiseHintOn(at(5_500), false)).toBe(false);
    for (let ms = 0; ms < V2.duration_ms; ms += 50) if (praiseHintOn(at(ms), true)) expect(at(ms).kind).toBe('window');
  });

  it('the token waits (dimmed) only while the dog visibly ignores — never during the »think« time', () => {
    expect(tokenWaiting(at(8_900))).toBe(false); // ignoredCue: the dog might still obey
    expect(tokenWaiting(at(10_499))).toBe(false);
    expect(tokenWaiting(at(10_500))).toBe(true);
    expect(tokenWaiting(at(13_399))).toBe(true);
    expect(tokenWaiting(at(13_400))).toBe(false);
    expect(tokenWaiting(at(5_500))).toBe(false);
  });

  it('tap hint (David 2026-10-31): on while the beginner hint is enabled and nothing counted, off after the first counted tap', () => {
    for (const ms of [0, 2_000, 2_600, 5_600, 8_900, 23_000]) expect(tapHintOn(true, false, at(ms))).toBe(true);
    for (const ms of [0, 5_600, 23_000]) expect(tapHintOn(true, true, at(ms))).toBe(false);
    // while the dog visibly ignores the token says »Počakaj« — no »Tapni« next to it
    expect(tapHintOn(true, false, at(10_600))).toBe(false);
  });

  it('tap hint shares the »Zdaj!« gate: gone at ≥ 50 % progress', () => {
    expect(PRAISE_HINT_BELOW_PROGRESS).toBe(50);
    expect(tapHintOn(praiseHintEnabled(49), false, at(1_000))).toBe(true);
    expect(tapHintOn(praiseHintEnabled(50), false, at(1_000))).toBe(false);
    expect(tapHintOn(praiseHintEnabled(100), false, at(5_600))).toBe(false);
  });

  it('a drag starts only after the finger moved TOKEN_DRAG_START_PT', () => {
    expect(TOKEN_DRAG_START_PT).toBe(8);
    expect(isTokenDrag(0, 0)).toBe(false);
    expect(isTokenDrag(5, 5)).toBe(false);
    expect(isTokenDrag(0, -8)).toBe(true);
    expect(isTokenDrag(-30, -120)).toBe(true);
  });

  it('the tap hint text fits a small pill (sl + en)', async () => {
    expect(SCHOOL_V2_STRINGS.tapHint).toBe('Tapni');
    await i18n.changeLanguage('en');
    expect(SCHOOL_V2_STRINGS.tapHint).toBe('Tap');
    await i18n.changeLanguage('sl');
  });

  it('break countdown: ≤ 3 s, counted to round 2\'s start, never past it (break 2.5 s → 3, 2, 1)', () => {
    expect(BREAK_COUNTDOWN_MS).toBe(3_000);
    expect(breakCountdown(V2, at(20_299), 20_299)).toBeNull();
    expect(breakCountdown(V2, at(20_300), 20_300)).toBe(3); // 2.5 s left
    expect(breakCountdown(V2, at(20_800), 20_800)).toBe(2);
    expect(breakCountdown(V2, at(21_800), 21_800)).toBe(1);
    expect(breakCountdown(V2, at(22_799), 22_799)).toBe(1);
    expect(breakCountdown(V2, at(22_800), 22_800)).toBeNull(); // round 2 runs: auto-continued exactly at its start
    // A longer break is capped at 3 s.
    const long = readTrainingSession(makeSchoolV2SessionPayload()) as TrainingSession;
    const rounds = [long.rounds![0], { ...long.rounds![1], start_ms: 30_000 }];
    expect(breakCountdown({ rounds }, { kind: 'break', round: 0, toMs: 30_000 }, 20_300)).toBe(3);
  });

  it('result headline by outcome (never false praise — the M5-F04 bucket)', () => {
    const r = (outcomes: string[]) => ({ trials: outcomes.map((outcome, index) => ({ index, obeys: outcome !== 'waited', outcome, tap_ms: null })) }) as never;
    expect(resultHeadline(r(['in_time', 'in_time', 'waited']))).toBe('success');
    expect(resultHeadline(r(['in_time', 'in_time', 'too_late']))).toBe('success');
    expect(resultHeadline(r(['in_time', 'too_late', 'no_praise', 'too_early']))).toBe('partial');
    expect(resultHeadline(r(['no_praise', 'too_early', 'waited']))).toBe('none');
  });

  it('the tip points at the most common mistake (tie: praise without obeying › too early › missed)', () => {
    const t = (outcomes: string[]) => outcomes.map((outcome) => ({ outcome })) as never;
    expect(commonMistake(t(['in_time', 'waited']))).toBeNull();
    expect(commonMistake(t(['too_early', 'too_early', 'no_praise']))).toBe('early');
    expect(commonMistake(t(['too_late', 'no_praise', 'too_early']))).toBe('missed');
    expect(commonMistake(t(['praised_without_obeying', 'too_early']))).toBe('notObeyed');
    expect(commonMistake(t(['too_early', 'too_late']))).toBe('early');
  });
});

describe('texts', () => {
  it.each(['sl', 'en'])('the rule strip says ♥ / treat and fits two short lines (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    expect(ruleText('heart')).toContain('♥');
    expect(ruleText('treat')).not.toContain('♥');
    for (const rule of [ruleText('heart'), ruleText('treat')]) expect(Array.from(rule).length).toBeLessThanOrEqual(2 * GAME_LINE_MAX_CHARS);
    await i18n.changeLanguage('sl');
  });

  it('sl wording as David asked', async () => {
    await i18n.changeLanguage('sl');
    expect(ruleText('heart')).toBe('Ko kuža uboga → pritisni ♥. Če ne uboga → počakaj.');
    expect(SCHOOL_V2_STRINGS.ignores).toBe('Ne uboga — ne pohvali, počakaj.');
    expect(SCHOOL_V2_STRINGS.result.tryAgainLeft(4)).toBe('Poskusi še enkrat — danes imaš še 4 vaje.');
    expect(SCHOOL_V2_STRINGS.result.tryAgainLeft(1)).toBe('Poskusi še enkrat — danes imaš še 1 vajo.');
    expect(SCHOOL_V2_STRINGS.result.tryAgainLeft(2)).toBe('Poskusi še enkrat — danes imaš še 2 vaji.');
    expect(SCHOOL_V2_STRINGS.result.tryAgainLeft(5)).toBe('Poskusi še enkrat — danes imaš še 5 vaj.');
    expect(SCHOOL_V2_STRINGS.result.outcome.waited).toBe('Pravilno počakal(a)');
  });

  it.each(['sl', 'en'])('every school v2 game line fits one line (%s)', async (lang) => {
    await i18n.changeLanguage(lang);
    const lines = [SCHOOL_V2_STRINGS.keepGoing, SCHOOL_V2_STRINGS.now, TRAINING_STRINGS.starting];
    for (const line of lines) expect(Array.from(line).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    for (const label of Object.values(SCHOOL_V2_STRINGS.fb)) expect(Array.from(label).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    expect(SCHOOL_V2_STRINGS.round(1)).toContain('1');
    await i18n.changeLanguage('sl');
  });
});
