/**
 * M5-R03 training payload readers (child state, parent summary, start / finish 200
 * bodies — untyped in schema.ts), gating helpers and Slovenian texts.
 */
import {
  EMPTY_TRAINING,
  EMPTY_TRAINING_SUMMARY,
  isDayEnding,
  knowsLine,
  parentTrainingLines,
  readChildTraining,
  readFinishResponse,
  readPetTraining,
  readStartResponse,
  readTrainingResult,
  readTrainingSession,
  sessionsLeftToday,
  showTrainingEntry,
  startBlock,
  TRAINING_STRINGS,
  trainingRefusalMessage,
} from '@/modules/training/training';
import {
  makeEnabledTraining,
  makeLiveChildState,
  makeRunningSession,
  makeTrainingCommands,
  makeTrainingResult,
  makeTrainingSessionPayload,
  makeTrainingState,
} from '@/test-utils/fixtures';

describe('readChildTraining', () => {
  it('legacy / older server / disabled → EMPTY_TRAINING (no entry)', () => {
    expect(readChildTraining(undefined)).toBe(EMPTY_TRAINING);
    expect(readChildTraining(null)).toBe(EMPTY_TRAINING);
    expect(readChildTraining('x')).toBe(EMPTY_TRAINING);
    expect(readChildTraining(makeTrainingState())).toBe(EMPTY_TRAINING);
    expect(showTrainingEntry(EMPTY_TRAINING)).toBe(false);
  });

  it('reads an enabled payload; commands always in the fixed order, missing ones at 0 %', () => {
    const t = readChildTraining(
      makeEnabledTraining({
        commands: [
          { command: 'potty', progress: 12.6, learned: false, last_practised_at: null },
          { command: 'sit', progress: 100, learned: true, last_practised_at: '2026-10-03T17:00:00+02:00' },
          { command: 'jump' as never, progress: 50, learned: false, last_practised_at: null },
        ],
        today_done: true,
      }),
    );
    expect(t.enabled).toBe(true);
    expect(t.today_done).toBe(true);
    expect(t.commands.map((c) => [c.command, c.progress, c.learned])).toEqual([
      ['sit', 100, true],
      ['come', 0, false],
      ['place', 0, false],
      ['potty', 13, false],
    ]);
    expect(showTrainingEntry(t)).toBe(true);
  });

  it('reads the running session; malformed session → null', () => {
    const session = makeRunningSession();
    const sibling = readChildTraining(makeEnabledTraining({ session, can_start: false })).session;
    expect(sibling).toMatchObject({ id: 's1', command: 'come', mine: false, schedule: null });
    expect(readChildTraining(makeEnabledTraining({ session: { ...session, command: 'fly' } as never })).session).toBeNull();
    expect(readChildTraining(makeEnabledTraining({ session: { ...session, ends_at: 'soon' } })).session).toBeNull();
    // Own session (PR #53): the schedule comes along for a resume.
    const own = readChildTraining(makeEnabledTraining({ session: makeRunningSession({ mine: true }), can_start: false })).session;
    expect(own?.schedule?.trials).toHaveLength(8);
    expect(own?.schedule?.min_reaction_ms).toBe(150);
    expect(own?.schedule?.id).toBe('s1');
    // Own session from an older server (no schedule) → nothing to resume.
    const ownOld = { ...makeRunningSession({ mine: true }), duration_ms: null, trials: null };
    expect(readChildTraining(makeEnabledTraining({ session: ownOld })).session?.schedule).toBeNull();
  });

  it('sessions left and why "Začni vajo" is off', () => {
    const t = readChildTraining(makeEnabledTraining({ daily_budget_left_seconds: 120 }));
    expect(sessionsLeftToday(t)).toBe(2);
    expect(startBlock(t)).toBeNull();
    expect(startBlock(readChildTraining(makeEnabledTraining({ can_start: false, daily_budget_left_seconds: 40 })))).toBe('budget_used');
    expect(startBlock(readChildTraining(makeEnabledTraining({ can_start: false, session: makeRunningSession() })))).toBe('sibling_training');
    expect(startBlock(readChildTraining(makeEnabledTraining({ can_start: false, session: makeRunningSession({ mine: true }) })))).toBe('own_session_closing');
    // Locked / other server reasons with budget left.
    const off = readChildTraining(makeEnabledTraining({ can_start: false }));
    expect(startBlock(off)).toBe('unavailable');
    // PR #53: the session + its 60 s TTL would cross the family midnight.
    const midnight = Date.parse('2026-10-05T00:00:00+02:00');
    expect(isDayEnding(off, midnight - 111_000, midnight)).toBe(false);
    expect(isDayEnding(off, midnight - 109_000, midnight)).toBe(true);
    expect(startBlock(off, true)).toBe('day_ending');
  });

  it('the live fixture without training key (older server) normalises to nothing', () => {
    const raw = makeLiveChildState({ training: null }) as unknown as { training?: unknown };
    expect(readChildTraining(raw.training)).toBe(EMPTY_TRAINING);
  });
});

