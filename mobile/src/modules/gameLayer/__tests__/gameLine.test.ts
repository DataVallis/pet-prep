import {
  EMPTY_GAME_LINE,
  GAME_LINE_MAX_CHARS,
  GAME_LINE_MIN_MS,
  clampGameLine,
  nextLineChangeAt,
  sayLine,
  tickLine,
} from '@/modules/gameLayer/gameLine';
import { feedbackStartScale, feedbackTimeline, FEEDBACK_ENTER_MS, FEEDBACK_EXIT_MS, MASK_OPACITY } from '@/modules/gameLayer/feedback';
import { gameFeedbackOf, readGameFeedback } from '@/modules/gameLayer/gameFeedback';

describe('GameLine (GAMES_SPEC §3.1: one line, ≤ 32 chars, ≥ 2 s)', () => {
  it('shows the first line at once', () => {
    const s = sayLine(EMPTY_GAME_LINE, 'Povleci in spusti žogo!', 0);
    expect(s.shown).toBe('Povleci in spusti žogo!');
    expect(s.since).toBe(0);
  });

  it('a newer line waits until the shown one had its 2 s; the newest waiting one wins', () => {
    let s = sayLine(EMPTY_GAME_LINE, 'A', 0);
    s = sayLine(s, 'B', 500);
    s = sayLine(s, 'C', 900);
    expect(s.shown).toBe('A');
    expect(nextLineChangeAt(s)).toBe(GAME_LINE_MIN_MS);
    expect(tickLine(s, GAME_LINE_MIN_MS - 1)).toBe(s);
    s = tickLine(s, GAME_LINE_MIN_MS);
    expect(s.shown).toBe('C');
    expect(s.since).toBe(GAME_LINE_MIN_MS);
    expect(nextLineChangeAt(s)).toBeNull();
  });

  it('clear waits as well and the same text is ignored', () => {
    let s = sayLine(EMPTY_GAME_LINE, 'A', 0);
    expect(sayLine(s, 'A', 100)).toBe(s);
    s = sayLine(s, null, 100);
    expect(s.shown).toBe('A');
    s = tickLine(s, 2_000);
    expect(s.shown).toBeNull();
    // After 2 s a line changes at once.
    s = sayLine(s, 'B', 5_000);
    expect(s.shown).toBe('B');
  });

  it('asking again for the shown line drops a waiting change', () => {
    let s = sayLine(EMPTY_GAME_LINE, 'A', 0);
    s = sayLine(s, 'B', 100);
    s = sayLine(s, 'A', 200);
    expect(s.waiting).toBeUndefined();
    expect(tickLine(s, 5_000).shown).toBe('A');
  });

  it('never more than 32 characters', () => {
    const long = 'Ta vrstica je zagotovo predolga za eno vrstico zgoraj';
    const clamped = clampGameLine(long);
    expect(Array.from(clamped).length).toBeLessThanOrEqual(GAME_LINE_MAX_CHARS);
    expect(clamped.endsWith('…')).toBe(true);
    expect(clampGameLine('  Kratko   besedilo ')).toBe('Kratko besedilo');
  });
});

describe('FeedbackLayer timing', () => {
  it('masks only between the end of the enter and the start of the exit', () => {
    const t = feedbackTimeline('ok', { mask: true, totalMs: 1_200 });
    expect(t).toEqual({ enterMs: FEEDBACK_ENTER_MS, holdMs: 1_200 - FEEDBACK_ENTER_MS - FEEDBACK_EXIT_MS, exitMs: FEEDBACK_EXIT_MS, maskFrom: 150, maskUntil: 950, totalMs: 1_200 });
    expect(feedbackTimeline('miss').maskFrom).toBeNull();
    expect(MASK_OPACITY).toBeGreaterThanOrEqual(0.85);
  });

  it('never shorter than enter + exit; reduce motion = no scale', () => {
    expect(feedbackTimeline('star', { totalMs: 10 }).totalMs).toBe(FEEDBACK_ENTER_MS + FEEDBACK_EXIT_MS);
    expect(feedbackStartScale('ok', true)).toBe(1);
    expect(feedbackStartScale('ok', false)).toBeLessThan(1);
  });
});

describe('game_feedback switch (default on)', () => {
  it('only an explicit false turns sounds and haptics off', () => {
    expect(readGameFeedback(undefined)).toBe(true);
    expect(readGameFeedback(null)).toBe(true);
    expect(readGameFeedback(true)).toBe(true);
    expect(readGameFeedback(false)).toBe(false);
    expect(gameFeedbackOf(null)).toBe(true);
    expect(gameFeedbackOf({ settings: {} })).toBe(true);
    expect(gameFeedbackOf({ settings: { game_feedback: false } })).toBe(false);
  });
});
