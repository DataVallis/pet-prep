/**
 * THE clock formatting of the app (David 2026-10-21 »7:00«; 2026-11-16 12 / 24 h: »da se
 * lahko nastavi 24-urni ali pa 12-urni format, ker gremo globalno«).
 *
 * - The person's choice (`TimeFormat`): `auto` (follow the phone — default), `24h`, `12h`.
 *   Stored on the server (`users.time_format`, PUT /api/user/time-format — push texts use it)
 *   and on this device (SecureStore, read before the first frame); `GET /api/user` wins.
 * - `auto` = the phone's own 12 / 24 h setting (`expo-localization` `uses24hourClock`, else
 *   the device locale through Intl, else 24 h).
 * - 24 h: "7:00", "17:00" (no leading zero, minutes always two digits — unchanged).
 *   12 h: "7:00 AM", "5:00 PM", noon "12:00 PM", midnight "12:00 AM"; the suffix comes from
 *   `common:clock.am|pm` (EN AM / PM, SL dop. / pop.). Formatted here, never with
 *   `Intl` / `toLocaleTimeString` (engines differ, e.g. U+202F before "PM") — the server's
 *   `ClockText::to12h` writes push texts exactly the same way.
 *
 * Every clock time shown to a person goes through `clockText()` / `formatClock()` /
 * `windowLabel()`; API values stay "HH:MM" / ISO data. Components re-render on a change
 * through `useTimeFormat()` (AppNavigator subscribes for the whole tree).
 */

import { useSyncExternalStore } from 'react';
import * as Localization from 'expo-localization';
import * as SecureStore from 'expo-secure-store';

import { t } from '@/i18n';

export type TimeFormat = 'auto' | '24h' | '12h';

export const TIME_FORMATS: readonly TimeFormat[] = ['auto', '24h', '12h'];

export const TIME_FORMAT_STORE_KEY = 'petprep.timeFormat';

export function isTimeFormat(value: unknown): value is TimeFormat {
  return value === 'auto' || value === '24h' || value === '12h';
}

// ── Preference (module store) ────────────────────────────────────

let preference: TimeFormat = 'auto';
let deviceOverride: boolean | null = null;
const listeners = new Set<() => void>();

