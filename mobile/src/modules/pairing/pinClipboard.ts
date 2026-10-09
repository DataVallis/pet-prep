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

/** Runs of digits joined by single spaces / dashes ("734 912", "15", "12-34-56-78"). */
const DIGIT_RUN = /[0-9]+(?:[ -][0-9]+)*/g;
/** A run that is exactly one PIN: "734912", "734 912", "734-912". */
const PIN_SHAPE = /^[0-9]{3}[ -]?[0-9]{3}$/;

/**
 * The PIN in a piece of copied text, or null.
 * 1. All digits joined, when exactly `PIN_LENGTH` ("123 456", "12-34-56", "Koda: 734 912").
 * 2. Otherwise exactly one stand-alone 6-digit group ("Koda 734 912 velja do 15:30");
 *    two or more candidates are ambiguous → null.
 */
export function extractPin(text: string): string | null {
  const digits = text.replace(/[^0-9]/g, '');
  if (digits.length === PIN_LENGTH) return digits;
  // Whole runs, so "734 912 345" (9 digits) is not mistaken for a PIN.
  const groups = (text.match(DIGIT_RUN) ?? []).filter((run) => PIN_SHAPE.test(run));
  return groups.length === 1 ? groups[0].replace(/[^0-9]/g, '') : null;
}

export type ClipboardPinResult =
  | { kind: 'pin'; pin: string }
  /** Empty clipboard, no 6-digit code, or (iOS 16+) the child denied the paste prompt. */
  | { kind: 'no_pin' }
  /** No clipboard module in this binary. */
  | { kind: 'unavailable' };

type ClipboardApi = Pick<typeof ClipboardModule, 'getStringAsync' | 'hasStringAsync'>;
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
    // No text on the clipboard → don't read it at all (no iOS paste prompt for nothing).
    if (!(await clipboard.hasStringAsync())) return { kind: 'no_pin' };
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
