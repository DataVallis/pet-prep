/**
 * School days in quiet hours (David 2026-11-22, »Starš izbere dneve«): reading the field,
 * the Mon–Fri default for an older server, chip toggling and the save payload.
 */
import {
  DEFAULT_QUIET_TIMES,
  effectiveSchoolDays,
  quietSummary,
  quietTimesOf,
  readPetQuietHours,
  readQuietTimes,
  readSchoolDays,
  toggleSchoolDay,
  weekdayLong,
  weekdayShort,
  type QuietTimes,
} from '@/modules/quietHours/quietHours';

const SCHOOL: QuietTimes = {
  school_start: '08:00',
  school_end: '13:00',
  bedtime_start: '21:00',
  bedtime_end: '07:00',
  is_active: true,
  school_days: [1, 2, 3, 4, 5],
};

describe('readSchoolDays', () => {
  it('a missing field (older server) is null — unknown, not empty', () => {
    expect(readSchoolDays(undefined)).toBeNull();
    expect(readSchoolDays(null)).toBeNull();
    expect(readSchoolDays('1,2')).toBeNull();
  });

  it('sorts, de-duplicates and drops values outside 1..7', () => {
    expect(readSchoolDays([5, 1, 3, 1, 0, 8, 2.5, '4'])).toEqual([1, 3, 5]);
  });

  it('keeps an empty set (no school day)', () => {
    expect(readSchoolDays([])).toEqual([]);
  });
});

describe('default when the field is missing', () => {
  it('readQuietTimes without school_days → null, read as Mon–Fri', () => {
    const times = readQuietTimes({ school_start: '08:00', school_end: '13:00', bedtime_start: '21:00', bedtime_end: '07:00', is_active: true });
    expect(times?.school_days).toBeNull();
    expect(effectiveSchoolDays(times as QuietTimes)).toEqual([1, 2, 3, 4, 5]);
  });

  it('the app default is unknown too (never sent to an older server)', () => {
    expect(DEFAULT_QUIET_TIMES.school_days).toBeNull();
    expect(quietTimesOf(DEFAULT_QUIET_TIMES)).not.toHaveProperty('school_days');
  });

  it('a pet payload carries its school days', () => {
    const pet = readPetQuietHours({ quiet_hours: { ...SCHOOL, school_days: [6, 7] }, quiet_hours_follow_family: false });
    expect(pet.times?.school_days).toEqual([6, 7]);
  });
});

describe('toggleSchoolDay', () => {
  it('adds a day in ISO order and removes it again', () => {
    const withSunday = toggleSchoolDay([1, 2, 3, 4, 5], 7);
    expect(withSunday).toEqual([1, 2, 3, 4, 5, 7]);
    expect(toggleSchoolDay([3, 1], 2)).toEqual([1, 2, 3]);
    expect(toggleSchoolDay(withSunday, 7)).toEqual([1, 2, 3, 4, 5]);
  });

  it('an empty set is allowed', () => {
    expect(toggleSchoolDay([3], 3)).toEqual([]);
  });
});

describe('quietTimesOf — payload', () => {
  it('sends school_days sorted and unique when known', () => {
    expect(quietTimesOf({ ...SCHOOL, school_days: [5, 1, 1, 3] })).toEqual({
      school_start: '08:00',
      school_end: '13:00',
      bedtime_start: '21:00',
      bedtime_end: '07:00',
      is_active: true,
      school_days: [1, 3, 5],
    });
  });

  it('sends an empty set as []', () => {
    expect(quietTimesOf({ ...SCHOOL, school_days: [] }).school_days).toEqual([]);
  });

  it('omits the field when unknown', () => {
    expect(Object.keys(quietTimesOf({ ...SCHOOL, school_days: null }))).toEqual([
      'school_start',
      'school_end',
      'bedtime_start',
      'bedtime_end',
      'is_active',
    ]);
  });
});

describe('quietSummary with school days', () => {
  it('Mon–Fri (or unknown) reads as before', () => {
    expect(quietSummary(SCHOOL)).toBe('Šola 8:00–13:00 · Spanje 21:00–7:00');
    expect(quietSummary({ ...SCHOOL, school_days: null })).toBe('Šola 8:00–13:00 · Spanje 21:00–7:00');
  });

  it('other days are listed; no day = no school part', () => {
    expect(quietSummary({ ...SCHOOL, school_days: [1, 3, 6] })).toBe('Šola 8:00–13:00 (Po, Sr, So) · Spanje 21:00–7:00');
    expect(quietSummary({ ...SCHOOL, school_days: [] })).toBe('Spanje 21:00–7:00');
  });
});

describe('weekday names', () => {
  it('short chip label + full name for screen readers', () => {
    expect(weekdayShort(4)).toBe('Če');
    expect(weekdayLong(4)).toBe('četrtek');
    expect(weekdayShort(7)).toBe('Ne');
    expect(weekdayLong(1)).toBe('ponedeljek');
  });
});