describe('readPetTraining / parent lines', () => {
  it('disabled or missing → nothing', () => {
    expect(readPetTraining(undefined)).toBe(EMPTY_TRAINING_SUMMARY);
    expect(readPetTraining({ enabled: false, commands: [], today_done: false, session_active: false })).toBe(EMPTY_TRAINING_SUMMARY);
    expect(parentTrainingLines(EMPTY_TRAINING_SUMMARY)).toEqual([]);
  });

  it('"Kuža zna: sedi ✓, pridi 60 % …" + today + a running session', () => {
    const t = readPetTraining({
      enabled: true,
      commands: makeTrainingCommands({ sit: 100, come: 60, potty: 10 }),
      today_done: false,
      session_active: true,
    });
    expect(knowsLine(t.commands)).toBe('Kuža zna: sedi ✓, pridi 60 %, prostor 0 %, lulat zunaj 10 %');
    expect(parentTrainingLines(t)).toEqual([
      'Kuža zna: sedi ✓, pridi 60 %, prostor 0 %, lulat zunaj 10 %',
      'Današnja vaja: še ne',
      'Otrok zdaj vadi s kužkom.',
    ]);
    expect(parentTrainingLines({ ...t, today_done: true, session_active: false })[1]).toBe('Današnja vaja: opravljena ✓');
  });
});

describe('session / result / response parsers', () => {
  it('reads a playable schedule (sorted by cue)', () => {
    const raw = makeTrainingSessionPayload();
    const session = readTrainingSession({ ...raw, trials: [...raw.trials].reverse() });
    expect(session).not.toBeNull();
    expect(session?.trials.map((t) => t.cue_at_ms)).toEqual([2000, 8000, 14000, 20000, 26000, 32000, 38000, 44000]);
    expect(session?.trials[0]).toEqual({ index: 0, cue_at_ms: 2000, obeys: true, obey_at_ms: 3000, window_end_ms: 4500 });
    expect(session?.trials[1]).toEqual({ index: 1, cue_at_ms: 8000, obeys: false, obey_at_ms: null, window_end_ms: null });
  });

  it('never guesses: any malformed cue or field → null', () => {
    const raw = makeTrainingSessionPayload();
    expect(readTrainingSession(null)).toBeNull();
    expect(readTrainingSession({ ...raw, command: 'roll' })).toBeNull();
    expect(readTrainingSession({ ...raw, duration_ms: 0 })).toBeNull();
    expect(readTrainingSession({ ...raw, trials: [] })).toBeNull();
    expect(readTrainingSession({ ...raw, trials: [...raw.trials, { index: 8, cue_at_ms: 2500, obeys: true, obey_at_ms: null }] })).toBeNull();
    expect(readTrainingSession({ ...raw, trials: [{ index: 0, cue_at_ms: 60_000, obeys: false }] })).toBeNull();
    expect(readTrainingSession({ ...raw, expires_at: null })).toBeNull();
  });

  it('reads the server result; unknown outcomes dropped, progress clamped', () => {
    const r = readTrainingResult(makeTrainingResult({ progress_after: 104, trials: [{ index: 0, obeys: true, outcome: 'meh', tap_ms: 1 }, { index: 1, obeys: false, outcome: 'waited', tap_ms: null }] }));
    expect(r?.progress_after).toBe(100);
    expect(r?.trials).toEqual([{ index: 1, obeys: false, outcome: 'waited', tap_ms: null }]);
    expect(readTrainingResult({ session_id: 'x' })).toBeNull();
  });

  it('start / finish 200 bodies need status + payload + state', () => {
    const state = makeLiveChildState();
    expect(readStartResponse({ status: 'accepted', session: makeTrainingSessionPayload(), state })?.session.id).toBe(
      '7b0d7a8e-3c1f-4f7e-9d65-0a6a9c0b1e11',
    );
    expect(readStartResponse({ status: 'accepted', session: makeTrainingSessionPayload() })).toBeNull();
    expect(readStartResponse({ status: 'weird', session: makeTrainingSessionPayload(), state })).toBeNull();
    expect(readFinishResponse({ status: 'unchanged', result: makeTrainingResult(), state })?.status).toBe('unchanged');
    expect(readFinishResponse({ status: 'accepted', result: null, state })).toBeNull();
    expect(readFinishResponse([])).toBeNull();
  });
});

describe('texts', () => {
  it('refusals are calm; a sibling session names the family time', () => {
    expect(trainingRefusalMessage('training_daily_budget_used', null, 'Europe/Ljubljana')).toBe(TRAINING_STRINGS.errors.training_daily_budget_used);
    expect(trainingRefusalMessage('training_session_active', '2026-10-04T10:05:00Z', 'Europe/Ljubljana')).toBe(
      'Nekdo že vadi s kužkom. Poskusi spet ob 12:05.',
    );
    expect(trainingRefusalMessage('something_new', null, null)).toBe(TRAINING_STRINGS.errors.badResponse);
    expect(trainingRefusalMessage('training_day_ending', '2026-10-05T00:00:00+02:00', 'Europe/Ljubljana')).toBe(TRAINING_STRINGS.errors.training_day_ending);
    expect(trainingRefusalMessage('training_session_interrupted', null, null)).toBe(TRAINING_STRINGS.errors.training_session_interrupted);
  });

  it('sessions-left plural forms', () => {
    expect([1, 2, 3, 5, 0].map(TRAINING_STRINGS.sessionsLeft)).toEqual([
      'Danes še 1 vaja.',
      'Danes še 2 vaji.',
      'Danes še 3 vaje.',
      'Danes še 5 vaj.',
      'Danes še 0 vaj.',
    ]);
  });
});
