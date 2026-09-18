/**
 * Tests for metric color interpolation utility functions.
 */

import {
  getMetricColor,
  interpolateColor,
  formatStepCount,
  formatVirtualAge,
  isActionDisabled,
} from '@/utils/metrics';

describe('getMetricColor', () => {
  it('returns green for high levels (>=60)', () => {
    expect(getMetricColor(100)).toBe('#10B981');
    expect(getMetricColor(80)).toBe('#10B981');
    expect(getMetricColor(60)).toBe('#10B981');
  });

  it('returns amber for medium levels (30-59)', () => {
    expect(getMetricColor(50)).toBe('#F59E0B');
    expect(getMetricColor(40)).toBe('#F59E0B');
    expect(getMetricColor(30)).toBe('#F59E0B');
  });

  it('returns red for low levels (<30)', () => {
    expect(getMetricColor(20)).toBe('#EF4444');
    expect(getMetricColor(10)).toBe('#EF4444');
    expect(getMetricColor(0)).toBe('#EF4444');
  });
});

describe('interpolateColor', () => {
  it('returns high color at 100%', () => {
    const color = interpolateColor(100);
    expect(color.toLowerCase()).toBe('#10b981');
  });

  it('returns mid color at 50%', () => {
    const color = interpolateColor(50);
    expect(color.toLowerCase()).toBe('#f59e0b');
  });

  it('returns low color at 0%', () => {
    const color = interpolateColor(0);
    expect(color.toLowerCase()).toBe('#ef4444');
  });

  it('interpolates between high and mid for values between 50-100', () => {
    const color = interpolateColor(75);
    // Should be between green (#10B981) and amber (#F59E0B)
    expect(color).not.toBe('#10B981');
    expect(color).not.toBe('#F59E0B');
    // Should start with #
    expect(color.startsWith('#')).toBe(true);
    expect(color.length).toBe(7);
  });

  it('interpolates between mid and low for values between 0-50', () => {
    const color = interpolateColor(25);
    expect(color).not.toBe('#F59E0B');
    expect(color).not.toBe('#EF4444');
    expect(color.startsWith('#')).toBe(true);
  });
});

describe('formatStepCount', () => {
  it('formats numbers with thousands separators', () => {
    expect(formatStepCount(5120)).toBe('5,120');
    expect(formatStepCount(10000)).toBe('10,000');
    expect(formatStepCount(0)).toBe('0');
    expect(formatStepCount(245)).toBe('245');
  });
});

describe('formatVirtualAge', () => {
  it('returns "AGE: 0 MONTHS" for null born_at', () => {
    expect(formatVirtualAge(null)).toBe('AGE: 0 MONTHS');
  });

  it('calculates months from born_at timestamp', () => {
    const threeWeeksAgo = new Date(Date.now() - 3 * 7 * 24 * 60 * 60 * 1000).toISOString();
    expect(formatVirtualAge(threeWeeksAgo)).toBe('AGE: 3 MONTHS');
  });

  it('returns 0 months for recent birth', () => {
    const now = new Date().toISOString();
    expect(formatVirtualAge(now)).toBe('AGE: 0 MONTHS');
  });
});

describe('isActionDisabled', () => {
  it('disables all actions when locked', () => {
    expect(isActionDisabled('feed', 'idle', true)).toBe(true);
    expect(isActionDisabled('water', 'idle', true)).toBe(true);
    expect(isActionDisabled('walk', 'idle', true)).toBe(true);
    expect(isActionDisabled('clean', 'idle', true)).toBe(true);
  });

  it('disables all actions when pet is sick', () => {
    expect(isActionDisabled('feed', 'sick', false)).toBe(true);
    expect(isActionDisabled('water', 'sick', false)).toBe(true);
    expect(isActionDisabled('walk', 'sick', false)).toBe(true);
    expect(isActionDisabled('clean', 'sick', false)).toBe(true);
  });

  it('disables all actions when pet is sleeping', () => {
    expect(isActionDisabled('feed', 'sleeping', false)).toBe(true);
    expect(isActionDisabled('water', 'sleeping', false)).toBe(true);
    expect(isActionDisabled('walk', 'sleeping', false)).toBe(true);
  });

  it('enables walk when not locked, sick, or sleeping', () => {
    expect(isActionDisabled('walk', 'idle', false)).toBe(false);
    expect(isActionDisabled('walk', 'hungry', false)).toBe(false);
    expect(isActionDisabled('walk', 'playing', false)).toBe(false);
  });

  it('enables feed/water/clean when not locked or sick', () => {
    expect(isActionDisabled('feed', 'idle', false)).toBe(false);
    expect(isActionDisabled('water', 'hungry', false)).toBe(false);
    expect(isActionDisabled('clean', 'playing', false)).toBe(false);
  });
});
