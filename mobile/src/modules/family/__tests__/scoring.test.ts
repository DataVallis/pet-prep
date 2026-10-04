/**
 * M2-05: reading the loosely typed M2-06 payloads and the parent's Slovenian texts.
 * Jest runs with TZ=UTC; the family is Europe/Ljubljana (+02:00 in October).
 */
import {
  activityText,
  activityWhenText,
  dayLabel,
  formatAmount,
  illnessesText,
  missedWhenText,
  progressShare,
  progressText,
  readChildReport,
  readTimeline,
  readToday,
  readTrafficLight,
  reasonText,
  routinesOfText,
} from '@/modules/family/scoring';
import { makeMissed } from '@/test-utils/fixtures';

const TZ = 'Europe/Ljubljana';

describe('readers', () => {
  it('traffic light: known colours / reasons only, green by default', () => {
    expect(readTrafficLight({ color: 'red', reasons: ['game_over', 'bogus', 'fell_ill_today'] })).toEqual({
      color: 'red',
      reasons: ['game_over', 'fell_ill_today'],
    });
    expect(readTrafficLight({ color: 'purple', reasons: 'x' })).toEqual({ color: 'green', reasons: [] });
    expect(readTrafficLight(undefined)).toEqual({ color: 'green', reasons: [] });
  });

  it('today block drops unknown routine types and keeps a null done_by_child (pet block)', () => {
    const today = readToday({
      date: '2026-10-04',
      expected: 5,
      done: 2,
      done_by_child: null,
      pending: 0,
      missed_count: 3,
      missed: [makeMissed('feed', 'a', 'b'), { type: 'nap', date: 'x' }, makeMissed('clean', 'c', 'd')],
    });
    expect(today.done_by_child).toBeNull();
    expect(today.missed.map((m) => m.type)).toEqual(['feed', 'clean']);
    expect(today.missed_count).toBe(3);
  });

  it('timeline: ids / values as numbers, nickname kept, system rows negative', () => {
    expect(
      readTimeline([
        { id: '12', activity_type: 'fed_pet', value: '30', actor_user_id: '2', actor_nickname: 'Luka', created_at: 'x', is_positive: true },
        { id: 13, activity_type: 'ignored_warning', value: null, actor_user_id: null, created_at: null },
        { activity_type: 'fed_pet' },
      ]),
    ).toEqual([
      { id: 12, activity_type: 'fed_pet', value: 30, actor_user_id: 2, actor_nickname: 'Luka', created_at: 'x', is_positive: true },
      { id: 13, activity_type: 'ignored_warning', value: null, actor_user_id: null, actor_nickname: null, created_at: null, is_positive: false },
    ]);
  });

  it('child report: full shape, null for a non-report body', () => {
    const report = readChildReport({
      child: { id: 2, name: 'Luka' },
      pet_id: 7,
      timezone: TZ,
      days: 30,
      from: '2026-09-05',
      to: '2026-10-04',
      traffic_light: { color: 'yellow', reasons: ['missed_routines'] },
      care_score: { score: 80, done: 40, expected: 50, routines: 50, illnesses: 0, since: null },
      period_score: { score: null, done: 0, expected: 0, illnesses: 0, since: null },
      progress: null,
      by_type: { feed: { expected: 2, done: 2, done_by_child: 1, missed: 0, pending: 0 } },
      daily: [{ date: '2026-10-04', expected: 3, walk_goal: null, walk_done: null }],
      missed: [makeMissed('water', 'a', 'b')],
      illnesses: [{ started_at: '2026-10-01T10:00:00+02:00', ended_at: null }, { bogus: 1 }],
    });
    expect(report?.days).toBe(30);
    expect(report?.period_score.score).toBeNull();
    expect(report?.by_type.feed.done_by_child).toBe(1);
    expect(report?.by_type.walk).toEqual({ expected: 0, done: 0, done_by_child: 0, missed: 0, pending: 0 });
    expect(report?.daily[0]).toMatchObject({ date: '2026-10-04', expected: 3, walk_goal: null, walk_done: null, walk_steps: 0 });
    expect(report?.illnesses).toHaveLength(1);
    expect(readChildReport([])).toBeNull();
    expect(readChildReport({ message: 'x' })).toBeNull();
  });
});

