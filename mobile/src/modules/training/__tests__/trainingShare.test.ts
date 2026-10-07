/**
 * M5-R03b: fair share of the daily training time between siblings (`children_sharing`,
 * `my_share_seconds`, `my_seconds_left`), the `training_child_share_used` refusal, the
 * HUD dot and starting progress of a dog that arrived older (sit 50 %, potty 70 % …).
 */
import {
  EMPTY_TRAINING,
  isTimeShared,
  knowsLine,
  readChildTraining,
  sessionsLeftToday,
  showTrainingDot,
  startBlock,
  TRAINING_STRINGS,
  trainingRefusalMessage,
} from '@/modules/training/training';
import { makeEnabledTraining, makeTrainingState } from '@/test-utils/fixtures';

const S = TRAINING_STRINGS;

/** What a dog that arrived young/adult/senior shows before its first session. */
const STARTING_COMMANDS = [
  { command: 'sit' as const, progress: 50, learned: false, last_practised_at: null },
  { command: 'come' as const, progress: 30, learned: false, last_practised_at: null },
  { command: 'place' as const, progress: 0, learned: false, last_practised_at: null },
  { command: 'potty' as const, progress: 70, learned: false, last_practised_at: null },
];

describe('fixture defaults (M5-R03b)', () => {
  it('training off: one child, no share; enabled: one child with the whole 300 s', () => {
    expect(makeTrainingState()).toMatchObject({ children_sharing: 1, my_share_seconds: 0, my_seconds_left: 0 });
    expect(makeEnabledTraining()).toMatchObject({ children_sharing: 1, my_share_seconds: 300, my_seconds_left: 300, daily_budget_left_seconds: 300 });
    expect(EMPTY_TRAINING).toMatchObject({ children_sharing: 1, my_share_seconds: 0, my_seconds_left: 0 });
  });
});

describe('readChildTraining — share fields', () => {
  it('reads the share; my time is capped by what the dog has left', () => {
    const t = readChildTraining(makeEnabledTraining({ children_sharing: 2, my_share_seconds: 150, my_seconds_left: 150, daily_budget_left_seconds: 100 }));
    expect(t.children_sharing).toBe(2);
    expect(t.my_share_seconds).toBe(150);
    expect(t.my_seconds_left).toBe(100);
  });

  it('an older server without the share keeps the old behaviour (the whole budget is mine)', () => {
    const raw: Record<string, unknown> = { ...makeEnabledTraining({ daily_budget_left_seconds: 200 }) };
    delete raw.children_sharing;
    delete raw.my_share_seconds;
    delete raw.my_seconds_left;
    const t = readChildTraining(raw);
    expect(t.children_sharing).toBe(1);
    expect(t.my_share_seconds).toBe(300);
    expect(t.my_seconds_left).toBe(200);
    expect(sessionsLeftToday(t)).toBe(4);
  });

  it('malformed values are clamped (never negative, at least one child)', () => {
    const t = readChildTraining(makeEnabledTraining({ children_sharing: 0, my_seconds_left: -20, my_share_seconds: -1 }));
    expect(t.children_sharing).toBe(1);
    expect(t.my_seconds_left).toBe(0);
    expect(t.my_share_seconds).toBe(0);
  });
});

describe('sessions left from my_seconds_left', () => {
  it('two children: 150 s → 3 sessions; after one (100 s) → 2; pet budget is not what counts', () => {
    const fresh = readChildTraining(makeEnabledTraining({ children_sharing: 2, my_share_seconds: 150, my_seconds_left: 150, daily_budget_left_seconds: 300 }));
    expect(sessionsLeftToday(fresh)).toBe(3);
    const afterOne = readChildTraining(makeEnabledTraining({ children_sharing: 2, my_share_seconds: 150, my_seconds_left: 100, daily_budget_left_seconds: 250 }));
    expect(sessionsLeftToday(afterOne)).toBe(2);
    expect(TRAINING_STRINGS.sessionsLeft(sessionsLeftToday(afterOne))).toBe('Danes še 2 vaji.');
  });

  it('one child: the whole 300 s → 6 sessions', () => {
    expect(sessionsLeftToday(readChildTraining(makeEnabledTraining()))).toBe(6);
  });

  it('my share used while the dog still has time → "share_used" (not "budget_used")', () => {
    const t = readChildTraining(makeEnabledTraining({ children_sharing: 2, my_share_seconds: 150, my_seconds_left: 0, daily_budget_left_seconds: 150, can_start: false }));
    expect(sessionsLeftToday(t)).toBe(0);
    expect(startBlock(t)).toBe('share_used');
    expect(S.blocked.share_used).toBe(S.errors.training_child_share_used);
    // The dog's whole budget used wins (same order as the server).
    const all = readChildTraining(makeEnabledTraining({ children_sharing: 2, my_seconds_left: 0, daily_budget_left_seconds: 0, can_start: false }));
    expect(startBlock(all)).toBe('budget_used');
  });
});

describe('shared-time hint', () => {
  it('only when more than one child shares the time', () => {
    expect(isTimeShared(readChildTraining(makeEnabledTraining()))).toBe(false);
    expect(isTimeShared(readChildTraining(makeEnabledTraining({ children_sharing: 2 })))).toBe(true);
    expect(isTimeShared(EMPTY_TRAINING)).toBe(false);
    expect(S.sharedTime(2)).toBe('Čas za šolo si deliš z bratom ali sestro.');
    expect(S.sharedTime(3)).toBe('Čas za šolo si deliš z brati in sestrami.');
  });
});

describe('training_child_share_used refusal', () => {
  it('maps to a kind message', () => {
    const msg = trainingRefusalMessage('training_child_share_used', '2026-10-05T00:00:00+02:00', 'Europe/Ljubljana');
    expect(msg).toBe('Tvoj današnji čas za šolo je porabljen. Brat ali sestra lahko s kužkom še vadi — ti pa spet jutri!');
  });
});

describe('HUD dot (today open AND I can still train)', () => {
  it('shows only while my time is left and today is open', () => {
    expect(showTrainingDot(readChildTraining(makeEnabledTraining()))).toBe(true);
    expect(showTrainingDot(readChildTraining(makeEnabledTraining({ today_done: true })))).toBe(false);
    expect(showTrainingDot(readChildTraining(makeEnabledTraining({ my_seconds_left: 0, can_start: false })))).toBe(false);
    expect(showTrainingDot(readChildTraining(makeEnabledTraining({ my_seconds_left: 40, can_start: false })))).toBe(false);
    // Not signed the contract yet → no share → no dot.
    expect(showTrainingDot(readChildTraining(makeEnabledTraining({ children_sharing: 1, my_share_seconds: 0, my_seconds_left: 0, can_start: false })))).toBe(false);
  });
});

describe('starting progress of a dog that arrived older', () => {
  it('reads 50 / 30 / 0 / 70 % as-is, nothing "learned", never practised', () => {
    const t = readChildTraining(makeEnabledTraining({ commands: STARTING_COMMANDS }));
    expect(t.commands.map((c) => [c.command, c.progress, c.learned, c.last_practised_at])).toEqual([
      ['sit', 50, false, null],
      ['come', 30, false, null],
      ['place', 0, false, null],
      ['potty', 70, false, null],
    ]);
    expect(knowsLine(t.commands)).toBe('Kuža zna: sedi 50 %, pridi 30 %, prostor 0 %, lulat zunaj 70 %');
  });
});
