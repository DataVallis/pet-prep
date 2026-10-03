import { ApiError } from '@/api/client';
import { classifyPinError, formatCountdown, formatPin, secondsUntil } from '@/modules/pairing/pin';

describe('formatPin', () => {
  it('splits a 6-digit PIN into two groups of three', () => {
    expect(formatPin('734912')).toBe('734 912');
  });

  it('keeps leading zeros', () => {
    expect(formatPin('004501')).toBe('004 501');
  });

  it('leaves anything that is not exactly 6 digits unchanged (trimmed)', () => {
    expect(formatPin(' 12345 ')).toBe('12345');
    expect(formatPin('12a456')).toBe('12a456');
  });
});

describe('secondsUntil', () => {
  const now = Date.parse('2026-10-03T10:00:00Z');

  it('returns the full 15 minutes for a fresh PIN', () => {
    expect(secondsUntil('2026-10-03T10:15:00Z', now)).toBe(900);
  });

  it('rounds partial seconds up so the countdown never shows 0 early', () => {
    expect(secondsUntil('2026-10-03T10:00:00.400Z', now)).toBe(1);
  });

  it('never goes negative after expiry', () => {
    expect(secondsUntil('2026-10-03T09:59:00Z', now)).toBe(0);
  });

  it('understands the backend ISO 8601 offset format', () => {
    expect(secondsUntil('2026-10-03T12:15:00+02:00', now)).toBe(900);
  });

  it('treats an unparsable date as expired', () => {
    expect(secondsUntil('not-a-date', now)).toBe(0);
  });
});

describe('formatCountdown', () => {
  it.each([
    [900, '15:00'],
    [899, '14:59'],
    [61, '1:01'],
    [59, '0:59'],
    [0, '0:00'],
    [-5, '0:00'],
  ])('%i s → %s', (seconds, expected) => {
    expect(formatCountdown(seconds)).toBe(expected);
  });
});

describe('classifyPinError', () => {
  it('maps 429 to rate_limited with the Retry-After seconds', () => {
    expect(classifyPinError(new ApiError('Too Many Attempts.', 429, null, 42))).toEqual({
      kind: 'rate_limited',
      retryAfterSeconds: 42,
    });
  });

  it('falls back to 60 s when a 429 has no Retry-After header', () => {
    expect(classifyPinError(new ApiError('Too Many Attempts.', 429))).toEqual({
      kind: 'rate_limited',
      retryAfterSeconds: 60,
    });
  });

  it('maps 403 / 401 / 500', () => {
    expect(classifyPinError(new ApiError('x', 403)).kind).toBe('forbidden');
    expect(classifyPinError(new ApiError('x', 401)).kind).toBe('unauthorized');
    expect(classifyPinError(new ApiError('x', 500)).kind).toBe('server');
  });

  it('treats a fetch TypeError as offline', () => {
    expect(classifyPinError(new TypeError('Network request failed')).kind).toBe('offline');
  });
});
