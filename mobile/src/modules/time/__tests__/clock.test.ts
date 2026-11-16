import { i18n } from '@/i18n';
import {
  applyTimeFormat,
  clockText,
  deviceUses12h,
  formatClock,
  getTimeFormat,
  isHour12,
  loadSavedTimeFormat,
  resolveHour12,
  saveTimeFormat,
  setDeviceHour12ForTests,
  shortClockText,
  windowLabel,
} from '@/modules/time/clock';
import { clockText as familyClockText, familyClock } from '@/modules/childPet/familyTime';

/*
 * The ONE clock formatter (David 2026-10-21 »7:00«; 2026-11-16 12 / 24 h, »ker gremo
 * globalno«). Jest's device: Slovenian, 24 h phone (jest.afterEnv.ts).
 */

describe('formatClock / clockText', () => {
  it('24 h stays "7:00" / "17:05" (no leading zero, minutes two digits)', () => {
    expect(formatClock(7, 0, false)).toBe('7:00');
    expect(formatClock(17, 5, false)).toBe('17:05');
    expect(formatClock(0, 0, false)).toBe('0:00');
    expect(clockText('07:00', false)).toBe('7:00');
    expect(clockText('later', false)).toBe('later');
  });

  it('12 h: noon 12:00 PM, midnight 12:00 AM, per language suffix', async () => {
    await i18n.changeLanguage('en');
    try {
      expect(formatClock(0, 0, true)).toBe('12:00 AM');
      expect(formatClock(12, 0, true)).toBe('12:00 PM');
      expect(formatClock(11, 59, true)).toBe('11:59 AM');
      expect(formatClock(23, 45, true)).toBe('11:45 PM');
      expect(clockText('17:30', true)).toBe('5:30 PM');
    } finally {
      await i18n.changeLanguage('sl');
    }
    expect(formatClock(9, 15, true)).toBe('9:15 dop.');
    expect(formatClock(13, 0, true)).toBe('1:00 pop.');
  });

  it('short chips and windows: "7–9" / "7–9 dop." / "11 dop.–1 pop."', () => {
    expect(shortClockText('07:30', false)).toBe('7:30');
    expect(shortClockText('07:00', true)).toBe('7 dop.');
    expect(windowLabel('06:00', '10:00', false)).toBe('6–10');
    expect(windowLabel('06:00', '10:00', true)).toBe('6–10 dop.');
    expect(windowLabel('11:00', '13:00', true)).toBe('11 dop.–1 pop.');
    expect(windowLabel('17:00', '21:30', true)).toBe('5–9:30 pop.');
  });
});

describe('the choice: auto follows the phone, 24h / 12h override it', () => {
  it('resolveHour12', () => {
    expect(resolveHour12('auto', true)).toBe(true);
    expect(resolveHour12('auto', false)).toBe(false);
    expect(resolveHour12('24h', true)).toBe(false);
    expect(resolveHour12('12h', false)).toBe(true);
  });

  it('auto on a 12 h phone → "5:00 pop."; 24h override → "17:00"; every helper follows', () => {
    setDeviceHour12ForTests(true);
    expect(getTimeFormat()).toBe('auto');
    expect(isHour12()).toBe(true);
    expect(clockText('17:00')).toBe('5:00 pop.');
    expect(familyClockText('17:00')).toBe('5:00 pop.');
    expect(familyClock('2026-11-16T16:00:00Z', 'Europe/Ljubljana')).toBe('5:00 pop.');

    applyTimeFormat('24h');
    expect(clockText('17:00')).toBe('17:00');
    expect(familyClock('2026-11-16T16:00:00Z', 'Europe/Ljubljana')).toBe('17:00');
  });

  it('phone detection without the calendar API falls back to the locale (sl-SI → 24 h)', () => {
    setDeviceHour12ForTests(null);
    expect(deviceUses12h()).toBe(false);
  });

  it('remembers the choice on the device and loads it at start', async () => {
    const memory = new Map<string, string>();
    const store = {
      getItemAsync: jest.fn(async (key: string) => memory.get(key) ?? null),
      setItemAsync: jest.fn(async (key: string, value: string) => {
        memory.set(key, value);
      }),
    };
    await saveTimeFormat('12h', store);
    applyTimeFormat('auto');
    await expect(loadSavedTimeFormat(store)).resolves.toBe('12h');
    expect(getTimeFormat()).toBe('12h');

    memory.set('petprep.timeFormat', 'garbage');
    applyTimeFormat('auto');
    await expect(loadSavedTimeFormat(store)).resolves.toBe('auto');
  });
});