describe('texts', () => {
  it('amounts and "x od y rutin" (fair share can be fractional)', () => {
    expect(formatAmount(9)).toBe('9');
    expect(formatAmount(9.5)).toBe('9,5');
    expect(formatAmount(2.333)).toBe('2,3');
    expect(routinesOfText(8, 9.5)).toBe('8 od 9,5 rutin');
    expect(routinesOfText(1, 1)).toBe('1 od 1 rutine');
    expect(routinesOfText(31, 36)).toBe('31 od 36 rutin');
  });

  it('illnesses and reasons in friendly Slovenian', () => {
    expect(illnessesText(1)).toBe('1 bolezen');
    expect(illnessesText(3)).toBe('3 bolezni');
    expect(reasonText('game_over')).toMatch(/odvzet/);
    expect(reasonText('phase3_alarm')).toMatch(/več kot uro/);
    expect(reasonText('fell_ill_today')).toMatch(/danes zbolel/);
    expect(reasonText('missed_routines', 3)).toBe('Danes so zamujene že 3 rutine.');
    expect(reasonText('missed_routines', 5)).toBe('Danes je zamujenih že 5 rutin.');
    expect(reasonText('missed_routines')).toBe('Danes so zamujene več kot 2 rutini.');
  });

  it('12-week progress', () => {
    const p = { started_at: 'x', days_elapsed: 14, week: 3, weeks_total: 12, completed: false };
    expect(progressText(p)).toBe('Teden 3 od 12');
    expect(progressText({ ...p, completed: true, days_elapsed: 90 })).toBe('Izziv zaključen (12 tednov)');
    expect(progressText(null)).toBeNull();
    expect(progressShare(p)).toBeCloseTo(14 / 84);
    expect(progressShare({ ...p, days_elapsed: 200 })).toBe(1);
  });

  it('day labels', () => {
    expect(dayLabel('2026-10-04')).toBe('ned 4. 10.');
    expect(dayLabel('2026-09-28')).toBe('pon 28. 9.');
    expect(dayLabel('bogus')).toBe('bogus');
  });

  it('missed routine time in the family wall clock (device on UTC)', () => {
    expect(missedWhenText(makeMissed('feed', '2026-10-04T07:00:00+02:00', '2026-10-04T09:00:00+02:00'), TZ)).toBe(
      'okno 07:00–09:00',
    );
    // UTC instants are shown in Ljubljana time.
    expect(missedWhenText(makeMissed('clean', '2026-10-04T10:00:00Z', '2026-10-04T12:30:00Z'), TZ)).toBe('rok 14:30');
    expect(missedWhenText(makeMissed('water', 'a', 'b'), TZ)).toBe('do konca dneva');
    // Yesterday's mess with a deadline today gets its date.
    expect(
      missedWhenText(makeMissed('clean', '2026-10-03T21:50:00+02:00', '2026-10-04T07:50:00+02:00', '2026-10-03'), TZ, '2026-10-04'),
    ).toBe('sob 3. 10. · rok 07:50');
  });

  it('timeline texts with nickname, family clock, today / yesterday', () => {
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Maja' })).toBe('Maja nahranil(a) kužka');
    expect(activityText({ activity_type: 'watered_pet', actor_nickname: null })).toBe('Nalil(a) vodo');
    expect(activityText({ activity_type: 'ignored_warning', actor_nickname: null })).toBe('Opozorilo ni bilo upoštevano');
    expect(activityWhenText('2026-10-04T05:15:00Z', TZ, '2026-10-04')).toBe('07:15');
    expect(activityWhenText('2026-10-03T18:00:00+02:00', TZ, '2026-10-04')).toBe('včeraj 18:00');
    expect(activityWhenText('2026-10-01T18:00:00+02:00', TZ, '2026-10-04')).toBe('1. 10. 18:00');
    expect(activityWhenText(null, TZ, '2026-10-04')).toBe('');
  });
});
