/**
 * M5-R14 / R15 / R18 / R19 / R20 — breed care tasks: readers, broadcast merge, heat window by
 * the clock, texts (sl + en), nails logic (one tap per claw, server-countable taps).
 */
import { i18n } from '@/i18n';
import {
  broadcastCareTasks,
  careTaskPeriodLine,
  careTaskTimelineText,
  frequencyText,
  heatWarningActive,
  nextHeatChangeMs,
  readCareReminderReport,
  readCareReminders,
  readChildCareTasks,
  readWalkHeat,
  showCareTaskChip,
} from '@/modules/careTasks/careTasks';
import { allTrimmed, initialNails, nailTaps, nailsFinishAt, nextClaw, trimClaw } from '@/modules/careTasks/nails';
import { catRefusalMessage } from '@/modules/catCare/catCare';
import { scoreStrokes } from '@/modules/catCare/catGames';
import { makeCareTask, makeCareTaskSession } from '@/test-utils/fixtures';

afterEach(async () => {
  await i18n.changeLanguage('sl');
});

describe('readers', () => {
  it('reads the child tasks in a fixed order, drops unknown / malformed entries', () => {
    const tasks = readChildCareTasks([
      makeCareTask({ task: 'nail_trim', times: 1, period_days: 28 }),
      makeCareTask(),
      { task: 'groomer_visit', times: 1, period_days: 28 },
      { task: 'ear_cleaning', times: 0, period_days: 7 },
      'nope',
    ]);
    expect(tasks.map((t) => t.task)).toEqual(['tooth_brushing', 'nail_trim']);
    expect(readChildCareTasks(undefined)).toEqual([]);
    const withSession = readChildCareTasks([makeCareTask({ session: makeCareTaskSession(), session_running: true })])[0];
    expect(withSession.session).toMatchObject({ task: 'tooth_brushing', duration_ms: 30000, min_strokes: 10, segments: 3 });
    // A session of another task is never taken.
    expect(readChildCareTasks([makeCareTask({ session: makeCareTaskSession({ task: 'nail_trim' }) })])[0].session).toBeNull();
  });

  it('reads the heat window, the parent reminders and the report rows', () => {
    expect(readWalkHeat(null)).toBeNull();
    expect(readWalkHeat({ starts_at: '2026-07-11T11:00:00+02:00', ends_at: '2026-07-11T16:00:00+02:00', active: true })).toEqual({
      starts_at: '2026-07-11T11:00:00+02:00',
      ends_at: '2026-07-11T16:00:00+02:00',
      active: true,
    });
    expect(readCareReminders([{ task: 'groomer_visit', period_days: 28, period_started_at: null, period_ends_at: '2026-11-17T08:00:00+01:00', done: false, done_at: null }, { task: 'tooth_brushing', period_days: 7 }])).toHaveLength(1);
    expect(readCareReminderReport([{ task: 'bath', status: 'not_done' }, { task: 'bath', status: 'weird' }])).toEqual([
      { task: 'bath', period_started_at: null, period_ends_at: null, done_at: null, status: 'not_done' },
    ]);
  });
});

describe('broadcast merge and chips', () => {
  it('keeps the own running session, turns can_start off while locked, keeps the list for an older server', () => {
    const mine = readChildCareTasks([makeCareTask({ session: makeCareTaskSession(), session_running: true, can_start: true })]);
    const merged = broadcastCareTasks(mine, [makeCareTask({ session: null, session_running: true, can_start: false })], false);
    expect(merged[0].session?.id).toBe(makeCareTaskSession().id);
    expect(broadcastCareTasks(mine, undefined, true)[0].can_start).toBe(false);
    expect(broadcastCareTasks(mine, [], false)).toEqual([]);
  });

  it('shows a chip while the period has an open slot and nothing is locked', () => {
    const [open] = readChildCareTasks([makeCareTask({ done_this_period: 3 })]);
    const [done] = readChildCareTasks([makeCareTask({ done_this_period: 7 })]);
    expect(showCareTaskChip(open, false)).toBe(true);
    expect(showCareTaskChip(open, true)).toBe(false);
    expect(showCareTaskChip(done, false)).toBe(false);
  });
});

