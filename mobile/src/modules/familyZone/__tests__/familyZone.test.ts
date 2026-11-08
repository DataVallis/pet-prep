/**
 * Family time zone setting (David 2026-11-08): offset comparison, banner rules, picker rows,
 * error mapping.
 */
import { ApiError } from '@/api/client';
import {
  allZones,
  formatUtcOffset,
  phoneDiffersFromFamily,
  readTimezoneChangeError,
  suggestionVisible,
  zoneChoices,
  zoneCity,
  zoneLabel,
  zoneOffsetMinutes,
} from '@/modules/familyZone/familyZone';

const WINTER = Date.parse('2026-11-08T19:30:00Z');
const SUMMER = Date.parse('2026-07-15T12:00:00Z');

describe('zoneOffsetMinutes', () => {
  it('reads the current offset of a zone, with daylight saving', () => {
    expect(zoneOffsetMinutes('Europe/Ljubljana', WINTER)).toBe(60);
    expect(zoneOffsetMinutes('Europe/Ljubljana', SUMMER)).toBe(120);
    expect(zoneOffsetMinutes('Europe/Lisbon', WINTER)).toBe(0);
    expect(zoneOffsetMinutes('America/New_York', WINTER)).toBe(-300);
    expect(zoneOffsetMinutes('Asia/Kolkata', WINTER)).toBe(330);
  });

  it('is null for a zone the device does not know', () => {
    expect(zoneOffsetMinutes('Mars/Olympus', WINTER)).toBeNull();
  });
});

describe('formatUtcOffset / zoneLabel / zoneCity', () => {
  it('formats offsets with a true minus and minutes only when needed', () => {
    expect(formatUtcOffset(0)).toBe('UTC');
    expect(formatUtcOffset(60)).toBe('UTC+1');
    expect(formatUtcOffset(-300)).toBe('UTC−5');
    expect(formatUtcOffset(330)).toBe('UTC+5:30');
    expect(formatUtcOffset(-210)).toBe('UTC−3:30');
  });

  it('names cities in Slovenian when known and falls back to the IANA city', () => {
    expect(zoneCity('Europe/Lisbon', 'sl')).toBe('Lizbona');
    expect(zoneCity('Europe/Lisbon', 'en')).toBe('Lisbon');
    expect(zoneCity('America/New_York', 'en')).toBe('New York');
    expect(zoneCity('America/Argentina/Buenos_Aires', 'sl')).toBe('Buenos Aires');
    expect(zoneLabel('Europe/Ljubljana', 'sl', WINTER)).toBe('Ljubljana · UTC+1');
    expect(zoneLabel('Mars/Olympus', 'en', WINTER)).toBe('Olympus');
  });
});

describe('phoneDiffersFromFamily', () => {
  it('compares current offsets, not names', () => {
    expect(phoneDiffersFromFamily('Europe/Ljubljana', 'Europe/Lisbon', WINTER)).toBe(true);
    expect(phoneDiffersFromFamily('Europe/Ljubljana', 'America/New_York', WINTER)).toBe(true);
    // Same clock, different name → no banner.
    expect(phoneDiffersFromFamily('Europe/Ljubljana', 'Europe/Vienna', WINTER)).toBe(false);
    expect(phoneDiffersFromFamily('Europe/Ljubljana', 'Europe/Ljubljana', WINTER)).toBe(false);
  });

  it('never differs for an unknown or missing zone', () => {
    expect(phoneDiffersFromFamily(null, 'Europe/Lisbon', WINTER)).toBe(false);
    expect(phoneDiffersFromFamily('Europe/Ljubljana', null, WINTER)).toBe(false);
    expect(phoneDiffersFromFamily('Europe/Ljubljana', 'Mars/Olympus', WINTER)).toBe(false);
  });

  it('follows daylight saving: London and Lisbon share a clock all year', () => {
    expect(phoneDiffersFromFamily('Europe/London', 'Europe/Lisbon', SUMMER)).toBe(false);
    expect(phoneDiffersFromFamily('Europe/London', 'Europe/Lisbon', WINTER)).toBe(false);
  });
});

describe('suggestionVisible (dashboard banner)', () => {
  const base = { familyZone: 'Europe/Ljubljana', phoneZone: 'Europe/Lisbon', atMs: WINTER };

  it('shows while the phone clock differs and nothing was dismissed', () => {
    expect(suggestionVisible({ ...base, dismissedZone: null })).toBe(true);
  });

  it('stays hidden for the phone zone it was dismissed in', () => {
    expect(suggestionVisible({ ...base, dismissedZone: 'Europe/Lisbon' })).toBe(false);
  });

  it('comes back only when the phone moves to yet another zone', () => {
    expect(suggestionVisible({ ...base, phoneZone: 'America/New_York', dismissedZone: 'Europe/Lisbon' })).toBe(true);
  });

  it('is hidden when the phone is back on the family clock', () => {
    expect(suggestionVisible({ ...base, phoneZone: 'Europe/Ljubljana', dismissedZone: null })).toBe(false);
  });
});

describe('zoneChoices (picker rows)', () => {
  const zones = ['America/New_York', 'Europe/Budapest', 'Europe/Lisbon', 'Europe/Ljubljana'];

  it('puts the phone zone first, without duplicates', () => {
    expect(zoneChoices({ phoneZone: 'Europe/Lisbon', query: '', zones, language: 'sl' })).toEqual([
      'Europe/Lisbon',
      'America/New_York',
      'Europe/Budapest',
      'Europe/Ljubljana',
    ]);
  });

  it('searches Slovenian and English city names and the IANA id, ignoring case and diacritics', () => {
    expect(zoneChoices({ phoneZone: null, query: 'lizbona', zones, language: 'sl' })).toEqual(['Europe/Lisbon']);
    expect(zoneChoices({ phoneZone: null, query: 'Lisbon', zones, language: 'sl' })).toEqual(['Europe/Lisbon']);
    expect(zoneChoices({ phoneZone: null, query: 'budimpesta', zones, language: 'sl' })).toEqual(['Europe/Budapest']);
    expect(zoneChoices({ phoneZone: null, query: 'new york', zones, language: 'en' })).toEqual(['America/New_York']);
    expect(zoneChoices({ phoneZone: 'Europe/Lisbon', query: 'york', zones, language: 'en' })).toEqual(['America/New_York']);
  });

  it('lists real Area/City zones from the device', () => {
    const list = allZones();
    expect(list).toContain('Europe/Ljubljana');
    expect(list.every((z) => z.includes('/'))).toBe(true);
  });
});

describe('readTimezoneChangeError', () => {
  it('maps the 24 h limit with its next time', () => {
    const err = new ApiError('too soon', 422, {
      reason: 'timezone_change_too_soon',
      next_allowed_at: '2026-11-09T19:30:00+00:00',
    });
    expect(readTimezoneChangeError(err)).toEqual({ kind: 'tooSoon', nextAllowedAt: '2026-11-09T19:30:00+00:00' });
  });

  it('maps validation, server and network failures', () => {
    expect(readTimezoneChangeError(new ApiError('bad', 422, { errors: { timezone: ['x'] } }))).toEqual({ kind: 'invalid' });
    expect(readTimezoneChangeError(new ApiError('boom', 500))).toEqual({ kind: 'failed' });
    expect(readTimezoneChangeError(new TypeError('Network request failed'))).toEqual({ kind: 'offline' });
  });
});
