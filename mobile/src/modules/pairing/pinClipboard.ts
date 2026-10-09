/**
 * Paste the child's PIN from the clipboard (M2-02 fix, David 2026-10-09).
 *
 * The child PIN screen is an on-screen keypad without a `TextInput`, so the system
 * "Paste" menu never appears. "Prilepi kodo" reads the clipboard text once, keeps only
 * the digits and — when exactly `PIN_LENGTH` remain — hands the code to the same submit
 * path as typing the 6th digit.
 *
 * `expo-clipboard` calls `requireNativeModule` when its JS is evaluated, so a binary built
 * before this module was added (or any runtime without it) would crash on a static
 * import. It is loaded lazily after probing for the native module; without it the paste
 * button is hidden and every call here is a safe no-op.
 *
 * Privacy: the clipboard text never leaves this function — it is not stored, logged or
 * shown; only the extracted 6-digit code is returned.
 */

import { requireOptionalNativeModule } from 'expo';
import type * as ClipboardModule from 'expo-clipboard';

import { PIN_LENGTH } from './pinLogin';

/** Digits only; a PIN only when exactly `PIN_LENGTH` digits remain ("123 456", "12-34-56"). */
export function extractPin(text: string): string | null {
  const digits = text.replace(/[^0-9]/g, '');
  return digits.length === PIN_LENGTH ? digits : null;
}

export type ClipboardPinResult =
  | { kind: 'pin'; pin: string }
  /** Empty clipboard, no 6-digit code, or (iOS 16+) the child denied the paste prompt. */
  | { kind: 'no_pin' }
  /** No clipboard module in this binary. */
  | { kind: 'unavailable' };

type ClipboardApi = Pick<typeof ClipboardModule, 'getStringAsync'>;
type NativeProbe = () => boolean;

const defaultProbe: NativeProbe = () => requireOptionalNativeModule('ExpoClipboard') != null;
let probe: NativeProbe = defaultProbe;
let cached: ClipboardApi | null | undefined;

/** The clipboard API, or null when this binary doesn't have it. Never throws. */
function loadClipboard(): ClipboardApi | null {
  if (cached !== undefined) return cached;
  try {
    cached = probe() ? (require('expo-clipboard') as typeof ClipboardModule) : null;
  } catch {
    cached = null;
  }
  return cached;
}

/** Whether the paste button can work at all (false in a binary without expo-clipboard). */
export function isPinClipboardAvailable(): boolean {
  return loadClipboard() !== null;
}

/** Reads the clipboard once and extracts a PIN. Never throws. */
export async function readPinFromClipboard(): Promise<ClipboardPinResult> {
  const clipboard = loadClipboard();
  if (!clipboard) return { kind: 'unavailable' };
  try {
    const pin = extractPin(await clipboard.getStringAsync());
    return pin ? { kind: 'pin', pin } : { kind: 'no_pin' };
  } catch {
    return { kind: 'no_pin' };
  }
}

/** Tests: replace the native-module probe (and forget the cached module). */
export function setClipboardProbeForTests(next: NativeProbe | null): void {
  probe = next ?? defaultProbe;
  cached = undefined;
}