describe('heat window by the clock (no weather data)', () => {
  const heat = { starts_at: '2026-07-11T11:00:00+02:00', ends_at: '2026-07-11T16:00:00+02:00', active: false };
  it('is on from 11:00 until 16:00 family time, whatever the snapshot said', () => {
    expect(heatWarningActive(heat, Date.parse('2026-07-11T10:59:59+02:00'))).toBe(false);
    expect(heatWarningActive(heat, Date.parse('2026-07-11T11:00:00+02:00'))).toBe(true);
    expect(heatWarningActive(heat, Date.parse('2026-07-11T15:59:59+02:00'))).toBe(true);
    expect(heatWarningActive(heat, Date.parse('2026-07-11T16:00:00+02:00'))).toBe(false);
    expect(heatWarningActive(null, Date.parse('2026-07-11T12:00:00+02:00'))).toBe(false);
    expect(nextHeatChangeMs(heat, Date.parse('2026-07-11T09:00:00+02:00'))).toBe(Date.parse(heat.starts_at));
    expect(nextHeatChangeMs(heat, Date.parse('2026-07-11T12:00:00+02:00'))).toBe(Date.parse(heat.ends_at));
    expect(nextHeatChangeMs(heat, Date.parse('2026-07-11T17:00:00+02:00'))).toBeNull();
  });
});

describe('texts', () => {
  it('words the sourced frequencies (sl, en)', async () => {
    expect([frequencyText(7, 7), frequencyText(1, 7), frequencyText(1, 28), frequencyText(1, 14), frequencyText(1, 84), frequencyText(1, 42), frequencyText(1, 56)]).toEqual([
      'vsak dan',
      'enkrat na teden',
      'enkrat na mesec',
      'vsaka dva tedna',
      'vsake 3 mesece',
      'vsakih 6 tednov',
      'vsaka dva meseca',
    ]);
    await i18n.changeLanguage('en');
    expect([frequencyText(7, 7), frequencyText(1, 28), frequencyText(1, 84)]).toEqual(['every day', 'once a month', 'every 3 months']);
  });

  it('names the period and the refusals calmly (never a scolding word)', () => {
    const [week] = readChildCareTasks([makeCareTask({ done_this_period: 2 })]);
    expect(careTaskPeriodLine(week)).toBe('Ta teden: 2 od 7');
    const [month] = readChildCareTasks([makeCareTask({ task: 'nail_trim', times: 1, period_days: 28, done_this_period: 1 })]);
    expect(careTaskPeriodLine(month)).toBe('Ta mesec je vse opravljeno ✓');
    const ctx = { nowIso: '2026-10-04T22:00:00+02:00', timezone: 'Europe/Ljubljana', dog: true };
    expect(catRefusalMessage('care_task_quiet_hours', '2026-10-05T07:00:00+02:00', ctx)).toBe('Kuža zdaj spi. Pusti ga počivati — lahko to narediš jutri ob 7:00.');
    expect(catRefusalMessage('care_task_done_today', null, ctx)).toBe('Za danes je opravljeno. Spet jutri.');
  });

  it('words the parent timeline rows', () => {
    expect(careTaskTimelineText('brushed_teeth', 'Mia')).toBe('Ščetkanje zob · Mia');
    expect(careTaskTimelineText('parent_groomer_visit', null)).toBe('Obisk pasjega frizerja');
    expect(careTaskTimelineText('fed_pet', 'Mia')).toBeNull();
  });
});

describe('nails: one tap per claw', () => {
  const rules = { duration_ms: 12_000, min_strokes: 8, segments: 1, min_stroke_interval_ms: 150 };

  it('trims each claw once, ignores a second tap and taps closer than the interval', () => {
    let s = initialNails(rules);
    expect(s.trimmed).toHaveLength(8);
    let r = trimClaw(s, 0, 500, rules);
    s = r.state;
    expect(r.stroke).toBe(500);
    expect(trimClaw(s, 0, 900, rules).stroke).toBeNull();
    expect(trimClaw(s, 1, 600, rules).stroke).toBeNull();
    r = trimClaw(s, 1, 700, rules);
    s = r.state;
    expect(nextClaw(s)).toBe(2);
    expect(trimClaw(s, 9, 2000, rules).stroke).toBeNull();
    expect(trimClaw(s, 2, 13_000, rules).stroke).toBeNull();
  });

  it('all claws = taps the server counts; finishes up to 2 s early', () => {
    let s = initialNails(rules);
    [400, 1150, 2000, 2700, 3650, 4300, 5250, 6100].forEach((ms, i) => {
      s = trimClaw(s, i, ms, rules).state;
    });
    expect(allTrimmed(s)).toBe(true);
    expect(scoreStrokes(nailTaps(s), rules)).toMatchObject({ success: true });
    expect(nailsFinishAt(rules, nailTaps(s))).toBe(10_000);
    expect(nailsFinishAt(rules, nailTaps(s).slice(0, 5))).toBe(12_000);
  });
});
