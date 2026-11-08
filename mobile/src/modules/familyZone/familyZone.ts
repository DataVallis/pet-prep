/**
 * Family time zone setting (David 2026-11-08, »kar B«) — pure helpers.
 *
 * Every wall-clock rule (quiet hours, sleep, meal windows, midnight, step day) runs in the
 * family's IANA zone (`families.timezone`). A parent can change it (`PUT /api/parent/settings`,
 * at most once per 24 h), and the parent app suggests it when the PHONE's current UTC offset
 * differs from the family zone's current offset (travelling). Offsets, not names, decide:
 * Europe/Ljubljana and Europe/Vienna are the same clock, so no banner.
 *
 * Nothing here touches the network or storage; UI lives in `components/parent/FamilyTimezoneCard`
 * and `TimezoneSuggestionBanner`, dismissals in `zoneSuggestionPrefs.ts`.
 */

import { ApiError } from '@/api/client';
import type { Language } from '@/i18n/language';

/** Slovenian names of the zones' cities people are likely to pick (others: the IANA city). */
const CITY_SL: Readonly<Record<string, string>> = {
  'Europe/Ljubljana': 'Ljubljana',
  'Europe/Lisbon': 'Lizbona',
  'Europe/London': 'London',
  'Europe/Dublin': 'Dublin',
  'Europe/Paris': 'Pariz',
  'Europe/Madrid': 'Madrid',
  'Europe/Rome': 'Rim',
  'Europe/Vienna': 'Dunaj',
  'Europe/Berlin': 'Berlin',
  'Europe/Zurich': 'Zürich',
  'Europe/Prague': 'Praga',
  'Europe/Budapest': 'Budimpešta',
  'Europe/Zagreb': 'Zagreb',
  'Europe/Belgrade': 'Beograd',
  'Europe/Sarajevo': 'Sarajevo',
  'Europe/Skopje': 'Skopje',
  'Europe/Warsaw': 'Varšava',
  'Europe/Athens': 'Atene',
  'Europe/Istanbul': 'Carigrad',
  'Europe/Moscow': 'Moskva',
  'Europe/Kyiv': 'Kijev',
  'Europe/Brussels': 'Bruselj',
  'Europe/Amsterdam': 'Amsterdam',
  'Europe/Copenhagen': 'København',
  'Europe/Stockholm': 'Stockholm',
  'Europe/Oslo': 'Oslo',
  'Europe/Helsinki': 'Helsinki',
  'Atlantic/Canary': 'Kanarski otoki',
  'America/New_York': 'New York',
  'America/Chicago': 'Chicago',
  'America/Denver': 'Denver',
  'America/Los_Angeles': 'Los Angeles',
  'America/Toronto': 'Toronto',
  'America/Mexico_City': 'Ciudad de México',
  'America/Sao_Paulo': 'São Paulo',
  'Asia/Dubai': 'Dubaj',
  'Asia/Tokyo': 'Tokio',
  'Asia/Shanghai': 'Šanghaj',
  'Asia/Singapore': 'Singapur',
  'Asia/Bangkok': 'Bangkok',
  'Asia/Kolkata': 'Kalkuta',
  'Australia/Sydney': 'Sydney',
  'Pacific/Auckland': 'Auckland',
};

/**
 * Used when the device's Intl can't list zones (`Intl.supportedValuesOf` missing): the zones
 * above plus a spread over every offset. The phone's own zone is always offered first anyway.
 */
const FALLBACK_ZONES: readonly string[] = [
  ...Object.keys(CITY_SL),
  'Atlantic/Azores',
  'Atlantic/Reykjavik',
  'Africa/Casablanca',
  'Africa/Cairo',
  'Africa/Johannesburg',
  'Africa/Nairobi',
  'Africa/Lagos',
  'America/Anchorage',
  'America/Halifax',
  'America/Bogota',
  'America/Lima',
  'America/Argentina/Buenos_Aires',
  'America/Santiago',
  'America/Vancouver',
  'America/Phoenix',
  'Pacific/Honolulu',
  'Asia/Jerusalem',
  'Asia/Tehran',
  'Asia/Karachi',
  'Asia/Kathmandu',
  'Asia/Dhaka',
  'Asia/Jakarta',
  'Asia/Hong_Kong',
  'Asia/Seoul',
  'Asia/Manila',
  'Australia/Perth',
  'Australia/Adelaide',
  'Australia/Brisbane',
];

const formatters = new Map<string, Intl.DateTimeFormat | null>();

function formatterFor(zone: string): Intl.DateTimeFormat | null {
  if (formatters.has(zone)) return formatters.get(zone) ?? null;
  let formatter: Intl.DateTimeFormat | null;
  try {
    formatter = new Intl.DateTimeFormat('en-GB', {
      timeZone: zone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    });
  } catch {
    formatter = null;
  }
  formatters.set(zone, formatter);
  return formatter;
}

/** Minutes east of UTC of `zone` at `atMs` (Ljubljana in winter → 60), null = unknown zone. */
export function zoneOffsetMinutes(zone: string, atMs: number): number | null {
  const formatter = formatterFor(zone);
  if (!formatter) return null;
  try {
    const parts = formatter.formatToParts(new Date(atMs));
    const get = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value);
    const hour = get('hour') === 24 ? 0 : get('hour');
    const wall = Date.UTC(get('year'), get('month') - 1, get('day'), hour, get('minute'), get('second'));
    if (Number.isNaN(wall)) return null;
    const seconds = Math.floor(atMs / 1000) * 1000;
    return Math.round((wall - seconds) / 60000);
  } catch {
    return null;
  }
}

