/**
 * Tests for metric color interpolation utility functions.
 */

import {
  getMetricColor,
  interpolateColor,
  formatStepCount,
  isActionDisabled,
} from '@/utils/metrics';
import { i18n } from '@/i18n';

describe('getMetricColor', () => {
  it('returns green for high levels (>=60)', () => {
    expect(getMetricColor(100)).toBe('#7FE0B4');
    expect(getMetricColor(80)).toBe('#7FE0B4');
    expect(getMetricColor(60)).toBe('#7FE0B4');
  });

  it('returns amber for medium levels (30-59)', () => {
    expect(getMetricColor(50)).toBe('#FFD15C');
    expect(getMetricColor(40)).toBe('#FFD15C');
    expect(getMetricColor(30)).toBe('#FFD15C');
  });

  it('returns red for low levels (<30)', () => {
    expect(getMetricColor(20)).toBe('#FF7A6B');
    expect(getMetricColor(10)).toBe('#FF7A6B');
    expect(getMetricColor(0)).toBe('#FF7A6B');
  });
});

describe('interpolateColor', () => {
  it('returns high color at 100%', () => {
    const color = interpolateColor(100);
    expect(color.toLowerCase()).toBe('#7fe0b4');
  });

  it('returns mid color at 50%', () => {
    const color = interpolateColor(50);
    expect(color.toLowerCase()).toBe('#ffd15c');
  });

  it('returns low color at 0%', () => {
    const color = interpolateColor(0);
    expect(color.toLowerCase()).toBe('#ff7a6b');
  });

  it('interpolates between high and mid for values between 50-100', () => {
    const color = interpolateColor(75);
    // Should be between green (#7FE0B4) and amber (#FFD15C)
    expect(color).not.toBe('#7FE0B4');
    expect(color).not.toBe('#FFD15C');
    // Should start with #
    expect(color.startsWith('#')).toBe(true);
    expect(color.length).toBe(7);
  });

  it('interpolates between mid and low for values between 0-50', () => {
    const color = interpolateColor(25);
    expect(color).not.toBe('#FFD15C');
    expect(color).not.toBe('#FF7A6B');
    expect(color.startsWith('#')).toBe(true);
  });
});

describe('formatStepCount', () => {
  afterEach(async () => {
    await i18n.changeLanguage('sl');
  });

  it('formats numbers with the Slovenian grouping on a Slovenian device', () => {
    expect(formatStepCount(10000)).toBe('10.000');
    expect(formatStepCount(0)).toBe('0');
    expect(formatStepCount(245)).toBe('245');
  });

  it('formats numbers with English thousands separators in English', async () => {
    await i18n.changeLanguage('en');
    expect(formatStepCount(5120)).toBe('5,120');
    expect(formatStepCount(10000)).toBe('10,000');
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