function emit(): void {
  listeners.forEach((listener) => listener());
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** The person's current choice (`auto` until known). */
export function getTimeFormat(): TimeFormat {
  return preference;
}

/** Apply a choice in memory (from the server, the device store or the settings card). */
export function applyTimeFormat(format: TimeFormat): void {
  if (format === preference) return;
  preference = format;
  emit();
}

/** Re-render on a change of the choice; returns the current choice. */
export function useTimeFormat(): TimeFormat {
  return useSyncExternalStore(subscribe, getTimeFormat, getTimeFormat);
}

type FormatStore = Pick<typeof SecureStore, 'getItemAsync' | 'setItemAsync'>;

/** The choice saved on this device (call once at startup); a failing store keeps `auto`. */
export async function loadSavedTimeFormat(store: FormatStore = SecureStore): Promise<TimeFormat> {
  try {
    const saved = await store.getItemAsync(TIME_FORMAT_STORE_KEY);
    if (isTimeFormat(saved)) applyTimeFormat(saved);
  } catch {
    // keep auto
  }
  return preference;
}

/** Apply and remember the choice on this device (the server is told by the caller). */
export async function saveTimeFormat(format: TimeFormat, store: FormatStore = SecureStore): Promise<void> {
  applyTimeFormat(format);
  try {
    await store.setItemAsync(TIME_FORMAT_STORE_KEY, format);
  } catch {
    // applies for this session
  }
}

// ── The phone's own setting ─────────────────────────────────────

/** Tests only: pretend the phone uses the 12 h (true) / 24 h (false) clock; null = real detection. */
export function setDeviceHour12ForTests(value: boolean | null): void {
  deviceOverride = value;
  emit();
}

/**
 * Does THIS phone use the 12 h clock? The system setting where the platform tells us
 * (`expo-localization` calendars), else the device locale's default (Intl), else false.
 * Sent with the push registration (`hour12`) so `auto` pushes match this phone.
 */
export function deviceUses12h(): boolean {
  if (deviceOverride !== null) return deviceOverride;
  try {
    const calendars = (Localization as { getCalendars?: () => { uses24hourClock?: boolean | null }[] }).getCalendars?.();
    const uses24 = calendars?.[0]?.uses24hourClock;
    if (typeof uses24 === 'boolean') return !uses24;
  } catch {
    // fall through
  }
  try {
    const tag = Localization.getLocales()[0]?.languageTag;
    if (tag) {
      const options = new Intl.DateTimeFormat(tag, { hour: 'numeric' }).resolvedOptions();
      if (typeof options.hour12 === 'boolean') return options.hour12;
    }
  } catch {
    // fall through
  }
  return false;
}

/** 12 h for `format` on a phone whose own setting is `phone12h`. */
export function resolveHour12(format: TimeFormat = preference, phone12h: boolean = deviceUses12h()): boolean {
  if (format === '12h') return true;
  if (format === '24h') return false;
  return phone12h;
}

/** True when clock times are shown in the 12 h format right now. */
export function isHour12(): boolean {
  return resolveHour12();
}

// ── Formatting ───────────────────────────────────────────────────

function suffix(hour: number): string {
  return hour < 12 ? t('common:clock.am') : t('common:clock.pm');
}

/** "17:05" / "5:05 PM" for a wall clock hour (0–23) and minute. */
export function formatClock(hour: number, minute: number, hour12: boolean = isHour12()): string {
  const mm = String(minute).padStart(2, '0');
  if (!hour12) return `${hour}:${mm}`;
  const h12 = hour % 12 === 0 ? 12 : hour % 12;
  return `${h12}:${mm} ${suffix(hour)}`;
}

const HHMM = /^(\d{1,2}):(\d{2})$/;

/** "HH:MM" → [hour, minute], or null when it isn't a wall clock. */
export function parseClock(hhmm: string | null | undefined): [number, number] | null {
  const match = HHMM.exec(hhmm ?? '');
  if (!match) return null;
  const hour = Number(match[1]);
  const minute = Number(match[2]);
  return hour <= 23 && minute <= 59 ? [hour, minute] : null;
}

/**
 * THE clock text for a wall clock "HH:MM" ("07:00" → "7:00" / "7:00 AM"); anything else
 * is returned as is.
 */
export function clockText(hhmm: string, hour12: boolean = isHour12()): string {
  const parsed = parseClock(hhmm);
  return parsed ? formatClock(parsed[0], parsed[1], hour12) : hhmm;
}

/** Short form for compact chips: "7" / "7:30" (24 h), "7 AM" / "7:30 AM" (12 h). */
export function shortClockText(hhmm: string, hour12: boolean = isHour12()): string {
  const parsed = parseClock(hhmm);
  if (!parsed) return hhmm;
  const [hour, minute] = parsed;
  const mm = minute === 0 ? '' : `:${String(minute).padStart(2, '0')}`;
  if (!hour12) return `${hour}${mm}`;
  const h12 = hour % 12 === 0 ? 12 : hour % 12;
  return `${h12}${mm} ${suffix(hour)}`;
}

/**
 * A window "07:00"–"09:00" as a compact label: "7–9" (24 h); 12 h "7–9 AM" when both ends
 * share the suffix, else "11 AM–1 PM".
 */
export function windowLabel(start: string, end: string, hour12: boolean = isHour12()): string {
  if (!hour12) return `${shortClockText(start, false)}–${shortClockText(end, false)}`;
  const a = parseClock(start);
  const b = parseClock(end);
  if (!a || !b) return `${start}–${end}`;
  if ((a[0] < 12) === (b[0] < 12)) {
    const bare = (hhmm: string) => shortClockText(hhmm, true).replace(/\s\S+$/, '');
    return `${bare(start)}–${shortClockText(end, true)}`;
  }
  return `${shortClockText(start, true)}–${shortClockText(end, true)}`;
}
