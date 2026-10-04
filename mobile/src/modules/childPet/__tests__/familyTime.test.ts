import { familyClock, isLaterDay, localParts, whenText } from '@/modules/childPet/familyTime';

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
