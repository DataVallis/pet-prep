import {
  familyCalendar,
  familyClock,
  isLaterDay,
  isoOffsetMinutes,
  localParts,
  lockClock,
  whenText,
} from '@/modules/childPet/familyTime';

describe('familyCalendar (device on UTC)', () => {
  it('Ljubljana: day and midnight differ from the device day', () => {
    const lj = familyCalendar('Europe/Ljubljana');
    const lateUtc = Date.parse('2026-10-04T22:30:00Z'); // 00:30 on 5 Oct in Ljubljana
    expect(new Date(lateUtc).getUTCDate()).toBe(4);
    expect(lj.dateOf(lateUtc)).toBe('2026-10-05');
    expect(new Date(lj.startOfDay(lateUtc)).toISOString()).toBe('2026-10-04T22:00:00.000Z');
    expect(new Date(lj.nextMidnight(Date.parse('2026-10-04T10:00:00Z'))).toISOString()).toBe('2026-10-04T22:00:00.000Z');
  });

  it('New York: the family is still on yesterday', () => {
    const ny = familyCalendar('America/New_York');
    const early = Date.parse('2026-10-04T02:00:00Z'); // 22:00 on 3 Oct
    expect(ny.dateOf(early)).toBe('2026-10-03');
    expect(new Date(ny.startOfDay(early)).toISOString()).toBe('2026-10-03T04:00:00.000Z');
  });

  it('DST: the 25 Oct 2026 day in Ljubljana starts at 22:00Z (CEST) and the next at 23:00Z (CET)', () => {
    const lj = familyCalendar('Europe/Ljubljana');
    const dstDay = Date.parse('2026-10-25T12:00:00Z');
    expect(new Date(lj.startOfDay(dstDay)).toISOString()).toBe('2026-10-24T22:00:00.000Z');
    expect(new Date(lj.nextMidnight(dstDay)).toISOString()).toBe('2026-10-25T23:00:00.000Z');
  });

  it('unknown zone → falls back to the offset in the server instant', () => {
    const cal = familyCalendar('Not/AZone', '2026-10-04T12:00:00+02:00');
    expect(cal.dateOf(Date.parse('2026-10-04T22:30:00Z'))).toBe('2026-10-05');
    expect(isoOffsetMinutes('2026-10-04T12:00:00-04:00')).toBe(-240);
    expect(isoOffsetMinutes('2026-10-04T12:00:00Z')).toBe(0);
    expect(isoOffsetMinutes('2026-10-04T12:00:00')).toBeNull();
  });
});

describe('lockClock (m3)', () => {
  it('uses the wall clock of a family-offset instant, Intl for UTC', () => {
    expect(lockClock('2026-10-04T18:30:00+02:00', 'America/New_York')).toBe('18:30');
    expect(lockClock('2026-10-04T16:30:00Z', 'Europe/Ljubljana')).toBe('18:30');
    expect(lockClock(null, 'Europe/Ljubljana')).toBeNull();
  });
});

describe('familyTime', () => {
  it('shows a family-offset instant at its wall clock', () => {
    expect(familyClock('2026-10-04T17:00:00+02:00', 'Europe/Ljubljana')).toBe('17:00');
  });

  it('converts a UTC broadcast instant into the family timezone', () => {
    expect(familyClock('2026-10-04T16:30:00Z', 'Europe/Ljubljana')).toBe('18:30');
    expect(familyClock('2026-10-04T16:30:00Z', 'America/New_York')).toBe('12:30');
  });

  it('follows DST: 25 Oct 2026 after the fall-back is UTC+1 in Ljubljana', () => {
    expect(familyClock('2026-10-25T05:00:00Z', 'Europe/Ljubljana')).toBe('06:00');
    expect(familyClock('2026-10-24T04:00:00Z', 'Europe/Ljubljana')).toBe('06:00');
  });

  it('falls back to the wall clock in the string for an unknown zone', () => {
    expect(localParts('2026-10-04T17:05:00+02:00', 'Not/AZone')).toEqual({ date: '2026-10-04', time: '17:05' });
    expect(familyClock(null, 'Europe/Ljubljana')).toBeNull();
    expect(familyClock('soon', 'Europe/Ljubljana')).toBeNull();
  });

  it('says "jutri" for the next family-local day', () => {
    const now = '2026-10-04T22:00:00+02:00';
    expect(whenText('2026-10-05T06:00:00+02:00', now, 'Europe/Ljubljana')).toBe('jutri ob 06:00');
    expect(whenText('2026-10-04T23:30:00+02:00', now, 'Europe/Ljubljana')).toBe('ob 23:30');
    expect(isLaterDay('2026-10-05T00:00:00+02:00', now, 'Europe/Ljubljana')).toBe(true);
    expect(isLaterDay('2026-10-04T23:00:00+02:00', now, 'Europe/Ljubljana')).toBe(false);
  });

  it('uses the family day, not the UTC day (New York evening is already tomorrow in UTC)', () => {
    const now = '2026-10-04T19:00:00-04:00'; // 23:00Z
    expect(whenText('2026-10-04T21:00:00-04:00', now, 'America/New_York')).toBe('ob 21:00'); // 01:00Z next day
  });
});