/** "UTC", "UTC+1", "UTC−5", "UTC+5:30" (true minus sign). */
export function formatUtcOffset(minutes: number): string {
  if (minutes === 0) return 'UTC';
  const sign = minutes > 0 ? '+' : '−';
  const abs = Math.abs(minutes);
  const h = Math.floor(abs / 60);
  const m = abs % 60;
  return `UTC${sign}${h}${m === 0 ? '' : `:${String(m).padStart(2, '0')}`}`;
}

/** Friendly city of a zone: Slovenian name when known, else the IANA city ("New_York" → "New York"). */
export function zoneCity(zone: string, language: Language): string {
  if (language === 'sl' && CITY_SL[zone]) return CITY_SL[zone];
  const last = zone.split('/').pop() ?? zone;
  return last.replace(/_/g, ' ');
}

/** "Lizbona · UTC" / "Ljubljana · UTC+1" (unknown offset → the city only). */
export function zoneLabel(zone: string, language: Language, atMs: number): string {
  const offset = zoneOffsetMinutes(zone, atMs);
  const city = zoneCity(zone, language);
  return offset === null ? city : `${city} · ${formatUtcOffset(offset)}`;
}

/**
 * True when the phone's clock now differs from the family's clock now (different current UTC
 * offsets). Unknown zones never differ (no banner on a broken Intl).
 */
export function phoneDiffersFromFamily(familyZone: string | null, phoneZone: string | null, atMs: number): boolean {
  if (!familyZone || !phoneZone || familyZone === phoneZone) return false;
  const family = zoneOffsetMinutes(familyZone, atMs);
  const phone = zoneOffsetMinutes(phoneZone, atMs);
  return family !== null && phone !== null && family !== phone;
}

/**
 * The dashboard banner (parent only): shown while the phone's offset differs from the family's,
 * unless it was dismissed for THIS phone zone — it comes back only in yet another zone.
 */
export function suggestionVisible(input: {
  familyZone: string | null;
  phoneZone: string | null;
  dismissedZone: string | null;
  atMs: number;
}): boolean {
  if (input.phoneZone !== null && input.dismissedZone === input.phoneZone) return false;
  return phoneDiffersFromFamily(input.familyZone, input.phoneZone, input.atMs);
}

type SupportedValuesOf = (key: 'timeZone') => string[];

/** Every IANA zone the device knows (Area/City form), or the fallback list. */
export function allZones(): string[] {
  const supported = (Intl as unknown as { supportedValuesOf?: SupportedValuesOf }).supportedValuesOf;
  let zones: string[] = [];
  if (typeof supported === 'function') {
    try {
      zones = supported('timeZone');
    } catch {
      zones = [];
    }
  }
  const list = zones.length > 0 ? zones : [...FALLBACK_ZONES];
  return [...new Set(list.filter((z) => /^[A-Z][A-Za-z_]+\/[A-Za-z_/-]+$/.test(z)))].sort();
}

/** Lower-case, no diacritics — "Budimpešta" matches "budimpesta". */
function fold(text: string): string {
  return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/**
 * Picker rows: the phone's zone first (when known), then every zone matching `query` by its
 * Slovenian / English city or IANA id. No duplicates.
 */
export function zoneChoices(input: { phoneZone: string | null; query: string; zones: readonly string[]; language: Language }): string[] {
  const q = fold(input.query.trim());
  const matches = (zone: string): boolean =>
    q === '' || [zone, zoneCity(zone, 'sl'), zoneCity(zone, 'en')].some((name) => fold(name).includes(q));
  const rows: string[] = [];
  if (input.phoneZone && matches(input.phoneZone)) rows.push(input.phoneZone);
  for (const zone of input.zones) {
    if (zone !== input.phoneZone && matches(zone)) rows.push(zone);
  }
  return rows;
}

export type TimezoneChangeError =
  | { kind: 'tooSoon'; nextAllowedAt: string | null }
  | { kind: 'invalid' }
  | { kind: 'offline' }
  | { kind: 'failed' };

/** Map a failed `PUT /api/parent/settings` to what the sheet says. */
export function readTimezoneChangeError(error: unknown): TimezoneChangeError {
  if (!(error instanceof ApiError)) return { kind: 'offline' };
  const data = error.data;
  const body = typeof data === 'object' && data !== null ? (data as Record<string, unknown>) : {};
  if (error.status === 422 && body.reason === 'timezone_change_too_soon') {
    return { kind: 'tooSoon', nextAllowedAt: typeof body.next_allowed_at === 'string' ? body.next_allowed_at : null };
  }
  if (error.status === 422) return { kind: 'invalid' };
  return { kind: 'failed' };
}

let phoneZoneOverride: string | null | undefined;

/** The phone's IANA zone right now (null = unknown). */
export function currentPhoneZone(): string | null {
  if (phoneZoneOverride !== undefined) return phoneZoneOverride;
  try {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    return typeof zone === 'string' && zone.length > 0 ? zone : null;
  } catch {
    return null;
  }
}

/** Test hook: pretend the phone is in `zone` (undefined = the real device zone). */
export function setPhoneZoneForTests(zone: string | null | undefined): void {
  phoneZoneOverride = zone;
}
