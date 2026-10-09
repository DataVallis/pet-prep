import * as Clipboard from 'expo-clipboard';

import {
  extractPin,
  isPinClipboardAvailable,
  readPinFromClipboard,
  setClipboardProbeForTests,
} from '@/modules/pairing/pinClipboard';

const getString = Clipboard.getStringAsync as jest.Mock;
const hasString = Clipboard.hasStringAsync as jest.Mock;

describe('extractPin', () => {
  it.each([
    ['123456', '123456'],
    ['123 456', '123456'],
    ['12-34-56', '123456'],
    [' 123456\n', '123456'],
    ['Koda: 734 912', '734912'],
    ['Koda 734 912 velja do 15:30', '734912'],
    ['Koda: 734-912 (15 min)', '734912'],
  ])('%j → %s', (text, pin) => {
    expect(extractPin(text)).toBe(pin);
  });

  it.each(['12345', '1234567', 'abc', '', '   ', '12 34 5', 'Koda 734 912 ali 123 456', '734 912 345 do 15:30'])('%j → null', (text) => {
    expect(extractPin(text)).toBeNull();
  });
});

describe('readPinFromClipboard', () => {
  beforeEach(() => {
    getString.mockReset();
    getString.mockResolvedValue('');
    hasString.mockReset();
    hasString.mockResolvedValue(true);
    setClipboardProbeForTests(() => true);
  });

  afterAll(() => setClipboardProbeForTests(null));

  it('returns the code when the clipboard holds exactly 6 digits', async () => {
    getString.mockResolvedValueOnce('734 912');
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'pin', pin: '734912' });
  });

  it('no_pin for an empty clipboard (also what iOS returns after a denied paste prompt)', async () => {
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'no_pin' });
  });

  it('nothing on the clipboard: the text is not read at all', async () => {
    hasString.mockResolvedValueOnce(false);
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'no_pin' });
    expect(getString).not.toHaveBeenCalled();
  });

  it('no_pin for the wrong number of digits', async () => {
    getString.mockResolvedValueOnce('1234567');
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'no_pin' });
  });

  it('a failing native call never throws', async () => {
    getString.mockRejectedValueOnce(new Error('boom'));
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'no_pin' });
  });

  it('without the native module: unavailable, the clipboard is never touched', async () => {
    setClipboardProbeForTests(() => false);
    expect(isPinClipboardAvailable()).toBe(false);
    await expect(readPinFromClipboard()).resolves.toEqual({ kind: 'unavailable' });
    expect(getString).not.toHaveBeenCalled();
  });

  it('a probe that throws counts as unavailable', () => {
    setClipboardProbeForTests(() => {
      throw new Error('no expo');
    });
    expect(isPinClipboardAvailable()).toBe(false);
  });
});
